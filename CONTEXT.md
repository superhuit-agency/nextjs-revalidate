# Next.js Revalidate

A WordPress plugin that tells a headless Next.js front-end which WordPress
content changed, so the front-end can revalidate whatever it cached from it.

> Seeded during triage of #28 (migration versioning). Terms outside that area are
> the minimum needed for the rest to read, and should be sharpened as they come up.
>
> Reshaped for v2 while grilling #81, which moves the plugin from naming paths to
> reporting changes — see ADRs 0033–0035.

## Language

### Revalidation

**Change**:
Something that happened to one WordPress subject — a post, a term, a redirect's
path, the templates — that may leave cache entries on the front-end stale.
Described as WordPress sees it, never as the front-end caches it: the plugin
reports changes, and the front-end decides which cache entries each one expires.
_Avoid_: Event — WordPress's hooks, and a field on the wire; fact; payload; tag —
the front-end's side of the line.

**Revalidation**:
Telling the front-end about changes, so it revalidates whatever it cached from
them. What the plugin delivers, and the unit a **failure**, a **refusal** and the
**failure window** are counted in.
_Avoid_: Purge, cache clear, invalidation — the front-end marks entries stale and
rebuilds them on demand; nothing is cleared.

**Revalidate all**:
An operator's request that the front-end revalidate everything it cached from
the site, or from one post type and the **revalidatable taxonomies** registered
for it. Reported as a single change, never as the pages it covers.
_Avoid_: Purge all

**Pending changes**:
The changes one request has produced and not yet delivered. Two changes to the
same subject merge into one — the state before the first and the state after the
last — so a post saved three times in a request is reported once. Delivered when
the request ends, or earlier in chunks when a long request, such as an import,
produces many.
_Avoid_: Queue — nothing outlives the request that produced it; batch.

**Scheduled purge**:
A path registered to be revalidated at a future time rather than immediately,
used for content with a publication or expiry date. Kept until its time passes,
then reported as a path change by the request that finds it due.

**Probe**:
A revalidation the operator asks for directly, in order to observe its outcome.
Delivered while the operator waits rather than with the request's **pending
changes**, and answered to the operator rather than recorded — a probe is never a
**failure** and never enters the **failure window**, because nothing about it
samples the site's ordinary traffic.

Not a read-only check: a probe reports a real path change, and the live front-end
revalidates what it cached from that path exactly as it would for any other. The operator's motive is the diagnosis;
the rebuild is real and happens anyway.
_Avoid_: Test — reserved for checks performed against *this plugin*, see **Manual
test**; ping, health check — both suggest the front-end is asked something
cheaper than a rebuild.

**Failure**:
A revalidation that was attempted against the front-end and did not succeed —
the whole request, whatever number of changes it carried, since the front-end
answers for them together. Recorded and dropped rather than retried: delivery is
at most once, so a failure is the end of every change that revalidation carried. Distinct from a **refusal**,
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
just **left the front-end**. A post that is not revalidatable produces no
revalidation at all; it is not refused, it was never a candidate.

A post **leaves the front-end** when the save that changed it moved it from a
status the status axis admits (publish or private) to one it does not — draft,
pending, future, trash, or any custom status. The front-end still holds the page
the post had, so the change reports the address the post had *before* the save
and none after it, and the front-end can make that page a 404. Defined against the status axis
rather than as a list of destinations, so it covers every status — including
ones a workflow plugin registers — and follows the axis if it is ever widened.
Private counts as on the front-end: private → trash leaves it, publish → private
does not.

Permanently deleting a post asks the same question of the post as it stands just
before it is gone: a publish or private post is revalidatable and its page is
revalidated, while a post already in the trash is not — trashing it already
revalidated the page, and the front-end has had no reason to cache it since. A
deleted *revision* is the one place the question is not asked at all: a save
treats a revision as standing for the post it belongs to, but a delete cannot —
the revision has no page of its own, and its post is deleted, or saved, in its
own right.

This is deliberately not core's `is_post_status_viewable()`, which rejects
private.

The site has the last word: a filter is applied after both axes and can admit or
decline any post, which is how a headless site whose types are not
`publicly_queryable` keeps its pages revalidating. Whether a post is
revalidatable is a question every entry point asks — a save, a row action, a bulk
action, the admin bar — never one that only save-time code consults.
_Avoid_: Public post — private posts are revalidatable, and password-protected
ones are too.

**Revalidatable taxonomy**:
A taxonomy whose terms' archive pages the front-end could hold, and whose terms
this plugin may therefore revalidate. One axis — the taxonomy is viewable,
WordPress's own `publicly_queryable` test, which for a taxonomy is that setting
and nothing else, with none of the `_builtin && public` fallback the post type
test applies.

One axis, where **revalidatable post** names two (type *and* status). The names
are siblings; the shapes are not, and the difference is why this is not called a
revalidatable *term*: a term has no status and no viewability of its own, so the
question is only ever asked about its taxonomy. Terms of a taxonomy that is not
revalidatable produce no revalidation at all; they are not refused, they were
never candidates.

The site has the last word here too, through a filter of its own rather than the
post one — the same escape hatch, for the same headless reason, and it can admit
a whole taxonomy as readily as decline one.

Only **revalidate all** asks the question today: nothing in this plugin reacts to
a term being created, edited or deleted, so a term archive goes stale until
somebody revalidates all. That gap is an enhancement, not a property of the taxonomy.
_Avoid_: Public taxonomy — `public` is a different setting and the two disagree
in both directions, which is the whole of the bug this names the fix for.

**Offered post type**:
A post type whose posts this plugin offers an operator an action over: the
"Purge caches" bulk action on its list screen, its allow purge all switch on the
settings page, and its entry in the admin bar's purge-all menu. One axis, the
type axis of a **revalidatable post** — WordPress's own `is_post_type_viewable()`
— with attachments taken out, because an uploaded file is not a page the
front-end holds.

An offer, and not a gate: that is the whole of the term. Being offered decides
nothing about whether a change is reported, which is **revalidatable post**'s
question and is asked of every post either way. A type this plugin does
not offer can still have revalidatable posts, through the post filter — and it
is then the site saying so, not this plugin.
_Avoid_: Public post type — `public` is a different setting and the two disagree
in both directions, which is the whole of the bug this names the fix for;
supported post type, allowed post type — both sound like a capability this
plugin grants rather than a menu it draws.

### Full site editing

**FSE snapshot**:
The whole WordPress template structure, as one derived value the front-end holds:
every template, with `core/template-part` blocks inlined and Polylang's
translation variants attached. Not this plugin's data and never assembled here —
the front-end builds it over WPGraphQL and caches it behind a cache tag. What
this plugin knows about it is only which WordPress changes make it stale: a
`wp_template` or `wp_template_part` saved or deleted, or a theme switched — each
reported as a templates change, like any other change, never naming which
template.
_Avoid_: Template cache, templates — the snapshot is one value covering all of
them, and a page holds no part of it separately.

> Until v2 a stale snapshot had its own endpoint and its own term, **snapshot
> invalidation**, because it was the one request that did not revalidate a path.
> Once no request revalidates a path, it is a revalidation like the rest, and
> the term is retired.

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

The site has the last word here too, but downward only: a filter is applied after
those axes, over the source path a change would report, and can decline any of
them — the escape hatch for a site whose front-end resolves redirects some other
way. Unlike the **revalidatable post** filter it cannot admit what the axes
declined, because a redirect they declined has no single path to hand it. Every
event that reports a source path asks it, and an update that changes the source
asks about the old path and the new one separately.
_Avoid_: Valid redirect — a regex redirect is perfectly valid, just not a single
path.

### Settings

**Setting**:
One named piece of operator-supplied configuration, stored as a single WordPress
option on the site. Declared once in `Settings`' option table, which pairs the
name the rest of the plugin reads with the option name it is stored under, the
value a read yields when the site has no row for it, and the callback every value
is sanitised through before it is stored — on a save of the settings screen and
on any other write to the option alike.
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
> gate was the one such setting: `''` read as off, and the `on` a new install
> started with was written into the row at setup, by `define_settings()`, on the
> evidence that the site held none of this plugin's rows. v2 removes the gate
> with the FSE endpoint (ADR 0034), leaving no setting of that kind; the rule
> stands for the next one.

> The option table is authoritative for **reads**, registration, seeding and
> teardown alike: each enumerates the same declaration, so a setting cannot be
> added to one of them and forgotten in another.

**Revalidate domain**:
The scheme, host and port of the Next.js app this site talks to — everything an
endpoint URL has in common, stored once. One of the two settings a site cannot
revalidate without. Empty on an unconfigured site; otherwise saved only as an
`http` or `https` URL with a host — a value that is not one is refused on save,
and the domain held before is kept. A row stored before 1.7.0 was never held to
that rule.
_Avoid_: Revalidate URL, front-end URL — the URL is composed, and naming the
stored half after the composed whole is what made a second endpoint unaddressable.

**Endpoint path**:
The route revalidations are served at on that app — `/api/revalidate` by
default. Optional: a path left empty composes from the **default path**, so a
standard install supplies a domain and a secret and nothing else. There is one
from v2, every change travelling to the same route; v1 kept a second for the FSE
snapshot. Whatever the operator's app routes, kept verbatim — never derived from
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
the revalidate domain and the secret. The **endpoint path** is deliberately not
among them, because it falls back to a default. The precondition for every
revalidation, and a per-site property: on a network each site is configured or
not on its own, and a newly created site starts unconfigured by design.
Half-configured is unconfigured.
_Avoid_: Set up, installed — site setup is the plugin preparing a site, which
says nothing about whether an operator has since supplied these two values.

**Refusal**:
Declining to deliver a revalidation that could not be delivered, in preference to
accepting one and dropping it later. The response to an unconfigured site.
Given when the change is produced, so a refused change never joins the
**pending changes** at all; a site whose settings are cleared before the request
ends is refused at delivery instead, which is the same answer given later.
Distinct from **failure**, which is a revalidation that reached the front-end and
did not succeed — what separates the two is whether anything was ever asked of
the front-end, not when the answer was given.
_Avoid_: Skip, ignore — both suggest the revalidation was unimportant rather than
undeliverable.

**Log file**:
The file the plugin appends its own diagnostics to, one per site, in a directory
this plugin owns beneath that site's uploads directory and under a name unique to
that site. Written only while the operator has the logs setting switched on, and
created by the first line written rather than by switching the setting on — so on
a site that has never logged, its absence is the normal state and not a fault.
Every log line the plugin can produce passes through that one setting; there is
no second channel that logs regardless. Its path is composed rather than known in
advance, and the settings screen is where an operator reads it.
_Avoid_: Debug mode — the plugin has a setting that enables logging, not a mode
it runs in.

**Redaction**:
Taking the secret out of a message the plugin did not write itself, at the moment
that message becomes an outcome. Applied to exactly two of them — what the HTTP
transport said about a request it could not complete, and what anything in the
request path threw — because every request this plugin makes carries the secret,
and those two messages are the only ones whose author is outside this repository.

Two passes, and neither covers the other: a `secret=` query arg is blanked **by
shape**, with the configured value never consulted, and the configured secret is
then replaced **by value** wherever else it appears — in every spelling it can
travel in, since a URL carries it `urlencode()`d rather than as it was typed.
Deliberately unguarded by any minimum length — a one-character secret is a legal
configuration, so it is redacted like any other and the surrounding diagnostic
is allowed to come out garbled.

> From v2 the secret travels in an `Authorization` header rather than a query
> arg, so the by-shape pass has nothing left to find in a request of this
> plugin's own; the by-value pass is what still applies, because a transport
> message can quote a header back. The by-shape pass stays while the
> revalidation queue still sends v1's `GET`, and is a harmless no-op once it
> does not (ADR 0023, amended).

A property of messages *leaving the transport*, never a property of the **log
file**: a redaction says nothing about what a file already holds, or about who
can read it.
_Avoid_: Sanitising — reserved for WordPress's own input functions; masking,
scrubbing, filtering.

### Versioning and migration

**Plugin version**:
The version of the plugin *code* currently running, declared in the main plugin
file's header. The single source of truth for what release this is.
_Avoid_: NJR_VERSION as a concept distinct from the header — the constant is
derived from the header, not maintained alongside it.

**WordPress floor**:
The oldest WordPress release the plugin declares it runs on — `Requires at
least` — and set by the newest core API the plugin uses, not by preference.
Written `MAJOR.MINOR`, and stated in four places the test suite holds together;
the analysis fails on a use of anything core introduced after it. Below it the
plugin is not degraded but refused: core, from 5.2 on, declines the activation.
_Avoid_: Minimum version, WP requirement — both leave open whether it is a
recommendation; supported versions, which is a support promise and not this.

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
Everything one site needs before it can revalidate: its registered options and
its scheduled cron. Applied identically whether the site is
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
