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

### Settings

**Setting**:
One named piece of operator-supplied configuration, stored as a single WordPress
option on the site. Declared once in `Settings`' option table, which pairs the
name the rest of the plugin reads with the option name it is stored under and the
value a read yields when the site has no row for it.
_Avoid_: Option — reserve that for the WordPress storage primitive a setting
happens to be kept in.

**Empty value**:
What reading a setting yields on a site that has never stored one. Declared per
setting, and of the setting's own type — `[]` for the set-shaped settings, `''`
for the scalar ones — never `false`. A read is therefore always safe to iterate
or compare without the caller guarding the type first.
_Avoid_: Default — the value is what *absence* means, not a preference a site
would sensibly keep.

> The option table is authoritative for **reads** only. Registration, seeding and
> teardown still enumerate the settings separately; unifying them is #24's to do.

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

### Network and sites

**Site**:
One WordPress install's worth of content and options. On a single install there
is exactly one; on a network there are many, each with its own table prefix,
options, cron array and transients. Every piece of this plugin's state is
per-site, without exception.
_Avoid_: Blog — WordPress's own internal term (`switch_to_blog`, `blog_id`), kept
only where core's API forces it.

**Network**:
The set of sites sharing one WordPress install. Owns nothing of this plugin's
state except the record of which sites have been swept.
_Avoid_: Multisite as a noun — it is a mode the install is in, not a thing.

**Site setup**:
Everything one site needs before it can revalidate: its queue table, its
registered options, its scheduled cron. Applied identically whether the site is
the only one on a single install, an existing site reached by a sweep, or a site
created later.
_Avoid_: Install, provision, activate — activation is the WordPress event that
may *trigger* setup, not the work itself.

**Site teardown**:
The inverse of site setup, in its two distinct depths: unscheduling cron on
deactivation, and dropping the table and options on uninstall. A site is torn
down at the same depth on a network as it would be on a single install.

**Network sweep**:
Applying a per-site operation across every site in a network. The mechanism
setup, teardown and migration all share. A sweep either reaches every site or
declines to start; a sweep that silently covers some sites is the failure this
plugin is designed against.
_Avoid_: Loop, batch, iterate

**Large network**:
A network with more sites than can be swept within one request, per core's
`wp_is_large_network()`. Sweeps decline rather than truncate, and the operator is
told to act per-site instead.
