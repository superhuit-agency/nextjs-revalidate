# The network sweeps migrations when its swept version differs from the running one

`Settings::migrate_db()` is hooked on `admin_init`, which fires per site. On a
network that means a site migrates only when a human opens *that site's* admin. A
plugin update reaches every site's *code* at once and no site's *data*, and
`register_activation_hook` does not fire on an update at all, so the sweep added
in #24 does not close the gap either — it runs on activation, and an update is
not one.

The consequence is not dormant inertia. WordPress cron on a site is triggered by
**front-end** traffic, not by admin visits, so a subsite with public traffic and
no admin visitors drains its **revalidation queue** and reads its **revalidate
domain** and secret out of unmigrated options for as long as nobody logs in. It
was masked until #28, because the version comparison it replaced resolved every
version to `0` and the migration that did run happened to be idempotent.

The network now keeps a **swept version** — a site option, network-wide rather
than per-site — holding the release its sites were last asked to migrate at. It
is compared against `NJR_VERSION` on `admin_init`; when the running code is
newer, `for_each_site()` carries `migrate_db()` to every site and the record is
stamped afterwards. Single-site installs never reach any of it: there, the
per-site hook already reaches the only site there is.

## Considered Options

**Hook the sweep on `upgrader_process_complete`.** The event WordPress fires
after its own updater has run. Rejected: it fires only for updates performed
*through* that updater, and this plugin is deployed by Composer, by git and by
dropping a zip over the directory at least as often. Those replace the files with
no event of any kind, which is exactly the case a comparison covers and an event
cannot.

**Make the sweep the network's `migrate_db()` — one network-scoped migration
routine.** Rejected: it merges two different questions. *Which* migrations a site
needs depends on that site's data, which is why #28 put the ledger per site;
*whether every site has been asked* is a property of the release. Keeping them
apart is what lets the sweep fire once per network while each site still decides
for itself, and what makes asking an up-to-date site cost one option read.

**Stamp the swept version before sweeping.** Simpler against concurrent admin
requests, which would then not both start a sweep. Rejected: a sweep cut short —
a fatal on one site, a request nobody waited for — would leave the network marked
as done and the remaining sites unmigrated until the *next* release, which is the
failure this change exists to remove. Stamping last means the worst case is a
sweep repeated, and a repeated sweep is a per-site option read.

**Chunk the sweep across cron with a persisted cursor**, so a large network is
covered too. Rejected for the reason ADR 0002 rejected it for setup: present-tense
machinery for an absent problem. Consistency matters more here than reach — one
sweep helper, one large-network answer.

## Consequences

The comparison uses `version_compare()`, as #28's does. The scheme both replaced
concatenated digits, ranking `1.7.0` (`170`) as older than `1.6.10` (`1610`); on
this path that would not have re-run a migration but *skipped a whole network*.

A sweep is due whenever the record is absent, empty, or older than the running
version — and a network swept by *newer* code keeps its higher record, for the
reason a site keeps its higher DB version: a downgrade must not make a sweep the
network has already been through due again.

Two concurrent admin requests can both start a sweep. Nothing serialises them,
and nothing needs to: each site's ledger has the last word on what runs there, so
the second sweep re-reads options and writes nothing. A lock would buy ordering
this design does not depend on.

On a **large network** the sweep declines rather than truncating, as every other
sweep does — but unlike activation there is nothing to undo, and unlike teardown
there is somebody left to tell. So it says so, on `admin_notices` and
`network_admin_notices`, to super admins only, and goes on saying so until the
network is swept: each site still migrates the first time its own admin is
opened, and that is the operator's route through. The notice recomputes the
condition rather than reading a flag `sweep_migrations()` set, so it states
something true on its own terms.

The swept version is deleted by `NextJsRevalidate::uninstall()` rather than by
`uninstall_site()` — it is the network's state, not a site's. Left behind, a
later reinstall would read it, believe every site had already been asked for this
release, and sweep none of them: the network-scoped twin of the stale ledger
ADR 0001 made an uninstall take with it.

This adds the ninth callback on `admin_init` at priority 10. The registration
order stays load-bearing, and `tests/HookRegistrationTest.php` still spells the
whole sequence out.
