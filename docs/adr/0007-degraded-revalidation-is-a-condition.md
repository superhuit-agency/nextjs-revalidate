# Degraded revalidation is a condition read from a bounded window, not a log of failure events

ADR 0004 settled that a **failure** is recorded in the log and dropped, and
closed by naming the gap that left: the log is the only surface a failure appears
on, logging is off by default, and "an operator who does not already suspect a
problem is still told nothing." This is that gap, closed.

The plugin now keeps a **failure window** — the outcomes of the last ten
revalidation attempts, each failure carrying the error code `purge()` returned —
and renders an admin notice while at least three of them are failures. That
state is **degraded revalidation**: a live property of the site, computed at
render time, in exactly the way **configured** is.

The choice that makes this cheap is modelling it as a *condition* rather than an
*event*. An event model — "a revalidation failed" — is a fact that never stops
being true, so it needs a retention policy to decide when to forget it and a
dismissal to let the operator clear it. #37's brief bans stored dismissal state
outright, on the grounds that "a permanently-dismissible notice recreates the
silence," and a failure-event notice walks straight into that. A condition has
neither problem: it appears when the window says so and vanishes when it stops
saying so, with no bookkeeping and nothing for an operator to acknowledge.

A window rather than a consecutive-failure counter, because the counter is
well-tuned for a front-end that is *down* and blind to one that is *flaky*. A
front-end failing half the time never accumulates three consecutive failures, so
a counter stays silent forever while half the site goes stale — which is
precisely the "client notices weeks later" case this exists to prevent. Three
failures in ten is a real problem however they arrived, and the window degrades
correctly at low volume: a site with four attempts on record can still trip at
three, which is right, because three failures out of four is unambiguous.

The window stores each failure's error code so the notice can name a cause.
ADR 0004 made this argument for the log and it holds harder for a notice:
`unreachable` and `http_401` send an operator to completely different places. A
notice that says revalidations are failing produces a support ticket; one that
says the front-end rejected the secret produces a fix. The notice names the most
recent code rather than a histogram, and says only that other errors occurred if
the window holds more than one — a list of three codes is a notice nobody reads.

## Considered Options

**A transient rather than an option.** The queue already keeps its running-cron
count in one, so the precedent exists. Rejected because transients are evictable:
under an external object cache the window can vanish with no expiry having
elapsed, the condition evaluates false, and the warning disappears while the
front-end is still broken. That is this ADR's own failure mode, reintroduced
through a storage choice. The running-cron counter tolerates eviction because
losing it merely permits another cron; losing this loses the warning.

**A row in the queue table, or a new table.** The most direct way to hold
per-failure detail, and the option ADR 0004 rejected for the queue. Rejected
again and for the same reason: changing the queue table's shape recruits the
migration ledger (#28) and the network sweep (#36) in service of a notice. An
option needs neither — a new option has no old shape to migrate *from*, which is
what **empty value** in `CONTEXT.md` already describes.

**Last-attempt outcome only — one boolean.** The cheapest possible condition, and
it has the appealing property of never claiming things are fine when nothing is
known. Rejected as noisy in the wrong direction: one blip pins the notice up
until the next attempt, which on a quiet site is days away. An operator who
investigates a warning, finds nothing, and sees it still there learns to ignore
warnings — worse than silence, because it burns the channel.

**A failure list on the settings screen's queue tab.** The natural next thought,
and rejected for the reason the condition model exists: nothing per-failure is
actionable. Delivery is at most once, so no listed failure can be retried or
inspected to any purpose, and a list invites a reader to assume otherwise.

**An `admin_notices` notice and nothing else.** The established pattern, and how
the unconfigured notice reaches its reader. Kept, but not sufficient on its own:
core hides every `admin_notices` output on a block editor screen — the selector
`body.js.block-editor-page #wpbody-content > div:not(.block-editor)` in core's
editor stylesheet — and the post edit screen is where the person this notice
exists for actually is. An author saving a post is told the save succeeded, and
the revalidation it produced is the one being dropped; a warning that reaches
every admin screen except that one leaves the silence in place exactly where it
costs the most. The notice is therefore dispatched to `core/notices` there
instead, on the script the purge notice already travels on, and printed exactly
once either way. It is not dismissible there either, for the reason it is not
anywhere: it is a condition, and there is nothing to acknowledge.

## Consequences

The failure window is the only piece of this plugin's state that is cleared on
**deactivation**, which is a real exception to the two teardown depths ADR 0002
and `CONTEXT.md` describe. The reason is semantic rather than tidiness: while
deactivated, content changes and nothing is attempted, so on reactivation the
front-end's health is not bad but *unknown*, and carrying the old warning across
that gap asserts evidence the plugin no longer has. The exception is narrow by
design — it belongs to state that is evidence about a running plugin, and the
general rule must not widen, because clearing settings or pending scheduled
purges at deactivation would be destructive.

Ten and three are invented numbers. No data stands behind them, and the first
site to hit a genuinely flaky front-end is the experiment that tests them. They
are constants for exactly that reason.

Recording is read-modify-write on an option, and up to four drains run at once,
so two attempts finishing together can cost one outcome. Accepted rather than
locked: the window is a sample of recent health and not a ledger, and a lost
outcome moves the condition by one slot out of ten — inside the tolerance of
numbers nobody has tuned yet. The cost is that a front-end which is fully down
trips the notice an attempt or two later than it could have, never that it fails
to trip.

There is no aggregate view for a network administrator. Every piece of this
plugin's state is per-site, so a network-admin notice would have no window to
read, and computing a rollup means a sweep. Twenty sites means twenty dashboards
to check, and that is a known gap rather than an oversight.

The unconfigured notice (#37) is still an `admin_notices` one only, so it is
hidden on a block editor screen the way this one was. Widening it is that
issue's to make: the mechanism is now shared — a notice source localizes its own
payload onto the editor script handle rather than being collected by `Assets` —
and taking it is a couple of lines.

The notice yields to #37's unconfigured notice on the screens that render it.
They are nearly exclusive already, since an unconfigured site refuses at enqueue
and never accumulates attempts; the overlap is a site that was configured and
failing and then lost a setting, where the window is evidence about a
configuration that no longer exists.

The yield stops at the block editor screen, and has to. Deferring there means
deferring to a notice core hides, so a site that is unconfigured *and* degraded
would be told nothing at all — on the one screen this ADR argues the reader is
most likely to be on. Silence produced by two notices each assuming the other
speaks is worse than either notice being slightly wrong, so on that screen this
one speaks, and its link leads to the settings page the missing setting is on.
The deference returns of its own accord once #37's notice reaches the editor.

This depends on #49 twice over: for the definition of failure, and now for the
`WP_Error` codes the notice names. A failure arriving without a code must still
count toward the window — the condition is about failure, not about diagnosis,
and an unnamed cause is not a reason to stay silent.
