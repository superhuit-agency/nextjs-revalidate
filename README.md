
# Next.js revalidate

Next.js plugin allows you to purge & re-build the cached pages from the WordPress admin area.
It also automatically purges & re-builds when a page/post/... is saved or updated.

The revalidation request is sent to an endpoint composed from the settings — the
**revalidate domain** joined to the **revalidate path** — with two query arguments.

1. The relative `path` to revalidate
2. The `secret` to protect the revalidation endpoint.

### Settings

| Setting | Required | Default |
| --- | --- | --- |
| Revalidate domain | yes | — (e.g. `https://example.com`) |
| Revalidate path | no | `/api/revalidate` |
| FSE revalidate path | no | `/api/revalidate-fse` |
| Revalidate secret | yes | — |
| Revalidate on FSE update | no | on for a new install, off for an upgrade |

A standard install fills in the domain and the secret. The paths exist for apps
that route these endpoints somewhere else; each falls back to the default shown
in its placeholder when left empty.

### FSE templates

Next.js renders its pages inside WordPress FSE templates, and holds the whole
template structure as one cached value. Saving a template or a template part in
the site editor, resetting one to its theme default, or switching themes
therefore changes every page at once — so the plugin sends **one** request to the
FSE endpoint, with the secret and no path, and the front-end's pages rebuild
lazily from there. Nothing is enqueued and nothing is rebuilt page by page.

```
https://example.com/api/revalidate-fse?secret=my-super-secret-string
```

Menu changes do **not** trigger this: menu items are fetched at request time by
the front-end and are not part of the template snapshot.

**Revalidate on FSE update** starts on for a new install and **off for a site
upgrading from an earlier release** — an existing front-end may not serve that
endpoint yet, and every template save would otherwise ask it for a route it does
not have. Switch it on once the front-end is serving it.

Sites upgrading from 1.6.x had a single, fully-qualified revalidate URL. It is
split into a domain and a path automatically on the first admin request after the
upgrade, custom paths and all — nothing to do by hand.

### Example
```
https://example.com/api/revalidate?path=/hello-world/&secret=my-super-secret-string
```

> Based on the Next.js [On-demand revalidation](https://nextjs.org/docs/basic-features/data-fetching/incremental-static-regeneration#on-demand-revalidation) documentation

### Probing the front-end

The **Probe** tab of the settings screen reports one path change to the
front-end straight away, in a request of its own, and shows what it answered —
including the error message and its code when it did not work. It is a real
revalidation and not a dry run: the front-end is sent the same request, through
the same `nextjs_revalidate_change` filter, as for any other change, using the
*saved* settings, so it answers "does this site revalidate right now" rather
than "would these values work".

A probe is never counted towards the "not keeping this site up to date" warning:
pressing it can neither raise that warning nor clear it. It is written to the log
file when logging is on, marked `🔎 Probe`.

## Requirements

- Requires PHP 7.4+
- Requires WordPress 5.6+

## API functions

Neither function can tell you whether the front-end has revalidated anything.
Each answers what the plugin took on: `nextjs_revalidate_path` whether the
change was **accepted** into the request's pending changes, and
`nextjs_revalidate_schedule_path` whether the schedule was registered — which is
one step further away, since that change is only reported when the date time
passes, and can be refused then. Pending changes are sent to the front-end once
the request that produced them has answered, so there is no return value in
this plugin that could report the outcome of a delivery that has not happened
yet. Delivery is at most once — a revalidation that is attempted and fails is
written to the log and dropped, never retried.

Both take a full URL or a path. A URL is reduced to its path from the domain
root — `https://example.com/hello-world/?ref=x` and `/hello-world/` are the same
change — keeping its trailing slash, or its lack of one, as given.

### nextjs_revalidate_path

Reports a path as changed, so the front-end revalidates whatever it cached from
it: a **path** change, `{ "subject": "path", "uri": "/hello-world/" }`, for a
path this plugin has no other way to know about. What the front-end expires for
it is the front-end's decision.

#### Usage
```php
nextjs_revalidate_path( $url );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| url  | string | The URL, or the path, that changed |

#### Returns

`bool` — whether the change was accepted into the pending changes. It is `false`
when the site is unconfigured, which is a **refusal**: the revalidate domain or
the secret is missing, nothing has been accepted, and nothing will be. It is also
`false` when the site's `nextjs_revalidate_change` filter dropped the change, and
for a URL that names no path at all. A path already reported in the same request
is accepted (`true`) and sent once.

It is never a statement about the front-end. A `true` says the plugin will try.

### nextjs_revalidate_schedule_path

Registers a path to be reported as changed at a future date time — a
**scheduled purge**. Nothing is reported until then: the cron request that finds
it due reports it as a path change, so a schedule registered on a configured site
is still refused at its due time if the site is unconfigured by then. The entry
is dropped either way.

#### Usage
```php
nextjs_revalidate_schedule_path( $datetime, $url );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| datetime  | string | The date time from which the path is due |
| url  | string | The URL, or the path, to report then |

#### Returns

`bool` — whether this call registered the scheduled purge. It is `false` when
that URL is already registered for that date time: the schedule stands, and this
call added nothing to it. It is also `false` if the write failed.

### Deprecated: nextjs_revalidate_purge_url, nextjs_revalidate_schedule_purge_url

The 1.x names, kept as wrappers until 3.0
([ADR 0035](docs/adr/0035-v2-breaks-the-wire-and-nothing-else.md)).
`nextjs_revalidate_purge_url( $url, $priority )` calls `nextjs_revalidate_path()`
and `nextjs_revalidate_schedule_purge_url( $datetime, $url )` calls
`nextjs_revalidate_schedule_path()`, answering what those answer. Both go
through WordPress's `_deprecated_function()`, so they warn under `WP_DEBUG` and
fire `deprecated_function_run` either way. `$priority` is accepted and ignored:
there is no queue left for it to order.

## REST routes

Two routes, for a deploy hook, a CI job or an external CMS asking this site to
revalidate. Both accept `POST`, `PUT` or `PATCH`, and both take the site's
revalidate secret — the same value the plugin sends to the front-end — as a
parameter rather than as a header. Each item becomes a **path** change,
`{ "subject": "path", "uri": … }`, exactly as `nextjs_revalidate_path` reports
one.

Plugin 2.0 still serves them under `/v1/`: a REST namespace versions the REST
API rather than the plugin, and the request these routes take has not changed.

| Route | Reports |
| --- | --- |
| `/wp-json/nextjs-revalidate/v1/revalidate` | one path |
| `/wp-json/nextjs-revalidate/v1/revalidate/batch` | an array of them |

```bash
curl -X POST https://example.com/wp-json/nextjs-revalidate/v1/revalidate \
  -H 'Content-Type: application/json' \
  -d '{"secret":"my-super-secret-string","path":"https://example.com/hello-world/"}'

curl -X POST https://example.com/wp-json/nextjs-revalidate/v1/revalidate/batch \
  -H 'Content-Type: application/json' \
  -d '{"secret":"my-super-secret-string","items":[{"path":"/hello-world/"},{"path":"/about/"}]}'
```

| Parameter | Type | Description |
| --- | --- | --- |
| secret | string | Required. The site's revalidate secret. |
| path | string | The single route only, required. A URL or a path; a URL is reduced to its path from the domain root. |
| items | array | The batch route only, required. Objects with a `path`, and optionally a `priority`. |
| priority | int | Optional, and ignored since 2.0: there is no queue left for it to order. Still accepted — and still required to be an integer — so a request 1.x took is taken still. |

What a route answers with is an acceptance, never a delivery: the change is in
the request's pending changes, which are sent to the front-end once the response
has gone. Nothing in the response says the front-end has revalidated anything,
and nothing can — see the note above `nextjs_revalidate_path`.

Both routes answer the same body, with one result per item sent, in the order
they were sent:

```json
{
  "success": false,
  "results": [
    { "path": "/hello-world/", "success": true, "data": true },
    { "path": "/about/", "success": false, "message": "Missing path" }
  ]
}
```

A result carries `data` — always `true`, where 1.x carried the queue's own
answer — only when the item was accepted, and a `message` only when it was not.
Match results to items by position; `path` is echoed back as it was sent, and is
`null` for an entry that carried none. An entry of `items` that is not an object
is reported as an item with no `path`, never skipped.

### Statuses

The status describes the request as a whole, and is decided by the outcomes
rather than by which route produced them:

| Status | Meaning |
| --- | --- |
| `200` | Every item was accepted into the pending changes. |
| `207` | Some items were accepted and some were not — read `results[].success` for which. Only the batch route can answer this. |
| `400` | No item was accepted, and the items are why: one carried no `path`. Also the answer when the request itself could not be read — no `path` on the single route, or no `items` array on the batch route. |
| `503` | No item was accepted because this site is unconfigured: the revalidate domain or the secret is missing, so nothing can be revalidated until an operator supplies them. |
| `500` | No item was accepted because of something on this site: the site's own `nextjs_revalidate_change` filter dropped the change, or something threw. Also what a site holding **no secret at all** answers, from the permission check, with the code `missing_secret`. |
| `401`/`403` | The secret did not match. Nothing was reported. |

A request in which **nothing** was accepted always answers 4xx or 5xx, never
2xx — so a caller that only checks the status still learns that nothing was
accepted. Where items failed for different reasons, `503` outranks `500`, which
outranks `400`. Releases before 1.7 answered `207` for every failed request,
including one in which nothing at all was queued;
[ADR 0027](docs/adr/0027-a-wholly-failed-request-answers-a-failure-status.md) has
the reasoning and what it changes for a caller.

## Which posts are revalidated

A post is revalidated when the front-end could hold a page for it: its post type
is viewable — WordPress's own `publicly_queryable` test, via
[`is_post_type_viewable()`](https://developer.wordpress.org/reference/functions/is_post_type_viewable/)
— and its status is `publish` or `private`, or it has just left the front-end:
the save moved it from `publish` or `private` to any other status — `draft`,
`pending`, `future`, `trash`, or one an editorial workflow plugin registers.
Posts of a post type that is not viewable are never revalidated, whatever their
status.

A revalidated post is reported as a `post` change, which describes the post as
the front-end sees it before and after:

```json
{ "subject": "post", "id": 42, "type": "post", "before": { "uri": "/hello/" }, "after": { "uri": "/hello-world/" } }
```

`uri` is the path from the domain root. A side is `null` when the post is not on
the front-end on that side — its status is not `publish` or `private` — so a
publish has no `before`, and a post that has left the front-end, or has been
deleted, has no `after`. An edit has two equal sides, a slug change two
different URIs, and the **Purge cache** row action, bulk action and admin bar
entry report the post as it stands, with both sides its current URI. A post
saved several times in one request is reported once, from the first `before` to
the last `after`. A revision stands for the post it belongs to, and an
attachment is never reported.

A headless site registering post types with `publicly_queryable => false` while
its front-end still renders their permalinks can say so with the filter below.

Permanently deleting a post asks the same question of the post as it stands just
before it is gone, and reports its URI with no `after`, so the front-end stops
serving a page for content that no longer exists. A post already in the trash is
not reported again: trashing it reported that page gone already, and the
front-end has had no reason to cache it since — so emptying the trash, by hand
or through WordPress's scheduled sweep, reports nothing. Deleting a revision
reports nothing either; the post it belongs to still has its page.

## Which post types the admin offers

The same viewability decides what this plugin *offers* for a post type: the
**Purge caches** bulk action on its list screen, its allow purge all switch on
the settings page, and its purge-all entry in the admin bar. Attachments are
never offered: an uploaded file is not a Next.js route.

Those are offers and not gates — what is revalidated is the section above, and
it is decided per post whatever the admin shows. The post filter below cannot
widen them either: it answers about one post, and no list of post types can be
derived from it. A headless site that wants the bulk surfaces for a type it
admits with that filter overrides viewability itself, with core's own
[`is_post_type_viewable`](https://developer.wordpress.org/reference/hooks/is_post_type_viewable/)
filter — this plugin asks that function, so the offer and the gate move
together.

A switch already stored for a post type that is no longer offered is left as it
is, and does nothing: no purge-all entry is offered for it. Saving the settings
page drops the stored row.

## Menus

Saving a menu reports one `menu` change, carrying the menu's ID and the theme
locations it is assigned to — none, for a menu assigned to no location. Which
pages that affects is the front-end's to decide; nothing is revalidated page by
page, and there is no setting for it.

## Which terms are revalidated

Revalidate all of the whole site reports one `all` change, and nothing else:
which pages and archives that covers is the front-end's to decide. Revalidate all
of one post type reports one `all` change naming the type and the taxonomies
registered for it whose term archives the front-end may hold — provided the
taxonomy is viewable, which is WordPress's own `publicly_queryable` test, via
[`is_taxonomy_viewable()`](https://developer.wordpress.org/reference/functions/is_taxonomy_viewable/).
The question is asked once per taxonomy rather than once per term: a term has no
status and no viewability of its own, and no term is read.

Note that for a taxonomy `publicly_queryable` is that setting and nothing else,
with none of the `public` fallback the post type test applies — so a taxonomy
registered `public => true, publicly_queryable => false` is never named,
and one registered the other way round is. A headless site can say
otherwise with the filter below, which is consulted for every registered
taxonomy and can admit one WordPress would never route.

Nothing else revalidates a term: this plugin does not react to a term being
created, edited or deleted, so a term archive goes stale until somebody
revalidates all.

## Integrations

An integration is a third-party plugin whose changes this plugin reacts to when
that plugin is present. This plugin supports an integration; it never requires
one. With that plugin absent nothing registers, no feature here needs it, and
revalidation of the site's posts is unaffected either way.

### Redirection

A headless front-end resolves a redirect inside the cached page of the path it
redirects *from*, so creating, editing, deleting, enabling or disabling a
redirect in [Redirection](https://wordpress.org/plugins/redirection/) leaves that
path answering as it did before until its cache entry expires. With Redirection
active, this plugin reports a **redirect** change for the source path whenever a
redirect changes — `{ "subject": "redirect", "uri": "/old-path/" }` — so the
redirect starts, or stops, working as soon as the front-end has been told, once
the request that saved it has answered.

**Supported, never required.** With Redirection absent, nothing here registers
and the plugin behaves exactly as it did before; installing it, or removing it
again later, changes nothing about how posts are revalidated. There is no setting
to switch the integration on: a redirect change reports exactly one path, so
there is nothing to gate.

#### What revalidates, and when

| A redirect is | and the plugin reports a redirect change for |
| --- | --- |
| created | its source path |
| edited | its new source path — and the source it had before the edit, see below |
| deleted | the source path it stops redirecting |
| enabled | the source path it starts redirecting |
| disabled | the source path it stops redirecting |

Changing a redirect's *source* leaves two paths stale, the one that stops
redirecting and the one that starts, and both are revalidated — on Redirection
versions whose update action carries the redirect's previous state. Redirection
5.9.0 and later pass the redirect's id instead, so nothing hands over the source
it had: only the new path is revalidated, and the old one keeps redirecting until
its own cache entry expires. See
[ADR 0014](docs/adr/0014-redirect-changes-revalidate-the-source-path.md).

#### Which redirects are candidates

Only a **revalidatable redirect** produces a revalidation: one the front-end
could resolve for a single path, which means its source is a literal path rather
than a regular expression, and it is enabled. A redirect that is not
revalidatable produces no revalidation at all — it is not refused, it was never a
candidate. Disabling one is the single event that does not ask whether it is
enabled: by the time the plugin hears about it the redirect is already stored as
disabled, and that it stopped being enabled is exactly the change the front-end
has not heard about yet.

**A redirect whose source is a regular expression is skipped entirely.** It
matches an unbounded set of paths, so there is no single path to rebuild, and
nothing is reported for it — the front-end simply keeps serving the page it
already holds, with nothing on screen to say why. The skip is recorded in the
plugin's log file and nowhere else, and only while logging is switched on; with
logging off, a regex redirect is silent. The line it writes, and the other
reasons a redirect gets one, are below. It deliberately does not escalate to a
**revalidate all**: the regex box is a per-rule checkbox an editor can tick
casually, and turning one tick into a site-wide rebuild is worse than the
staleness it would cure.

Source paths are reduced to their path component, dropping any query string or
domain the source was stored with, and given the site's trailing slash
convention, so they match the form the site's post permalinks take. A source
names a path from the domain root rather than from the site — which is how
Redirection matches them, and what a change's `uri` is — so on a site served
from a subdirectory that directory
is already part of the source.

#### Why a redirect did not revalidate

Nothing about a redirect change is reported on screen. Redirects are saved
through Redirection's own REST routes, from an admin that never reloads the
page, so a revalidation that did not happen is answered by the plugin's **log
file** or by nothing at all. Every case in the table writes one line to that
file, and only while **Enable logs** is switched on under the **Debug** tab of
*Settings → Next.js revalidate*; its full path is printed beneath that switch.
It lives in a `nextjs-revalidate/` directory beneath the site's uploads
directory, under a name unique to the site — one file per site on a network, and
never at a path you can type from memory. With logging off there is
nothing to read anywhere, which is why switching it on is the first step for a
redirect that "did nothing".

Every one of them is a single line of the same shape, so the whole set is one
grep away:

```
[2026-04-28 11:04:07]	[INFO]	[Redirection.php] ↪️ Redirect #12 not revalidated (source: ^/blog/(.*)) — its source is a regular expression, which names no single path
```

What follows the dash is the reason, and there are five of them:

| How the line ends | What happened |
| --- | --- |
| `its source is a regular expression, which names no single path` | The redirect was never a candidate, as above. |
| `it is disabled, so the front-end resolves nothing for it` | The redirect was created, edited, deleted or enabled while stored as disabled, so what the front-end holds for its source is already the right answer. Disabling one is the exception, and does revalidate. |
| `it names no path of this site to rebuild` | The source names no path to rebuild — it is empty, it is not a URL this plugin can read a path out of, or it is the bare site root. |
| `a filter declined the revalidation of …` | [`nextjs_revalidate_should_revalidate_redirect`](#nextjs_revalidate_should_revalidate_redirect) returned `false` for that path. |
| `⛔ Refused a redirect change — site not configured` | The path was a candidate and its change was **refused**: the revalidate domain or the secret is missing. Nothing was accepted, and nothing will be until the site is configured. Written by the pending changes rather than by this integration, so it does not start `↪️ Redirect #…`. |

A redirect that *is* revalidated writes no line of its own here — its change
simply joins the request's pending changes. The line arrives when they are
delivered, once the request has answered, as `✅ Revalidated` or
`❌ Failed to revalidate` followed by how many changes the request carried and
of which subjects — `2 changes (redirect ×2)` — written by the delivery rather
than by this integration.

#### Bulk operations and imports

Redirection fires these events once per redirect, from its bulk actions as much
as from a single edit, so a bulk delete of three hundred redirects reaches this
plugin three hundred times — and so does an import, which creates its redirects
through the same code path a hand-typed one goes through. What the front-end is
told is **one redirect change per distinct source path**: redirects sharing a
source cost a single change, because two identical changes in one request merge
into one. Nothing is capped, collapsed above a threshold, or escalated to a
revalidate all.

The changes are delivered when the request that made them ends — in one request
to the front-end, or in several of about a hundred changes each when a long
request, such as an import, produces more than that. Nothing waits for cron.

#### Declining a revalidation

A site whose front-end resolves redirects some other way can decline any of these
revalidations with the
[`nextjs_revalidate_should_revalidate_redirect`](#nextjs_revalidate_should_revalidate_redirect)
filter, without deactivating the plugin or losing the revalidation of its posts.

## Filters

### nextjs_revalidate_should_revalidate_redirect

Filters whether a redirect's source path is revalidated. Return `false` to leave
the path alone — the escape hatch for a site whose front-end resolves redirects
some other way, from build-time configuration, from middleware, or from anywhere
a per-path revalidation does not reach.

Applied last, and to every redirect change that revalidates a source path:
creating, updating, deleting, enabling and disabling one alike. It is asked once
per path, so changing a redirect's source puts the path that stops redirecting
and the one that starts to it separately, and a site can decline either on its
own.

Unlike the post filter below it declines rather than admits. A redirect that is
not revalidatable never reaches it — a regular expression source names no single
path, so there is nothing to hand the filter, and returning `true` revalidates
nothing.

#### Usage
```php
add_filter( 'nextjs_revalidate_should_revalidate_redirect', function( $should_revalidate, $path, $redirect ) {
	if ( 0 === strpos( $path, '/legacy/' ) ) return false;
	return $should_revalidate;
}, 10, 3 );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| should_revalidate | bool | Whether the source path is revalidated |
| path | string | The source path, normalised |
| redirect | object | The redirect the path is the source of, as Redirection's own `Red_Item` |

### nextjs_revalidate_should_revalidate_post

Filters whether the given post is revalidated. Applied last, so it can admit a
post the rules above decline, or decline one they admit. Every entry point asks
it — a save, a permanent delete, the row action, the bulk action and the admin
bar.

It decides whether a post is a candidate, not what its change says: the two
sides of the change are still read off the post's status, so a post admitted
while it is on the front-end on neither side — a draft saved as a draft — is not
reported.

**Renamed in 2.0.0** from `nextjs_revalidate_purge_should_revalidate_post_on_save`,
which it was called when only a save asked it. The old name is still applied,
before this one, through `apply_filters_deprecated()`: a callback on it keeps
working, and under `WP_DEBUG` WordPress raises a deprecation notice naming this
filter. Move the callback here; the old name goes in 3.0.

#### Usage
```php
add_filter( 'nextjs_revalidate_should_revalidate_post', function( $should_revalidate, $post_id ) {
	if ( 'my-headless-type' === get_post_type( $post_id ) ) return 'publish' === get_post_status( $post_id );
	return $should_revalidate;
}, 10, 2 );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| should_revalidate | bool | Whether the post is revalidated |
| post_id | int | The post ID |

### nextjs_revalidate_should_revalidate_taxonomy

Filters whether the archive pages of the given taxonomy's terms are revalidated.
Applied last, and consulted for every registered taxonomy, so it can admit a
taxonomy that is not `publicly_queryable` as readily as decline one that is.

#### Usage
```php
add_filter( 'nextjs_revalidate_should_revalidate_taxonomy', function( $should_revalidate, $taxonomy_name, $taxonomy ) {
	if ( 'my-headless-taxonomy' === $taxonomy_name ) return true;
	return $should_revalidate;
}, 10, 3 );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| should_revalidate | bool | Whether the taxonomy's terms are revalidated |
| taxonomy_name | string | The taxonomy name |
| taxonomy | WP_Taxonomy\|false | The taxonomy, or false when none is registered under that name |

### nextjs_revalidate_change

Filters every change before it joins the request's pending changes. Return the
change, altered or not, or `false` to drop it. What is returned is sent as it
is, so keep it in the shape your front-end reads.

#### Usage
```php
// Keep one post's manual and automatic revalidations away from the front-end.
add_filter( 'nextjs_revalidate_change', function( $change ) {
	if ( 'post' === $change['subject'] && 123 === $change['id'] ) return false;
	return $change;
} );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| change | array | The change, with its `subject` and that subject's fields — a post's are `id`, `type`, `before` and `after` |

### nextjs_revalidate_purge_action_permalink

**Retired in 2.0.0, and no longer applied.** It filtered the permalink the
"Purge cache" row action, bulk action and admin bar entry put in the queue. A
post is now reported as a change keyed by its ID, which carries no permalink to
rewrite. A callback still on this filter is never called; when one of those
actions runs, `_deprecated_hook()` names
[`nextjs_revalidate_change`](#nextjs_revalidate_change) as the replacement.
Move the callback there — it sees every change rather than those three entry
points, so check `$change['subject']` is `post` — and return `false` where it
returned `false`.

### nextjs_revalidate_show_unconfigured_notice

Filters whether an unconfigured site shows its unconfigured notice. Return
`false` to silence it on a site that is unconfigured on purpose, such as a
network's staging subsite.

It is asked last, and only on an unconfigured site, for a user who would
otherwise see the notice. A configured site never reaches it, and neither does
a user who can neither `manage_options` nor `edit_posts`. It covers every
admin screen, and the block editor too: there, the degraded revalidation notice
stands in for the unconfigured one when the site's recent revalidations failed,
and the filter silences that stand-in with it. On a configured site, the
degraded notice ignores this filter.

It silences the notice and nothing else. An unconfigured site still refuses
every revalidation, still logs each refusal when logs are on, and its REST
routes still answer 503.

#### Usage
```php
// In an mu-plugin: silence the notice on the network's staging subsite only.
add_filter( 'nextjs_revalidate_show_unconfigured_notice', function( $show, $missing_settings ) {
	if ( 3 === get_current_blog_id() ) return false;
	return $show;
}, 10, 2 );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| show | bool | Whether the notice is shown. Defaults to `true` |
| missing_settings | string[] | The settings the site is missing: any of `domain` and `secret` |


## Tests

Two commands, and which one a test belongs to depends on whether it needs real
WordPress state. See `docs/adr/0008-two-testing-idioms.md`. A third idiom is not
a command at all — see the runbook below.

### `npm run test:php` — standalone scripts

Scripts under `tests/` that stub the handful of WordPress functions their
subject touches. No framework, no database, no Docker; they run anywhere PHP
does, including a sandbox with neither.

The command globs `tests/*.php` — every script at the top level, in one
interpreter each, stopping at the first one to exit non-zero. Top level only:
`tests/integration/` is the other suite's, and needs Docker. **Adding a script
needs no wiring** — drop it in `tests/` and the next run picks it up. Each
script runs under `php`, or under `PHP_BIN` when that is set.

The rule runs both ways: **every top-level `.php` under `tests/` is a test and
will be executed as one.** There is no shared helper to drop beside them — ADR
0008 gives each script its own stubs and no autoload precisely so that none is
needed. Order is not a contract either: the glob is walked in whatever order
the shell's locale collates, which is neither the order they were written in
nor the same on every machine — **no script may depend on another having run
first.** A failing script's own exit code is the command's.

### `npm run test:integration` — the integration suite

PHPUnit tests under `tests/integration/` that boot WordPress with this plugin
active and assert on what an event produces: given some WordPress state and an
event, which changes are **pending** — a post's save or delete — or which paths
does the **revalidation queue** revalidate, in what order, at what priority?

From a fresh checkout:

```bash
npm install
composer install
npm run test:integration
```

wp-env installs the Redirection plugin alongside this one, on the development
site and on the test site, so the redirect integration can be exercised without
assembling an install by hand. The suite's bootstrap loads it and creates its
tables when it is there, and skips the tests that need it when it is not. The two
sites are two config files, `.wp-env.json` and `.wp-env.tests.json`, and wp-env
has no way for one to extend the other: a plugin added to one has to be added to
both.

Neither config pins a Redirection version, so the suite runs against whatever
upstream ships — which is what makes it notice a change there
([ADR 0014](docs/adr/0014-redirect-changes-revalidate-the-source-path.md)). It
also means an upstream release can turn the suite red: creating Redirection's
tables means naming files inside it, and 5.10.0 moved them.
`tests/integration/redirection-database.php` knows that layout and the one before
it, and reports the release that moves them again rather than running the redirect
tests against tables nothing created. An environment started before a release
keeps the copy it downloaded until `npx wp-env start --update --config=…`.

The command starts wp-env itself — `wp-env start` is idempotent, so running it
again costs seconds. It runs against a site of its own, described by
`.wp-env.tests.json` and served on port 8888: wp-env gives each config file its
own containers and database, so the development site keeps its data and its
settings, and does not have to be running. Docker must be running. The first run
downloads WordPress and its PHPUnit test library and takes a few minutes.

To reach that site by hand, pass the same file:
`npx wp-env run --config=.wp-env.tests.json cli wp option list`. It reads
`.wp-env.tests.override.json`, never `.wp-env.override.json`, so an override
made for the development site — the multisite one of the extended pass — does
not change what the suite runs against. Nor does `npm run stop` stop it: the test
site stays up after a run until `npx wp-env stop --config=.wp-env.tests.json`.

Write a test by extending `NextJsRevalidate\Tests\QueueTestCase`, which
configures the site, enqueues paths and reads the queue back:

```php
$this->configure_site();                       // a configured site — an
                                               // unconfigured one refuses
$this->enqueue( '/hello-world/', 5 );          // enqueue a path at a priority
$this->assertQueueRevalidates( [ '/hello-world/' ] );  // paths, in drain order
$this->assertQueueRevalidatesAtPriorities( [ '/hello-world/' => 5 ] );
$this->assertQueueHolds( [ home_url( '/hello-world/' ) ] ); // the permalinks
```

The queue **holds permalinks**, and those permalinks **revalidate paths** — the
two are kept apart because on a network they can disagree. `assertQueueHolds()`
takes permalinks; `assertQueueRevalidates()` takes paths and normalises against
the site's home url, so a test survives a change of the test site's port.

The queue table is created once in the bootstrap and emptied around every test.
It has to be: `RevalidateQueue::add_item()` runs its own transaction, whose
`COMMIT` also commits the one `WP_UnitTestCase` uses to roll a test back.

What reports a **change** rather than enqueueing a path — the public API, the
REST routes, a due scheduled purge, the Redirection integration — is tested by
extending `NextJsRevalidate\Tests\PendingChangesTestCase` instead, which reads
what the request holds before anything is delivered:

```php
$this->configure_site();
nextjs_revalidate_path( home_url( '/hello-world/' ) );
$this->assertReports( [ Change::path( '/hello-world/' ) ] ); // changes, in order
$this->assertReportsNothing();                               // or none at all
```

The pending changes are emptied around every test, which is also what keeps the
suite's own `shutdown` from sending them anywhere.

### The manual test runbook — checks no command can run

An admin notice rendering on the screen it is meant for, a redirect saved through
Redirection's own UI, a site upgraded from 1.6.9. What puts a check here is
**reach** — whether the answer can only come from a browser, a console or a file
on a running site — never its subject. See
`docs/adr/0012-a-third-testing-idiom.md`.

Two files, partitioning the checks between them. **No step appears in both.**

- **`docs/manual-tests.md`** — the **core pass**: steps on a single site. Run
  it before every release, and after any change worth ten minutes.
- **`docs/manual-tests-extended.md`** — the **extended pass**: everything else,
  including the network stack and a site upgraded from the previous release. Run
  the part covering what you touched, and all of it before a structural release.

Both are committed **unchecked**. Tick the boxes in your working copy during a
pass; never commit the ticks.

## Checks

Four npm scripts, and they are the whole gate:

```bash
npm run typecheck      # tsc --noEmit over src/
npm run lint:php       # php -l over every tracked PHP file, on PHP 7.4
npm run test:php       # the standalone scripts above
npm run analyse:php    # PHPStan over the 7.4–8.4 span the plugin claims
```

`.github/workflows/ci.yml` runs all four on every pull request against `main` and
on every push to `main`. Nothing is defined there that is not an npm script here,
so a green CI run means what a green local run means — and no more: syntax,
types, PHP-range compatibility and the handful of behaviours the standalone
scripts pin. It is not a claim that a change works.

`lint:php` refuses to run on anything but PHP 7.4, the version
`nextjs-revalidate.php` declares, because a newer parser accepts PHP 8-only
syntax and proves nothing. Point `PHP_BIN` at a 7.4 binary, or set
`ALLOW_PHP_VERSION_MISMATCH=1` and know the lint proves nothing. `analyse:php`
needs `composer install` first — PHPStan is a dev dependency.

`npm run test:integration` is **not** in CI yet: it needs Docker, and wiring it up
is its own piece of work.

Requiring a green run to merge is a repo setting, not a file — branch protection
on `main` and `v1.x`, with **Typecheck** and **PHP 7.4** as required status
checks, both pinned to the GitHub Actions app. A branch need not be up to date
with its base to merge: the `push` run on `main` is what catches two green
branches combining into a red one. Admins are not held to it, so a release bump
can still be pushed straight to `main`.
