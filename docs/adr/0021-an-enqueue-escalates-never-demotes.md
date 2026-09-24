# An enqueue escalates an already-queued permalink, and never demotes it

Decided while fixing #119.

`RevalidateQueue::add_item( $permalink, $priority )` deduplicates by permalink:
a path already waiting is not queued a second time, because the entry it already
has *is* the revalidation and a second row would only rebuild the same path
twice. That much is right, and [ADR 0004](0004-at-most-once-revalidation.md)
depends on it.

What the dedup branch also did was throw the `$priority` away. It counted rows,
found one, set its result to `true` and returned without touching the entry. So a
permalink queued at the default 10 and re-submitted at 1 stayed at 10, and the
caller was told `true` — over REST, a `200` with `success: true`.

Priority is not decoration. `get_next_item()` selects `ORDER BY priority ASC,
id ASC`, so a caller re-submitting a path at a lower number is asking it to jump
the queue. The plugin answered yes and did nothing. This is the family #49, #50
and #93 are in — success reported for something that did not happen — but a step
worse than the others: those are imprecise about what was achieved, while this
one leaves the queue in a state the caller was told it was not in. The realistic
case is an editor saving a post stuck behind a bulk revalidate-all, or an
external system escalating a path it needs fresh now.

## The decision

**An enqueue escalates, never demotes.** When the permalink is already queued,
the entry's priority becomes the more urgent of the two — numerically
`LEAST( existing, requested )`. A more urgent re-submission promotes the entry;
an equal or less urgent one leaves it exactly as it is.

The minimum is the rule a caller most plausibly expects, and it is the only rule
under which a second, less urgent caller cannot slow down work something else
deemed urgent. An editor saving a post an external system has already escalated
is not asking for that path to be revalidated *later*; they are asking for it to
be revalidated, which it already will be, sooner.

**`0` is a priority, not an absence of one.** The read that used to be
`SELECT COUNT(*)` is now `SELECT priority`, and the branch turns on
`null === $queued_priority` rather than on falsiness. Written for truthiness it
would treat an entry queued at `0` — the most urgent priority there is — as
unqueued and insert a duplicate row. That is the same falsy-`0` mistake #110
fixed one layer up, at the single REST route, where an explicit `priority=0`
became the default 10.

**The dedup branch reports success unless the write genuinely errored.** It does
not return the affected-row count. MySQL reports **0 affected rows when the new
value equals the old**, and #93 reads any falsy `add_item()` return as
`success: false` — so the *idempotent* case, the second identical request a
retrying deploy hook sends, would report failure. A `false` from `$wpdb->query()`
is a different thing and must propagate: the caller asked for an escalation and
the database refused it. An `UPDATE` that matches no row is also not a failure —
another caller promoted the entry to at least this priority between the read and
the write, which is the state this one asked for.

**Promotion keeps the entry's `id`.** The tiebreaker after priority is arrival
order, and a promoted entry genuinely did arrive before the others in its new
band, so it drains ahead of them and behind whatever was already waiting there.

**Nothing new is reported to the caller.** Under this rule the requested priority
is always honoured, or the entry was already more urgent, so there is nothing to
disclose. `success: true` stays honest under
[ADR 0010](0010-the-public-api-reports-acceptance-not-delivery.md), and no field
announces a promotion. The operator-facing log line is not part of that contract
and is free to say so.

## Considered Options

**`INSERT … ON DUPLICATE KEY UPDATE priority = LEAST( priority, VALUES( priority ) )`.**
One statement replacing a read, a branch and a write, and on its own terms the
better statement. **This is the option this ADR exists to keep rejected**, because
a future reader will see three round-trips doing what one does and collapse them,
and that refactor is clean, obvious, well-motivated and silently wrong.

It works only where the `permalink` unique key exists, and that key is declared
on a `TEXT` column (#121). wp-env's MariaDB accepts it through its "long unique"
hash-index feature; standard MySQL does not support an unprefixed index on `TEXT`
at all, so on a large share of production installs the key is simply absent.
Where it is absent there is no duplicate for the statement to detect, and it
degrades to inserting a **second row for the same permalink** — silently, with
duplicate rows that drain twice, on installs nothing in this repo tests, while
every test here stays green. The explicit read-then-branch behaves identically
with or without the index, which is the whole reason it is three statements.

Revisit this only if #121 concludes the key is dependable everywhere.

> Revisited. #121 made the key dependable — it is now on a fixed-width hash of
> the permalink, which every engine can carry
> ([ADR 0029](0029-the-queue-dedups-on-a-hash-of-the-permalink.md)) — and the
> answer is still no, on the *other* ground above: the statement answers with an
> affected-row count, `0` when the new value equals the old, and #93 reads a
> falsy `add_item()` as failure. The read-then-branch also has somewhere to put
> the promotion's log line. Portability was the argument that has expired; it was
> not the only one.

**Return the affected-row count from the dedup branch.** Honest-looking, and it
turns the idempotent re-submission into a reported failure for the reason given
above. Rejected.

**Refuse to promote, and tell the caller the priority did not take.** Defensible:
dedup exists so a burst of edits does not queue a page ten times, and promotion
makes the dedup branch do a write it did not do before. Rejected because the
write is one indexed `UPDATE` on a path that is already doing a `SELECT`, and
because a caller told "queued, but not at the priority you asked for" has no
recourse the plugin offers — no way to reorder the entry, and no reason to accept
being refused something the queue can trivially do.

**Delete the entry and re-insert it at the new priority.** Would work, and moves
the entry to the back of its new priority band, behind work it predates. Worse,
it reintroduces a write that can fail and leave the queue holding nothing, on a
path whose entire premise is that the permalink was already safely queued.
Rejected.

**Leave the comparison to the read and write an unconditional `UPDATE`.** The
`WHERE` carries `AND priority > %d` as well, which duplicates the check the early
return already made. Kept deliberately: two callers escalating the same permalink
concurrently could otherwise interleave so that the less urgent of the two
priorities is written last. The early return exists only to skip the query and
the log line, not to be the guard.

## Consequences

**The dedup branch now writes.** It was a pure read before. The write is a single
`UPDATE` on the permalink, inside the transaction `add_item()` already opens, and
it runs only when the re-submission is actually an escalation.

**`add_item()`'s answers are unchanged in shape** — `WP_Error`, `1`, `true`,
`false` — and the meaning of `true` widens to "already queued, and now at least
as urgent as you asked". The README and the `nextjs_revalidate_purge_url()`
docblock name the promotion as the second write that can answer `false`.

**`get_next_item()` is untouched.** Its `priority ASC, id ASC` ordering is what
gives promotion its effect; it is not a coincidence this decision can rely on.

**Only the REST route exercises this today.** Of every `add_item()` caller, it is
the only one that supplies a real priority — post save, the permalink rebuild,
revalidate-all and the Redirection integration all take the default. The
scheduled purge passes a boolean, which is #63; once that is fixed, scheduled
purges reach this path too.

**The `SELECT`-then-`INSERT` race on a *new* permalink is unchanged.** Two
concurrent enqueues of a permalink nothing holds can still both read nothing and
both insert. That predates this decision and belongs to #121.

> Closed there. The unique key refuses the second insert, and the loser re-reads
> the winner's row and promotes through the branch this ADR added — so the race
> now ends in the state the caller asked for rather than in a duplicate entry.
> See [ADR 0029](0029-the-queue-dedups-on-a-hash-of-the-permalink.md).

**These tests need a real `$wpdb` and a real table**, so they live in the wp-env
integration suite ([ADR 0008](0008-two-testing-idioms.md)) and do not run in the
gate an unattended agent is judged by ([ADR 0006](0006-afk-harness-invariants.md)).
