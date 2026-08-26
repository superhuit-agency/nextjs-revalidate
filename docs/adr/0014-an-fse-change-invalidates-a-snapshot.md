# An FSE change invalidates a snapshot; it does not revalidate anything

Decided while implementing #30, on the settings [ADR 0010](0010-endpoints-compose-from-a-domain-and-a-path.md)
put in place.

In the headless stack this plugin serves, Next.js renders every page inside a
WordPress FSE template. The whole template structure — every template, with
`core/template-part` blocks inlined and Polylang's translation variants attached
— is one derived value, the **FSE snapshot**. On a real project it is 702 KB, 13
templates, 1163 blocks.

Until now that snapshot was a build artefact: a script fetched it over WPGraphQL
and wrote a JSON file the front-end imported at build time. Editing a template in
the site editor therefore changed nothing until a full rebuild and redeploy — an
editor moving a block in the footer cost a CI run. The front-end is moving the
snapshot to runtime cached data behind a cache tag, and **this plugin is the half
that says when it went stale.**

From 1.7.0, saving a `wp_template` or `wp_template_part`, deleting one, or
switching themes sends **one request to the FSE endpoint**, gated by a single
`revalidate_on_fse_save` setting that a **new install** starts with on and an
**existing site** starts with off.

## It is not a revalidation, and the vocabulary is not decoration

A **revalidation** is of a single path: it is enqueued, drained by cron, and its
outcome joins the **failure window**. Almost none of that applies here.

An FSE change changes *every* page and *no* page in particular. There is nothing
to enqueue, no permalink to compose, and nothing a per-URL fan-out could
enumerate that would not be the whole site. The front-end invalidates a cache
tag — the Varnish `BAN` equivalent — and its pages rebuild lazily as they are
asked for. So this plugin's answer is one tagged ping and nothing else: no queue
entry, no cron, no warming.

Three consequences follow from that, and each one is a place where doing the
familiar thing would have been wrong:

**It does not enter the failure window.** The window "samples the queue's
traffic" and holds the outcomes of *revalidation attempts*; an FSE invalidation
was never enqueued and has no permalink to be recorded against. Feeding it in
would make **degraded revalidation** — the answer to "is the front-end updating
right now" — report on something the queue never carried. The outcome goes to the
log, which is the only place a site-editor save can be told anything at all: the
editor saves over REST and never reloads the page, so an admin notice would
surface on some later, unrelated screen.

**It does not enqueue on an unconfigured site; it refuses.** The same refusal
every other path gives, given here directly rather than at a drain, because there
is no drain.

**"Revalidate all" is not the answer either**, though the *event* is the same
category as the one it already handles. `RevalidateAll::revalidate_all_after_menu_update()`
reacts to "a global structure changed" by enumerating every post type and
enqueuing a URL for each — because the front-end has nowhere to put a
site-wide fact. For FSE it does, so the shape is different only for that reason.

## The gate is one switch, not a post type matrix

`revalidate_on_menu_save` is a set of per-post-type switches. Mirroring it here
was rejected: that shape exists solely because `revalidate_all()` must enumerate
post types in order to enqueue URLs. Here the payload is one ping that covers
every page at once, so a per-post-type choice would be a control with nothing on
the other end of it.

The on/off escape hatch still earns its place. A site whose Next.js app has not
been upgraded to serve the endpoint yet would otherwise emit a stream of failing
requests on every template save, with no way to stop them.

### A new install starts on; an existing site does not

The gate is on for a site installing 1.7.0 fresh, and off for a site upgrading
into it. The asymmetry is the whole point: an existing install's Next.js app is
whatever it already was, and it may not serve `/api/revalidate-fse` at all. On by
default there would mean a failing request on every template save, reported only
to the log — the site editor saves over REST and never reloads the page, so
there is no notice to show — until somebody found the switch. A site that has
never had an opinion about a setting must not start making requests it cannot
answer.

So the **empty value means off**, exactly as it does for every other setting in
the table, and only a row saying `on` invalidates. That is not the whole answer,
though, because it makes "on by default for new installs" something that has to
be *written down somewhere*, and neither obvious place works:

- **Not a migration gated on the version.** Every site predating the migration
  ledger is backfilled to the release that introduces it, so a `1.7.0` gate is
  read after the site has already been stamped past it and never fires for
  anybody. That constraint is already written down in `backfill_db_version()`,
  and it binds this exactly as it bound the settings split of #29.
- **Not a reading of the stored value.** A site upgrading into 1.7.0 and a site
  that has just switched the gate off hold the same empty row. Nothing about the
  value distinguishes them.

What does distinguish them is evidence about the site itself, and it is
available at exactly one moment: `Settings::define_settings()`, which runs when
a site is set up. A site holding **none of this plugin's rows** — no setting, no
legacy URL, no ledger — has never run this plugin, and only that site is seeded
`on`. An existing site holds rows already, whether it was ever configured or
not, and keeps its empty value. Setting a site up twice cannot re-seed it,
because the first setup is what created the evidence.

Uninstallation removes all three sets, so a reinstall is a new install. That is
the reading an operator who deleted the plugin's data would expect.

## Coalescing is per request, and the request is made at `shutdown`

A single site-editor save can reach more than one of these hooks, and a theme
switch changes every template at once. The front-end needs telling once, so the
first change of a request marks the snapshot stale and defers the telling to
`shutdown`.

`shutdown` rather than firing on the spot, for two reasons. It is what makes the
coalescing whole — every hook of the request has fired by then, whatever order
they came in — and it keeps a request to another host out of the middle of a save
an editor is waiting on. It survives the redirect a theme switch ends in, because
PHP runs shutdown functions after `exit()`.

`shutdown` alone does not get the editor out of the waiting, though. The response
is *produced* by then but not *delivered*: under PHP-FPM it sits in the buffer
until the process ends, so the person who pressed Save would wait out our timeout
as well as their own save. `FseSnapshot::close_request()` flushes it first —
`fastcgi_finish_request()`, or LiteSpeed's equivalent under its own name, and
nothing at all on a SAPI with neither. The request stays blocking on our side,
which is what keeps the outcome real enough to log; it is asynchronous from the
only side that was waiting.

The timeout is fifteen seconds rather than the minute a revalidation is given.
That request comes from cron; this one comes from a person's save, and what is on
the other end invalidates a cache tag and returns.

## Menus are out, deliberately

`wp_navigation` and the menu hooks are not here. Menu items are fetched at
request time by the front-end and are **absent from the snapshot by design**, so
invalidating it on a menu change would be pure waste. That is a fact about the
front-end rather than about this plugin, which is why it is written down: nothing
in this repository would stop somebody adding the hook, and it would look right.

## Considered Options

**Enqueue every page as a menu update does.** The behaviour that already exists,
and it works — at the cost of one queue entry and one rebuild per page, per
template edit, on a site whose whole point is that the template is shared. It is
what having no tag to invalidate forces, and the front-end no longer has that
excuse.

**Hook `wp_after_insert_post` and widen `should_revalidate()`.** The change that
looks smallest: `Revalidate` already hooks the save of every post. It was
rejected because `wp_template` posts are not publicly viewable and have no usable
permalink — the gate that stops them is `is_post_publicly_viewable()`, and
loosening it to let templates through would let them through *as revalidatable
posts*, which they are not. They have no page for the front-end to hold.

**A second queue, or a debounce across requests** (a transient, a scheduled
single event). More machinery than one O(1) request needs, and it would put the
one thing that must be immediate — an editor watching to see their change appear
— behind WordPress cron, which on a quiet site does not run.

## Consequences

**A tab of its own appears on the settings screen**, holding one switch. The
alternative — putting it in the API tab beside the FSE path — was declined
because that section is about *addressing* the front-end and this is about *when*
the plugin speaks to it, which is what the "On menu update" tab is already for.

**`Revalidate::purge()` no longer owns the transport.** The request and the naming
of its outcome moved to `Traits\FrontEndRequest`, shared with the invalidation, so
`unreachable`, `no_response`, `http_{status}` and `exception` mean one thing
across both. Nothing about `purge()`'s answer changed;
`tests/purge-outcome-test.php` is what says so.

**The runbook grew a section rather than a step**, in the extended pass. The core
pass is for what would mean the plugin does not work at all on an ordinary site,
and a site with no FSE templates is unaffected by every line of this.

**Verifying it end to end needs the other half.** Nothing in this repository can
prove that the front-end's snapshot actually refreshed — that is a Next.js app,
on a staging host, and the acceptance criterion naming tipee.ch is a manual check
against that stack. What this repo can prove stops at "exactly one request went
to the FSE endpoint, with the secret, when a template was saved".
