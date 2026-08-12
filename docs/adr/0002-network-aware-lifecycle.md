# Plugin lifecycle is per-site and network-aware, driven by one sweep that never partially succeeds

`register_activation_hook` passes a `$network_wide` flag that this plugin's
`activate()` never accepted. Network activation therefore ran setup once, against
whichever site served the request, and every other site on the network was left
with no queue table, no registered options and no cron. Deactivation and
uninstall had the same shape: uninstalling from a fifty-site network dropped one
table and left forty-nine behind.

All plugin state is now treated as **per-site** and applied through a single
**network sweep** — one `for_each_site()` helper serving **site setup**, **site
teardown** and, once #28 lands, migration. Setup is triggered eagerly: by
activation for sites that already exist, and by `wp_initialize_site` for sites
created later. On a **large network** the sweep declines to start and tells the
operator to act per-site, rather than truncating.

## Considered Options

**One network-wide queue table with a `blog_id` column.** Removes the sweep for
the table — but not for options or the migration ledger, so the sweep survives
anyway and the saving is illusory. Every query would gain a `blog_id` filter and
the `UNIQUE KEY permalink` would have to widen. Rejected: it makes the queue the
one network-scoped thing in an otherwise per-site design, while the drain stays
per-site regardless, because each site revalidates its own front-end with its own
URL and secret.

**Lazy setup — each site creates what it needs on first use.** Attractive: no
sweep, no bound to choose, new sites handled for free, and correct by
construction. Rejected because it makes activation unobservable — "did network
activation work?" has no answer until a site happens to enqueue something, which
is precisely the question #22 was filed to ask.

**A chunked cron sweep with a persisted cursor.** The robust answer at any scale,
and the right one if this plugin ever runs on networks in the thousands. Rejected
as present-tense machinery for an absent problem: core's own
`wp_is_large_network()` threshold is 10,000 sites and is filterable, so declining
above it costs a handful of lines and leaves the door open.

**Inheriting the revalidate URL and secret for newly created sites.** Rejected:
each site targets its own Next.js deployment, so a copied URL would point
revalidations at the wrong front-end. An unconfigured site revalidates nothing,
which is a far better failure than rebuilding a stranger's cache.

## Consequences

A sweep that reaches every site or none is a stronger guarantee than it appears,
because setup is eager with no lazy fallback: a site the sweep skips gets
*nothing*, so partial success would be indistinguishable from success. This is
why the large-network case refuses loudly instead of doing what it can.

`RevalidateQueue` cached `$table_name` and `$timezone` at construction, both
per-site values on a long-lived singleton. Under `switch_to_blog()` they kept
describing the original site, so the obvious sweep — switch, then call
`create_table()` — would have created the first site's table repeatedly, with the
existing `SHOW TABLES LIKE` guard turning every iteration after the first into a
silent no-op. It would have looked like it worked. These are now derived at call
time, so correctness no longer depends on a caller remembering to refresh.

`Settings` and `Logger` needed no equivalent change: both already resolve
per-site values at access time.

Declining a sweep is not enough on activation: a network activated plugin cannot
be activated on one site, so an operator told to act per-site has no way to. The
refusal therefore undoes the activation as well as explaining it — it dies before
WordPress records the activation, and deactivates for the paths that record it
first. That is also why the refusal is loud on setup alone: a declined teardown
sweep has nobody left to tell, since the plugin stops running the moment it
returns.

New sites are reached through `wp_initialize_site`, which arrived in WordPress
5.1 while the header still declares 5.0. Its predecessor, `wpmu_new_blog`, is
still fired on every version that has both — through `do_action_deprecated()`, so
listening to it warns under `WP_DEBUG` on every site creation, and listening to
both would run setup twice. Neither is worth paying to serve a release the
plugin's own "Tested up to" left behind years ago. A 5.0 network keeps working;
sites created on one are set up by activating the plugin on them.

Uninstall now deletes all five settings options rather than two. Beyond the
obvious leak, `nextjs_revalidate-db_version` surviving an uninstall would leave a
stale migration ledger for a later reinstall to trust, skipping migrations that
should run — so ledger cleanup is a correctness requirement of #28, not tidiness.

Scheduled purges are dropped with them, though they live outside the settings
declaration. Every option this plugin writes is per-site state and none of it
should outlive an uninstall; enumerating the settings is how *that* rule is kept
for settings, not the rule itself. The declaration is therefore the authority on
which settings exist, and site teardown is the authority on what a site holds —
a distinction worth keeping, since the next thing this plugin stores per site
will also need adding to teardown rather than to the settings table.

The migration sweep is deliberately not part of this change. Sweeping
`Settings::migrate_db()` before #28 replaces it would execute the existing broken
version — which parses every version as `0` and re-runs the oldest migration —
once per site, multiplying the bug by the site count rather than fixing it.
