# A drain holds a lease on one of the cap's slots, and the cap counts nothing

Decided while fixing #210, on `v1.x` only: v2 has no queue (#160), so `main` has
nothing to apply it to. Numbered past `main`'s ADRs rather than after `v1.x`'s
own, so no number names two decisions across the two branches.

> On `main` this is a copy of the ADR on `v1.x`, kept here so `main` holds the
> number as well: `tests/adr-numbers-test.php` sees only its own branch, so
> without it `main`'s next ADR would take 0043. Nothing on `main` implements it.

At most `MAX_NB_RUNNING_CRON` drains (four) run at once on a site. Until this
change the cap was a counter in the `nextjs_revalidate-running_queue` transient:
a drain read it as it started, refused to run at four, and wrote it back plus
one; it read it again as it finished and wrote it back minus one. In production
(1.6.9, `max_execution_time = 3600`, Object Cache Pro) it read **2** after a
queue of 170 had drained, with no drain running and no event scheduled, and it
cleared only when the transient expired. It drifts upward for four separate
reasons, and any one of them is enough:

- **A long drain decrements from a stale value.** It reads the counter through
  its own request's object cache, and after an hour that copy is the value the
  drain wrote at its start. Whichever drain finishes last writes its own start
  value minus one, whatever the others did since. The `cron` copy of #208 is the
  same trap, and a persistent object cache does not help: its per-request layer
  still answers from the old value.
- **The update is not atomic.** Two drains reading `n` together both write
  `n ± 1`.
- **A killed drain never decrements.** A fatal, a timeout or a recycled worker
  leaves its slot taken.
- **Every write refreshed the hour's expiry**, so while drains kept starting an
  inflated value never expired at all.

A counter at four turned every drain away until it expired; one below four but
inflated let fewer drains run than the cap allows.

## The decision

**Each drain holds a lease on one of the cap's slots.** A lease is one row in the
site's options, named `nextjs_revalidate-drain_lease-{slot}` for a slot from `0`
to `MAX_NB_RUNNING_CRON - 1`, holding the moment it expires. A drain starting
deletes every expired lease, then tries the slots in order with an
`INSERT IGNORE`, and takes the first one the insert lands in. Every slot held is
the cap: the drain is turned away, and re-arms the next tick as #208 made it.
A drain finishing deletes its own row, by name and value.

That answers each cause of the drift by removing what it acted on:

- **Nothing is counted up or down.** No drain writes a value another drain wrote,
  so there is nothing to read stale and nothing to lose in a race.
- **The claim is the cap, and it is atomic.** The options table's unique key on
  `option_name` lets exactly one insert into a slot succeed. Two drains starting
  together cannot both take the last slot.
- **A lease expires on its own,** a fixed time after its claim, and nothing
  extends it. A killed drain's slot is free once its lease has run out, and the
  next drain to start deletes the row.

**A lease expires a little after its drain's whole time budget**:
`max_execution_time` (a minute where there is none, as under WP-CLI) plus 120
seconds. A drain stops taking entries at 95% of its budget, and the entry it is
on can still take a purge's 60-second timeout to answer; the margin covers that
twice. It has to be longer than the drain can run — a lease that expires under a
running drain lets a fifth one in — and no longer than that needs, because it is
also how long a killed drain's slot stays taken.

**Leases are read and written in SQL, never through the option API.**
`get_option()` answers from the object cache, which is the staleness #208 and
this both came from. `add_option()` and `update_option()` insert-or-update, where
a claim has to be *refused* when the row is there. The rows are not autoloaded,
so claiming one never touches `alloptions`.

**A drain gives back its own lease and no other.** The release deletes on name
*and* value, so a drain that outlived its lease — a purge that hung past the
margin — finds its slot taken by another drain and leaves that lease alone. Two
leases on one slot never share a value: the second is claimed only after the
first has expired, and expires a whole budget later.

## Considered Options

**Read the counter fresh before each update** — drop the cached transient, the
way `forget_cached_cron()` drops `cron`. Cheap, and it fixes the stale decrement.
Rejected: it leaves the race, the killed drain and the refreshed expiry exactly
as they are, and each of those drifts the counter on its own.

**One lease per drain, keyed by the drain's id, with the cap a count of live
leases.** What #210 proposed, and nearly this. Rejected for the last step: a
drain counting three live leases and then inserting its own can be joined by a
second drain doing the same in between, and both run as the fifth. Keying the row
by slot instead makes the database's unique key the thing that refuses the fifth
drain, and costs nothing — a slot's row still belongs to exactly one drain.

**Renew the lease as the drain goes**, extending it after each entry, so it could
be minutes long instead of a whole budget. A killed drain would then free its slot
in minutes rather than up to an hour on a host with `max_execution_time = 3600`.
Not done here: it adds a write per entry and a decision about what a drain does
when its renewal fails, and a slot held for an hour by a dead drain leaves three
others draining, with turned-away ticks re-arming (#208). Worth revisiting if
killed drains turn out to be common.

**A table of its own for the leases.** Rejected: it would be a second table to
create, migrate and drop through #121's machinery for at most four rows. The
options table already has the unique key a claim needs.

## Consequences

**The old counter is never read**, so a site upgrading with it stuck at four
drains straight away — including a site no admin has visited since, whose
migration has not run. A drain runs on front-end traffic and a migration on an
admin request, so a fix that waited for the migration would have left those
sites blocked until the transient expired. `migrate_db()` still deletes the
counter on a site the ledger has below 1.7.1, so it does not sit in the options
until then. A site jumping here from before the ledger is backfilled past that
gate and keeps the counter until it expires within the hour — the under-shoot
ADR 0001 describes, and harmless here because nothing reads it.

**Drains still running the old code at the moment of an upgrade are not counted.**
For the length of one budget after a deploy, up to four old drains and four new
ones can run at once. They take entries through the same locking read, so this
costs concurrency against the front-end and nothing else.

**An uninstall deletes the leases** and the old counter, with the rest of the
site's options: left behind, they are rows nothing would read again.

**The log lines a drain writes as it starts and finishes are unchanged**, and the
number in them is now the count of live leases, read fresh. Nothing enforces the
cap through that count — the claim does — so it is for reading only.
