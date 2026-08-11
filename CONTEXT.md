# Next.js Revalidate

A WordPress plugin that tells a headless Next.js front-end to rebuild its cached
pages when the corresponding WordPress content changes.

> Seeded during triage of #28 (migration versioning). Terms outside that area are
> the minimum needed for the rest to read, and should be sharpened as they come up.

## Language

### Revalidation

**Revalidation**:
Asking the Next.js front-end to discard and rebuild the cached version of a
single path. The unit of work this plugin exists to produce.
_Avoid_: Purge, cache clear, invalidation

**Revalidate all**:
A bulk operation that enqueues a revalidation for every publicly reachable page
of one or more post types.
_Avoid_: Purge all

**Revalidation queue**:
The durable, ordered set of revalidations awaiting delivery to the front-end.
Drained by cron rather than during the request that created the entries.
_Avoid_: Job list, backlog

**Scheduled purge**:
A revalidation registered to happen at a future time rather than immediately,
used for content with a publication or expiry date.

### Versioning and migration

**Plugin version**:
The version of the plugin *code* currently running, declared in the main plugin
file's header. The single source of truth for what release this is.
_Avoid_: NJR_VERSION as a concept distinct from the header — the constant is
derived from the header, not maintained alongside it.

**DB version**:
The version of the plugin whose data shape a given site's stored options match.
Distinct from the plugin version: a site can be running new code over old data,
which is exactly the window a migration closes.
_Avoid_: Schema version, options version

**Migration ledger**:
The per-site record of the DB version. The authority on which migrations a site
has already been through; a migration decides whether to run by consulting it,
never by inspecting the plugin version.
_Avoid_: Migration flag, version option

**Backfill**:
Establishing a DB version for a site that predates the migration ledger, by
inferring it from which legacy options are present in the site's data.
_Avoid_: Bootstrapping, seeding
