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

**Probe**:
A revalidation the operator asks for directly, in order to observe its outcome.
Delivered in the request that asked for it rather than through the **revalidation
queue**, and answered to the operator rather than recorded — a probe is never a
**failure** and never enters the **failure window**, because nothing about it
samples the queue's traffic.

Not a read-only check: a probe rebuilds the path it names, on the live front-end,
exactly as any other revalidation would. The operator's motive is the diagnosis;
the rebuild is real and happens anyway.
_Avoid_: Test — reserved for checks performed against *this plugin*, see **Manual
test**; ping, health check — both suggest the front-end is asked something
cheaper than a rebuild.

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

### Full site editing

**FSE snapshot**:
The whole WordPress template structure, as one derived value the front-end holds:
every template, with `core/template-part` blocks inlined and Polylang's
translation variants attached. Not this plugin's data and never assembled here —
the front-end builds it over WPGraphQL and caches it behind a cache tag. What
this plugin knows about it is only which WordPress changes make it stale: a
`wp_template` or `wp_template_part` saved or deleted, or a theme switched.
_Avoid_: Template cache, templates — the snapshot is one value covering all of
them, and a page holds no part of it separately.

**Snapshot invalidation**:
Telling the front-end, in one request to the **FSE endpoint**, that its **FSE
snapshot** is stale. Not a **revalidation** and not a bulk one: nothing is
enqueued, no permalink is composed, and no page is named — the front-end drops a
cache tag and its pages rebuild lazily as they are asked for. So it never
produces a **failure** in the sense the **failure window** holds, because it was
never in the **revalidation queue** to be attempted from.

The one exception to "invalidation" being a word this project avoids, and the
exception is what the term is for: it names the act that is genuinely not a
revalidation of a path.
_Avoid_: FSE revalidation, revalidating the templates — both suggest a path is
being rebuilt, which is the distinction this term exists to keep.

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

> What absence *resolves to* is a separate question, answered by whoever reads
> the setting rather than by the table: an **endpoint path**'s `''` resolves to
> its **default path**, which is not the empty value having an opinion.
>
> A setting whose default differs between a new install and an existing site
> cannot be answered that way at all — the two hold the same empty row. The FSE
> gate is the one such setting: `''` reads as off, and the `on` a new install
> starts with is written into the row at setup, by `define_settings()`, on the
> evidence that the site held none of this plugin's rows.

> The option table is authoritative for **reads**, registration, seeding and
> teardown alike: each enumerates the same declaration, so a setting cannot be
> added to one of them and forgotten in another.

**Revalidate domain**:
The scheme, host and port of the Next.js app this site talks to — everything an
endpoint URL has in common, stored once. One of the two settings a site cannot
revalidate without.
_Avoid_: Revalidate URL, front-end URL — the URL is composed, and naming the
stored half after the composed whole is what made a second endpoint unaddressable.

**Endpoint path**:
The route one kind of revalidation is served at on that app — `/api/revalidate`
for a single path, `/api/revalidate-fse` for the FSE snapshot. Stored per
endpoint and optional: a path left empty composes from the **default path** for
that endpoint, so a standard install supplies a domain and a secret and nothing
else. Whatever the operator's app routes, kept verbatim — never derived from
another path by string surgery.

**Endpoint URL**:
A **revalidate domain** and an **endpoint path** joined by exactly one slash, at
the moment a revalidation is sent. Composed, never stored: the settings hold the
two halves, and nothing in the options table is an endpoint URL.

> **Default path** and **empty value** are different things and both apply to a
> path setting. Its empty value is `''` — what a read yields on a site holding no
> row, as for every other scalar setting. Its default path is what *composition*
> substitutes for that `''`. The empty value still means absence; it is only the
> composition that has an opinion about what absence should resolve to.

**Configured site**:
A site holding both of the settings a revalidation cannot be delivered without —
the revalidate domain and the secret. The **endpoint paths** are deliberately not
among them, because each falls back to a default. The precondition for every
revalidation, and a per-site property: on a network each site is configured or
not on its own, and a newly created site starts unconfigured by design.
Half-configured is unconfigured.
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

**Swept version**:
The record of the release every site of a **network** was last asked to migrate
at. Network-scoped rather than per-site — the one piece of this plugin's state
that is — and compared against the **plugin version** on admin requests: when the
running code is newer, every site is swept and the record is then stamped.

Not a second ledger. It says nothing about any site's data shape, only whether
every site has been *asked* this release; the **migration ledger** remains the
authority on which migrations a given site runs, and a site that has nothing to
do answers the sweep with one option read. That split is what lets the sweep fire
once per network per release while migrations stay a per-site decision.
_Avoid_: Network DB version — it describes no data shape; last migrated version —
a site the sweep reached may have had nothing to migrate.

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
state except the **swept version**.
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
registration order, and nine of this plugin's callbacks sit on `admin_init` at
priority 10.
_Avoid_: Wiring, binding, hooking up

**Hookable**:
A class that registers WordPress hooks, declaring so by implementing the
interface of that name. Constructing one has no effect on global state;
`register_hooks()` is the only thing that does. Every class the composition root
constructs is Hookable, and a Hookable is always safe to construct for a single
method call. The root registers all of them but the Redirection integration in
one loop, in construction order; that one registers last, because it alone is
conditional on another plugin being installed.
_Avoid_: Listener, subscriber, observer — all imply a dispatcher this plugin does
not have.

### Testing

**Manual test**:
A check a person performs against a running site, whose answer only a browser, a
console or a file on disk can give. Not a test that happens to be unautomated:
what puts a check here is **reach** — an admin notice rendering, a redirect saved
through another plugin's own screens, a site upgraded from an earlier release —
never its subject. The automated idioms pin units and seams; a manual test pins
that the assembled plugin is wired together at all.
_Avoid_: Smoke test as a synonym — that is one kind of pass, not the category;
QA, acceptance test.

**Runbook**:
Where the manual tests are written down. Two documents, because one that holds
every check is one nobody runs: `docs/manual-tests.md` is the **core pass** and
`docs/manual-tests-extended.md` is the **extended pass**. No check appears in
both, so neither can drift from the other. Always committed unchecked: a runbook
describes a **pass** and never records one, and a ticked box in the repository is
a mistake rather than a result.
_Avoid_: Test plan, checklist, QA doc.

**Pass**:
One execution of a runbook, whole or partial. What a person does; distinct from
the runbook, which is what they read.
_Avoid_: Run — reserved for the automated suites, which are run rather than
passed through.

**Core pass**:
The manual tests worth running before every release and after any substantial
change: one **stack**, and short enough to actually be run. Not a summary of the
**extended pass** and not a subset of it — the two partition the manual tests
between them.
_Avoid_: Smoke test, quick test, sanity check — all three imply a shallower
version of something else, and this is not one.

**Extended pass**:
The manual tests the **core pass** leaves out, including every check that needs a
stack other than a single site. Run against the ground it covers when that ground
has been touched, and in full before a release that changes anything structural.
_Avoid_: Full pass — the whole of the manual tests is both documents, not this
one.

**Stack**:
The shape of the WordPress install a group of manual tests needs — a single site,
a network, or a site upgraded from an earlier release. Never the software
underneath: PHP, MySQL and Docker are the same in all three, and it is the
install that differs. Switching stacks is the expensive move a **pass** is
ordered to minimise, so every stack states its setup and its teardown in full.
_Avoid_: Environment, technology stack, LAMP — the first is wp-env's word for
something else, the last two are the reading this term exists to rule out.
