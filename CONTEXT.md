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

A **revalidation** is of a path, but a queue entry stores the **permalink** — the
absolute URL, as the front-end is asked for it. The distinction is not
bookkeeping: the table a queue entry lands in follows `switch_to_blog()`, while
the permalink written into it resolves against whichever site is current, so the
two can disagree on a network. Say "the queue holds permalinks"; reserve "path"
for the thing being revalidated.
_Avoid_: Job list, backlog

**Scheduled purge**:
A revalidation registered to happen at a future time rather than immediately,
used for content with a publication or expiry date.

**Failure**:
A revalidation that was enqueued, attempted against the front-end, and did not
succeed. Recorded and dropped rather than retried: delivery is at most once, so a
failure is the end of that revalidation's life. Distinct from a **refusal**,
which is declined without the front-end being asked anything at all.
_Avoid_: Error, rejected, unsuccessful purge

**Failure window**:
The outcomes of the last ten revalidation attempts on a site, each failure
carrying the error code the attempt returned. Not a log and not a queue: it holds
outcomes rather than revalidations, and nothing in it can be retried or acted on
individually. The only state this plugin clears on deactivation, because it is
evidence about a *running* plugin — see **Site teardown**.
_Avoid_: Failure log, history — both imply a record kept for reading back.

**Degraded revalidation**:
The condition of a site whose failure window holds three or more failures. A live
property computed when it is asked for, the way **configured** is, rather than a
flag some earlier code path set. What the operator-facing notice renders, and the
answer to "is the front-end updating right now" — never a statement about any one
revalidation.
_Avoid_: Broken, down, unhealthy — the front-end may be fine and the secret
merely wrong.

**Revalidatable post**:
A post the front-end could hold a page for. Its type is viewable — WordPress's
own `publicly_queryable` test — and its status is publish or private, or it has
just left publish for draft or trash. A post that is not revalidatable produces
no revalidation at all; it is not refused, it was never a candidate.

The site has the last word: a filter is applied after both axes and can admit or
decline any post, which is how a headless site whose types are not
`publicly_queryable` keeps its pages revalidating. Whether a post is
revalidatable is a question every entry point asks — a save, a row action, a bulk
action, the admin bar — never one that only save-time code consults.
_Avoid_: Public post — private posts are revalidatable, and password-protected
ones are too.

### Integrations

**Integration**:
A third-party plugin whose changes this plugin reacts to when that plugin is
present. Never a dependency: an absent integration is inert, and no feature of
this plugin requires one.
_Avoid_: Support as a noun, dependency, requirement — this plugin supports an
integration, it never requires one.

**Redirect**:
A rule owned by an integration, mapping one source path to a target. Not this
plugin's data — it is read, never written.
_Avoid_: Redirection, which is the plugin the rule belongs to; and reserve
"redirect" alone for `wp_safe_redirect()`'s admin sendbacks, which are unrelated.

**Revalidatable redirect**:
A redirect the front-end could resolve for a single path: its source is a literal
path rather than a regex, and it is enabled. A redirect that is not revalidatable
produces no revalidation at all; it was never a candidate.
_Avoid_: Valid redirect — a regex redirect is perfectly valid, just not a single
path.

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

> The option table is authoritative for **reads**, registration, seeding and
> teardown alike: each enumerates the same declaration, so a setting cannot be
> added to one of them and forgotten in another.

**Configured site**:
A site holding both of the settings a revalidation cannot be delivered without —
the revalidate URL and the secret. The precondition for every revalidation, and a
per-site property: on a network each site is configured or not on its own, and a
newly created site starts unconfigured by design. Half-configured is unconfigured.
_Avoid_: Set up, installed — site setup is the plugin preparing a site, which
says nothing about whether an operator has since supplied these two values.

**Refusal**:
Declining to deliver a revalidation that could not be delivered, in preference to
accepting one and dropping it later. The response to an unconfigured site.
Normally the answer at enqueue time, so a refused revalidation usually never
reaches the queue at all; a site whose settings are cleared while items are
pending is refused at the drain instead, which is the same answer given later.
Distinct from **failure**, which is a revalidation that reached the front-end and
did not succeed — what separates the two is whether anything was ever asked of
the front-end, not how far down the queue the answer was given.
_Avoid_: Skip, ignore — both suggest the revalidation was unimportant rather than
undeliverable.

**Log file**:
The file the plugin appends its own diagnostics to, one per site, in that site's
uploads directory. Written only while the operator has the logs setting switched
on, and created by the first line written rather than by switching the setting
on — so on a site that has never logged, its absence is the normal state and not
a fault. Every log line the plugin can produce passes through that one setting;
there is no second channel that logs regardless.
_Avoid_: Debug mode — the plugin has a setting that enables logging, not a mode
it runs in.

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

The **failure window** is the one exception, and is cleared at the shallower
depth too. It records what happened while the plugin was running, so a gap in
which nothing was attempted leaves the front-end's health unknown rather than
bad, and carrying the window across that gap would assert evidence the plugin no
longer has. The exception is narrow on purpose: it covers state that is evidence
about a running plugin, and does not extend to settings or pending scheduled
purges, which deactivation must leave untouched.

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

### Composition and hooks

**Composition root**:
The one place that decides which of this plugin's objects exist, in what order,
and when they register their hooks — `NextJsRevalidate::__construct()`. Nothing
else constructs a long-lived object.
_Avoid_: Bootstrap, container, plugin init — `init()` is the static accessor that
returns the already-built root, not the thing that builds it.

**Hook registration**:
Attaching a class's callbacks to WordPress actions and filters. A separate act
from constructing that class, performed once, by the composition root. The order
is load-bearing: WordPress runs same-hook, same-priority callbacks in
registration order, and eight of this plugin's callbacks sit on `admin_init` at
priority 10.
_Avoid_: Wiring, binding, hooking up

**Hookable**:
A class that registers WordPress hooks, declaring so by implementing the
interface of that name. Constructing one has no effect on global state;
`register_hooks()` is the only thing that does. Every class the composition root
constructs is Hookable, and a Hookable is always safe to construct for a single
method call.
_Avoid_: Listener, subscriber, observer — all imply a dispatcher this plugin does
not have.
