# A probe is not evidence: the failure window samples the queue, and nothing else

Decided while triaging #20, which asks for a button that revalidates one path on
demand and shows the operator what the front-end answered. `CONTEXT.md` calls
that a **probe**.

A probe is the cleanest evidence about the front-end this plugin can ever
obtain — a real request, to the real endpoint, with the real secret, made
deliberately rather than as a side effect of someone editing a post. The
obvious thing to do with it is to record it in the **failure window** alongside
every other attempt, and `FailureWindow::record()` even appears to invite it:
its only stated exclusion is the **refusal**, on the grounds that an
unconfigured site "was never attempted against the front-end, so it is no
evidence about the front-end's health".

**A probe outcome is nevertheless never recorded.** The window is not a record,
it is a *sample* — ten slots, with `DEGRADED_AT` failures among them defining
**degraded revalidation** and driving a notice on every admin screen. A sample
only means anything if what enters it is drawn from the population it claims to
describe, and that population is the queue's own traffic: revalidations that
happened because content changed, at whatever rate the site produces them. A
probe enters at a rate set by how worried the operator is.

## Considered Options

**Record a probe like any other attempt.** Rejected, and the deciding case is
not the noise but the direction of it. A front-end that is failing on deep paths
while `/` still rebuilds is a site whose operator sees the degraded notice,
presses the button a few times, watches it succeed, and watches the warning go
away — with the site still serving stale pages. **A diagnostic that can silence
its own alarm is worse than no diagnostic**, because it converts "I don't know"
into a confident and wrong "it's fine".

The mirror case costs less but is more common: a new site with a mistyped
secret, probed three times, trips the notice; the operator fixes the typo, and
the warning outlives the fault it diagnosed until eight real revalidations push
it out of the window — days, on a quiet site. The tool that found the problem is
then the reason the site still looks broken.

**Record failures and discard successes.** Kills the first case and leaves the
second. Rejected mainly because "we count the bad news and ignore the good" is a
rule nobody will be able to justify to the next reader, and an asymmetric sample
is not a sample of anything.

**Let a successful probe clear the window** — `FailureWindow::clear()` as an
explicit operator gesture meaning "I have fixed it, forget what you saw".
Rejected as out of scope for #20 rather than as wrong: it is the honest version
of the mute button the first option provides by accident, and if the stale-alarm
case turns out to bite in practice, this is where to look. It should be decided
on its own, not smuggled in behind a test button.

## Consequences

**The glossary's definition of failure stands as written.** A **failure** is a
revalidation that was *enqueued*, attempted, and did not succeed. A probe is
never enqueued, so it is never a failure, so it is never in the window — three
statements that all follow from one distinction the domain had already drawn.
`FailureWindow::record()`'s docblock gains a second exclusion beside the
refusal, and the principle behind both sharpens from "evidence about the
front-end's health" to "evidence from the queue's own traffic".

**The exclusion does not extend to the log file.** A probe *is* written there,
behind the same logs setting as everything else, with its own marker and its own
line shape — it has neither a queue id nor a priority to fill the drain's format
with. The reasoning above simply does not apply: nothing is computed from the
log, no threshold reads it, and it has no fixed length, so operator-initiated
traffic cannot bias a conclusion it never draws. Being able to produce a
timestamped diagnostic *on demand*, rather than waiting for a content edit to
trigger one, is most of what the probe is worth to whoever is supporting the
site.

**The probe is otherwise maximally faithful to the queue's delivery path**, and
that is deliberate: a probe whose answer can disagree with what a real
revalidation would have done is a probe that sends operators to chase phantoms.
It calls `Revalidate::purge()` rather than building its own request; it composes
`home_url( $path )` so that function's parameter keeps meaning one thing; it
reads *saved* settings rather than the unsaved form fields on screen, so it
answers "does this site revalidate right now" rather than "would these values
work"; and it keeps the 60-second timeout rather than shortening it for the
comfort of someone watching a spinner, because a shorter one would report
`unreachable` for a slow front-end the queue would have revalidated fine.

That last one has a cost worth naming: 60 seconds of outbound request inside a
`max_execution_time` commonly set to 30 will fatal rather than answer, and a
diagnostic whose worst case is *no output* fails exactly when it is needed.
`set_time_limit()` is raised best-effort before the request, which a hardened
host may refuse — and a host that refuses it has been quietly killing long cron
drains all along, so the probe surfacing that is closer to a feature than a bug.
ADR 0010 rejected synchronous delivery for `nextjs_revalidate_purge_url()`
because it put a front-end round trip in the request that saved a post; that
reasoning does not reach here, where the round trip *is* what the operator asked
for.
