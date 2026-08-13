# An unconfigured site refuses revalidations at the door, and says so on every admin screen

`Settings::is_configured()` was used by three callers as a silent guard: the
purge path, revalidate all, and the row/bulk actions all returned early and did
nothing. Nothing was printed, nothing was logged, and the settings screen looked
the same configured or not. An unconfigured install accepted edits, showed
“cache will be purged shortly”, and revalidated nothing — for as long as it took
someone to notice a stale page and connect it back. On a network this is the
default state of every new site, which does not inherit the revalidate URL or
secret (#24).

Two decisions, one for each half of the silence.

**The queue refuses at the door.** `RevalidateQueue::add_item()` now returns a
`not_configured` `WP_Error` on an unconfigured site, without writing a row or
scheduling the drain cron. This is what **Refusal** in `CONTEXT.md` names, and it
is the earliest point every entry point passes through — a save, a row action, a
bulk action, revalidate all, the REST routes and
`nextjs_revalidate_purge_url()`. Callers that counted enqueues now count only
accepted ones, so “%d caches will be purged shortly” can no longer report work
that was never queued.

**The notice is not dismissible, and is not confined to the settings screen.**
It renders on every admin screen, to anyone who can `manage_options` (who can
fix it) or `edit_posts` (whose edits are being dropped), naming which of the two
settings is missing. A permanently-dismissible notice would recreate the exact
silence this exists to break, and a notice only on the settings screen is only
ever seen by someone who already went looking.

A refusal is also written to the log via `Logger::log`, so an operator with the
logs setting switched on has the permalink and the reason. It is the queue that
writes it, at the single point every refusal passes through, rather than each
entry point writing its own. This is diagnostic only, and stays diagnostic
because logging is off by default: on the sites this ADR is about, nothing is
written at all. The notice, not the log, is what closes the gap.

## Considered Options

**Keep accepting items and drain them once the site is configured.** Attractive
because nothing an editor did is lost: fix the settings and the backlog flushes.
Rejected because the queue has no notion of age, so a site configured a month
later would revalidate a month of accumulated permalinks in one drain, most of
them long since superseded — and a site never configured accumulates a table
that nothing ever empties. It also contradicts at-most-once delivery (ADR 0004):
holding items pending a future configuration is a retry queue with no bound,
which is what that ADR declined to build.

**Refuse the edit itself** — block saving while unconfigured. Rejected outright:
this plugin warms a cache. A stale page is a cost, not a corruption, and
standing between an editor and their content to protect a cache inverts the
priorities completely.

**A dismissible notice, or one only on the settings screen and the post
editor.** Rejected on the issue's own reasoning. The plugin's work is invisible
by nature; the notice is the only signal that it is not happening, so it stays
until the thing it reports is fixed. The cost is a persistent notice on a
deliberately-unconfigured site — a network's staging subsite, say — which is
accepted: that site genuinely does not revalidate.

## Consequences

`add_item()` returns `bool|WP_Error` rather than `bool`. `RestApi::process_items`
already handled a `WP_Error` return and reports it per item with a 207, which is
how a REST client learns of a refusal. `nextjs_revalidate_purge_url()` returns
`false` on a refusal where it previously returned `true` unconditionally; a
caller that treated its return as meaningful gets a truthful answer for the first
time, and one that ignored it is unaffected.

A due **scheduled purge** on an unconfigured site is refused and dropped, like
any other revalidation — `ScheduledPurges::run_cron_hook()` removes an entry from
the option once its time has passed regardless of what the queue does with it.
The refusal is logged; the entry does not come back.

The unconfigured branch in `Revalidate::purge()` is now unreachable from the
drain, since nothing unconfigured reaches the queue. It stays as a guard, as ADR
0004 anticipated.

`missing_settings()` is covered by `tests/SettingsTest.php`, in the repo's plain
PHP style — no WordPress, the two option reads stubbed. It is the only part of
this change a test can pin down: the rest is admin-notice output and a `WP_Error`
return from a method that talks to `$wpdb` directly, neither of which is reachable
without a WordPress runtime. Testing the seam is worth it anyway, because
`missing_settings()` is what decides *which* of the two settings the notice names,
and `is_configured()` — the precondition every revalidation is measured against —
is now defined in terms of it.
