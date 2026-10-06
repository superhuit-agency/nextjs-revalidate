# Next.js Revalidate

Tells a Next.js front-end which WordPress content changed — posts, menus,
templates, redirects — so it can revalidate whatever it cached from it. Changes
are reported as they happen, on save, and on demand from the admin.

The plugin reports **changes**, described as WordPress sees them, and never
names the front-end's cache tags: which entries a change expires is the
front-end's decision
([ADR 0033](docs/adr/0033-the-plugin-reports-changes-not-tags.md)). The
front-end writes that mapping once, in a route handler — see
[the front-end contract](#the-front-end-contract) and the
[reference route](examples/next/app/api/revalidate/route.ts).

Upgrading from 1.x? 2.0 changes the request the front-end receives, and a
site's Next.js route has to change with it, in the same deploy. Read the
[2.0.0 release notes](CHANGELOG.md#200) first.

### Settings

| Setting | Required | Default |
| --- | --- | --- |
| Revalidate domain | yes | — (e.g. `https://example.com`) |
| Revalidate path | no | `/api/revalidate` |
| Revalidate secret | yes | — |

A standard install fills in the domain and the secret. The path exists for an
app that routes its endpoint somewhere else, and falls back to the default shown
in its placeholder when left empty. Every change goes to that one route.

Sites upgrading from 1.6.x had a single, fully-qualified revalidate URL. It is
split into a domain and a path automatically on the first admin request after the
upgrade, custom paths and all — nothing to do by hand.

## The front-end contract

What the plugin sends, and what it takes for an answer. This is contract version
**2**; the plugin's own version is not part of it.

### The request

```http
POST {revalidate domain}{revalidate path}
Authorization: Bearer <secret>
Content-Type: application/json

{ "version": 2, "changes": [ … ] }
```

- **`Authorization`** carries the site's revalidate secret, after `Bearer `. It
  is never sent in the URL or the body.
- **`version`** is the contract version, `2`. See the rules below.
- **`changes`** is a non-empty array of changes, one object per subject that
  changed.

The changes are the **pending changes** of the WordPress request that produced
them, delivered when that request ends — after the editor has had their answer,
where the server allows it. Two changes to the same subject in one request are
sent as one: a post saved three times is one post change, from its state before
the first save to its state after the last. A long request, such as an import,
sends its changes in several requests of up to 100 changes each, rather than one
unbounded body. On a network, each site's changes go to that site's own domain,
with its own secret.

### The subjects

Every change has a `subject`, and that subject's fields. There are eight:

| Subject | Fields | Sent for |
| --- | --- | --- |
| `post` | `id`, `type`, `before`, `after` | a post saved, published, unpublished, trashed or deleted; a post's terms written with no save — see [term membership](#term-membership); a post whose page another post's save moved — see [dependent posts](#dependent-posts); a post reordered or reparented by Nested Pages or Simple Custom Post Order; the **Revalidate** row action, bulk action and admin bar entry; `nextjs_revalidate_post()` |
| `term` | `id`, `taxonomy`, `before`, `after` | a term of a **revalidatable taxonomy** created, edited or deleted; a term whose archive another term's edit or delete moved — see [dependent terms](#dependent-terms); the default term a delete moved posts into |
| `redirect` | `uri` | a redirect created, edited, deleted, enabled or disabled in Redirection — one change per affected source path |
| `path` | `uri` | `nextjs_revalidate_path()`, the inbound REST routes, a due scheduled purge, the probe |
| `menu` | `id`, `locations` | a classic menu saved; a block menu (`wp_navigation`) saved, trashed, restored or deleted |
| `templates` | none | an FSE template or template part saved or deleted, a theme switched |
| `settings` | none | a **site setting** added, changed or deleted — one change per request, however many were saved |
| `all` | none, or `type` and `taxonomies` | revalidate all, of the whole site or of one post type |

```json
{
  "version": 2,
  "changes": [
    { "subject": "post", "id": 42, "type": "post", "before": { "uri": "/hello/", "terms": [ { "id": 7, "taxonomy": "category", "slug": "video" } ] }, "after": { "uri": "/hello-world/", "terms": [ { "id": 9, "taxonomy": "category", "slug": "podcast" } ] } },
    { "subject": "term", "id": 7, "taxonomy": "category", "before": { "slug": "video", "uri": "/category/video/" }, "after": { "slug": "videos", "uri": "/category/videos/" } },
    { "subject": "redirect", "uri": "/old-path/" },
    { "subject": "path", "uri": "/feeds/events/" },
    { "subject": "menu", "id": 7, "locations": [ "primary" ] },
    { "subject": "templates" },
    { "subject": "settings" },
    { "subject": "all", "type": "post", "taxonomies": [ "category", "post_tag" ] }
  ]
}
```

Every field, and when it is `null`:

| Subject | Field | Type | Meaning |
| --- | --- | --- | --- |
| any | `subject` | string | Which subject changed. |
| `post` | `id` | integer | The post's ID. |
| `post` | `type` | string | Its post type, as registered: `post`, `page`, `event`… |
| `post` | `before` | `{ uri, terms }` or `null` | Where the front-end showed the post before the change, and the terms it was in. `null` when it was not on the front-end — a publish. |
| `post` | `after` | `{ uri, terms }` or `null` | Where the front-end shows it after, and the terms it is in. `null` when it is no longer there — unpublished, trashed or deleted alike, since the page is gone either way. |
| `post` | `terms` | `{ id, taxonomy, slug }[]` | On each side, the post's terms in **revalidatable taxonomies** — the archives it appears in — sorted by taxonomy then ID. `[]` when it is in none. |
| `term` | `id` | integer | The term's ID. |
| `term` | `taxonomy` | string | Its taxonomy, as registered: `category`, `post_tag`, `genre`… |
| `term` | `before` | `{ slug, uri }` or `null` | The term's slug, and where its archive was, before the change. `null` when it was not there — a term just created. |
| `term` | `after` | `{ slug, uri }` or `null` | Its slug, and where its archive is, after. `null` when it is no longer there — a term deleted. |
| `redirect` | `uri` | string | A source path whose redirect changed. |
| `path` | `uri` | string | A path somebody reported as changed, without saying what is there. |
| `menu` | `id` | integer | The term ID of a classic menu, or the post ID of a block menu. The two can collide: nothing tells them apart, and a front-end tagging menus by ID expires one extra entry at worst. |
| `menu` | `locations` | string[] | The theme locations the menu is assigned to. Empty for a classic menu assigned to none, which a block, a widget or the front-end may still render by its ID — and always empty for a block menu, which has no locations. |
| `all` | `type` | string | The post type revalidated. Absent — with `taxonomies` — for the whole site. |
| `all` | `taxonomies` | string[] | The **revalidatable taxonomies** registered for `type`, whose term archives the front-end may hold. Possibly empty. Present exactly when `type` is. |

- **A `uri`** is always the path from the domain root, with a leading slash:
  the path of the URL, and nothing else — a domain or a query string is never
  part of it. It keeps the trailing slash, or its lack of one, that WordPress's
  permalink or the caller gave it. A site on plain permalinks, whose posts live
  at `/?p=42`, reports `/` for each of them: a front-end telling posts apart by
  `uri` needs pretty permalinks, and every post change carries its `id` too.
- **A post's `before` and `after`** describe it *as the front-end sees it*: a
  post is on the front-end while its status is `publish` or `private`. Only
  one side is ever `null` — a change with both `null`, a draft saved as a draft,
  is never sent. Compare the two sides rather than looking for an event name: an
  edit has two equal URIs, a slug change two different ones, a publish no
  `before`, and anything that takes the page away no `after`. The row action,
  bulk action and admin bar entry report a post as it stands, with both sides its
  current URI.
- **A post's `terms`** are on both sides of every post change, whatever
  produced it. Expire the archive of every term on either side: a publish
  reaches the archives the post joined, an unpublish, a trash or a delete the
  ones it left, a move from *Video* to *Podcast* both, and an edit the ones
  whose listings show its title. A producer with no record of the post's terms
  before — the row action, a dependent post, `nextjs_revalidate_post()` — lists
  the terms it has now on both sides.
- **A term's `before` and `after`** compare the same way: an edit of its name
  or description has two equal sides, a slug change two different slugs and
  URIs, a creation no `before`, a delete no `after`. `slug` is there for a
  front-end tagging terms by slug, whose entries still carry the old one after
  a slug change.
- **A term's change never names the posts in it**, however many there are. A
  rename or a delete is one change, so **a front-end must tag whatever shows a
  term — its archive, a badge or a term list on a post's page — with that
  term's tag**, and expire it on the term's change. One that tags only the
  archive leaves every post page showing the old name.
- **`templates`** never names which template changed: the front-end holds the
  whole template structure as one value, the **FSE snapshot**.
- **`settings`** never names which setting changed: it says something every
  page renders changed. See [Site settings](#site-settings).
- **`all`** is one change, never the pages it covers.

### Two rules

The contract grows without breaking a front-end written against it:

1. **Ignore what you do not recognise** — a subject, or a field on a subject you
   know. A new subject or field is therefore not a breaking change, and ships in
   a minor release of the plugin.
2. **`version` is raised only for a breaking change** — a field removed, renamed,
   or its meaning changed. Answer a `version` you do not know with an error (a
   4xx): the plugin records it as a failure, and its "not keeping this site up
   to date" notice reaches an operator, instead of the wrong entries expiring in
   silence.

### The answer

**Any 2xx is a success** — 200, 202 and 204 alike; the body is not read.
Everything else is a **failure**, and so is a request the front-end did not
answer within **five seconds**. A 307 or a 308 to the same scheme, host and
port is followed, with the same request, so a route served only at
`/api/revalidate/` — `trailingSlash: true` — is reached; any other 3xx is a
failure ([ADR 0039](docs/adr/0039-a-delivery-follows-a-redirect-that-keeps-the-request.md)).
Type the path with its trailing slash to skip the extra round trip.
A front-end should mark entries stale and answer; it should not rebuild pages
before answering.

One request has one outcome: there are no per-change results, and a failure
fails every change the request carried. Delivery is **at most once** — a failure
is written to the log file and dropped, never retried
([ADR 0004](docs/adr/0004-at-most-once-revalidation.md)), and it counts towards
the degraded-revalidation notice an operator sees when three of the last ten
requests failed ([ADR 0007](docs/adr/0007-degraded-revalidation-is-a-condition.md)).

### A reference route

[`examples/next/app/api/revalidate/route.ts`](examples/next/app/api/revalidate/route.ts)
is a complete Next.js route handler, in TypeScript with the payload types above.
It checks the secret, answers an unknown `version` with a 400, maps every subject
onto an example tag scheme and expires each tag with `revalidateTag( tag, 'max' )`:

| Change | Tags expired |
| --- | --- |
| `post` | `node:{id}`, `type:{type}`, and `term:{id}` for every term on either side; and `uris` when `before.uri` and `after.uri` differ — a publish, an unpublish, a trash, a delete or a slug change |
| `term` | `term:{id}`; and `uris` when `before.uri` and `after.uri` differ — a creation, a delete, a slug change or a move |
| `redirect`, `path` | `uris` |
| `menu` | `menu:{id}`, whatever its locations |
| `templates` | `templates` |
| `settings` | `settings` |
| `all` of the whole site | `content` |
| `all` of one post type | `type:{type}`, `type:{taxonomy}` for each of its taxonomies, and `nodes` |

The scheme is an example: which tag an entry carries is your app's decision, and
the route is the place to say it. Copy the file into your app and change
`tagsFor()`. It type-checks in this repository with `npm run typecheck`, against
a declaration of `next/cache`'s `revalidateTag()` as Next.js 16 gives it.

### FSE templates

Next.js renders its pages inside WordPress FSE templates, and holds the whole
template structure as one cached value. Saving a template or a template part in
the site editor, resetting one to its theme default, or switching themes
therefore reports **one** `templates` change, to the same route as every other
change, and the front-end decides what that expires. Nothing is rebuilt page by
page.

Menu changes do **not** report a `templates` change: a menu save reports a
`menu` change of its own.

### Site settings

A **site setting** is a value held once for the whole site that the front-end
renders as it is, on any page: the site title, the tagline, the date and time
formats, SEO defaults, the language list. Saving any of them reports **one**
`settings` change for the request, however many were saved, and the front-end
decides what that expires — typically a tag every cached page carries.

An option is a site setting when its name is on the list the
[`nextjs_revalidate_site_setting_options`](#nextjs_revalidate_site_setting_options)
filter returns. By default that is WordPress's own:

`blogname`, `blogdescription`, `date_format`, `time_format`, `timezone_string`,
`gmt_offset`, `home`, `site_icon`, `site_logo`, `WPLANG`

One on the list reports a change when it is added, updated to a different value,
or deleted. An entry ending in `*` names every option starting with what comes
before it, for an option stored once per language: `landbot_config_url_*` covers
`landbot_config_url_fr` and `landbot_config_url_de`. Saving a value an option already holds reports nothing: WordPress
fires no update for it.

Options that move which content lives at which path — the permalink structure,
the category and tag bases, the front page, the posts page, the posts per page —
are **not** site settings, and report nothing here. Expiring what every page
carries does not fix what they leave stale
([ADR 0037](docs/adr/0037-a-settings-change-reports-what-every-page-renders.md)).

The [Yoast SEO](#yoast-seo) and [Polylang](#polylang) integrations add their own
site settings.

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

Changes are sent once the editor has had their answer where the server can
answer first: under PHP-FPM (`fastcgi_finish_request()`) and LiteSpeed
(`litespeed_finish_request()`). Under Apache's `mod_php`, which has neither,
every request that changed something waits for the front-end's answer before
it answers the editor — up to the five-second timeout when the front-end is slow
or down. The revalidation is the same either way; only the editor waits.

## API functions

No function here can tell you whether the front-end has revalidated anything.
Each answers what the plugin took on: `nextjs_revalidate_path` and
`nextjs_revalidate_post` whether the change was **accepted** into the request's
pending changes, and
`nextjs_revalidate_schedule_path` whether the schedule was registered — which is
one step further away, since that change is only reported when the date time
passes, and can be refused then. Pending changes are sent to the front-end once
the request that produced them has answered, so there is no return value in
this plugin that could report the outcome of a delivery that has not happened
yet. Delivery is at most once — a revalidation that is attempted and fails is
written to the log and dropped, never retried.

Each takes a full URL or a path. A URL is reduced to its path from the domain
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

### nextjs_revalidate_post

Reports a post as changed, from the URI it had to the one it has now: a
**post** change, for a post whose permalink moved without the post being saved.
The case it is for is a permalink a theme builds from a term, through a
`post_type_link` filter — a term's change reports the term and never the posts
built from it, so the theme reads the permalink before the term changes and
reports it after. A
permalink built from another *post* is better named through
[`nextjs_revalidate_dependent_posts`](#nextjs_revalidate_dependent_posts), which
reads both sides itself.

The post is asked what every entry point asks — whether it is
[revalidated](#which-posts-are-revalidated) — and its `after` is its URI now.

#### Usage
```php
// A category's slug is part of its interviews' permalinks.
add_action( 'edit_terms', function( $term_id, $taxonomy ) {
	if ( 'interview_category' !== $taxonomy ) return;
	foreach ( my_theme_interviews_in( $term_id ) as $post_id ) {
		$GLOBALS['my_theme_before'][ $post_id ] = get_permalink( $post_id );
	}
}, 10, 2 );

// `edited_term`, not `edited_terms`: only by then has core cleared the term's
// cache, so the permalinks are built from the new slug.
add_action( 'edited_term', function( $term_id, $tt_id, $taxonomy ) {
	if ( 'interview_category' !== $taxonomy ) return;
	foreach ( $GLOBALS['my_theme_before'] ?? [] as $post_id => $before ) {
		nextjs_revalidate_post( $post_id, $before );
	}
}, 10, 3 );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| post_id | int | The post |
| before_url | string\|null | The URL, or the path, the post had. Optional: without it the post is reported as it stands, both sides its current URI — what the row action reports |

#### Returns

`bool` — whether the change was accepted into the pending changes. It is `false`
for a post that is not revalidated or has no page, for a `before_url` that names
no path, on a refusal, and when the `nextjs_revalidate_change` filter dropped it.

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
{ "subject": "post", "id": 42, "type": "post", "before": { "uri": "/hello/", "terms": [ { "id": 7, "taxonomy": "category", "slug": "video" } ] }, "after": { "uri": "/hello-world/", "terms": [ { "id": 7, "taxonomy": "category", "slug": "video" } ] } }
```

`uri` is the path from the domain root. A side is `null` when the post is not on
the front-end on that side — its status is not `publish` or `private` — so a
publish has no `before`, and a post that has left the front-end, or has been
deleted, has no `after`. An edit has two equal sides, a slug change two
different URIs, and the **Revalidate** row action, bulk action and admin bar
entry report the post as it stands, with both sides its current URI. A post
saved several times in one request is reported once, from the first `before` to
the last `after`. A revision stands for the post it belongs to, and an
attachment is never reported.

A headless site registering post types with `publicly_queryable => false` while
its front-end still renders their permalinks can say so with the filter below.

### Term membership

Each side of a post change lists the post's terms on that side — its terms in
the [revalidatable taxonomies](#which-terms-are-revalidated), each as
`{ id, taxonomy, slug }`, sorted by taxonomy then ID — so the front-end reaches
every archive the post is in, joined or left. A save that moves a post from
*Video* to *Podcast* lists *Video* in `before.terms` and *Podcast* in
`after.terms`; a title edit lists the same terms on both sides.

A post's terms written with **no save** — an import, a plugin, Polylang's
synchronisation, calling `wp_set_object_terms()`, `wp_add_object_terms()` or
`wp_remove_object_terms()` — are a change of that post too, reported where it
stands with the terms it had before the write and the ones it has after:

```json
{ "subject": "post", "id": 42, "type": "post", "before": { "uri": "/hello/", "terms": [ { "id": 7, "taxonomy": "category", "slug": "video" } ] }, "after": { "uri": "/hello/", "terms": [ { "id": 9, "taxonomy": "category", "slug": "podcast" } ] } }
```

Only a post with a page — a revalidatable post, `publish` or `private` — is
reported, and only when its terms in a revalidatable taxonomy changed: a draft's,
or a write that sets the terms a post already has, reports nothing. A post also
saved in the same request is one change, from its terms before the first to its
terms after the last. The one exception is a term's delete: the posts it moves
are not reported, since the deleted term's own change reaches whatever showed
it, and the default term's reaches the archive the posts moved into.

### Dependent posts

A post's page can move without the post being saved. A child page's permalink is
its parent's plus its own slug, so renaming or moving the parent moves every
descendant. Each post whose URI a save moved is reported as a `post` change of
its own, after the saved post's, with the URI it had before the save and the one
it has after:

```json
{ "subject": "post", "id": 42, "type": "page", "before": { "uri": "/about/", "terms": [] }, "after": { "uri": "/company/", "terms": [] } }
{ "subject": "post", "id": 43, "type": "page", "before": { "uri": "/about/team/", "terms": [] }, "after": { "uri": "/company/team/", "terms": [] } }
```

By default these are the descendants of a post of a hierarchical type whose
slug or parent the save changes; an edit reports the saved post alone. A post
whose URI, order and parent did not move is not reported; one whose order alone
moved is reported where it is, both sides its URI. A post whose permalink a theme builds
from another post is added with the
[`nextjs_revalidate_dependent_posts`](#nextjs_revalidate_dependent_posts) filter,
and one built from a term is reported with
[`nextjs_revalidate_post()`](#nextjs_revalidate_post). The
[Polylang](#polylang) integration adds a post's translations.

Permanently deleting a post asks the same question of the post as it stands just
before it is gone, and reports its URI with no `after`, so the front-end stops
serving a page for content that no longer exists. A post already in the trash is
not reported again: trashing it reported that page gone already, and the
front-end has had no reason to cache it since — so emptying the trash, by hand
or through WordPress's scheduled sweep, reports nothing. Deleting a revision
reports nothing either; the post it belongs to still has its page.

## Which post types the admin offers

The same viewability decides what this plugin *offers* for a post type: the
**Revalidate** bulk action on its list screen, its allow revalidate all switch
on the settings page, and its revalidate all entry in the admin bar. Attachments are
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
is, and does nothing: no revalidate all entry is offered for it. Saving the settings
page drops the stored row.

## Menus

Saving a classic menu (Appearance → Menus) reports one `menu` change, carrying
the menu's term ID and the theme locations it is assigned to — none, for a menu
assigned to no location.

A block menu — the `wp_navigation` post the Site Editor and the Navigation block
save — reports the same `menu` change, carrying its post ID and no locations,
when it is saved, trashed, restored from the trash or permanently deleted. An
auto-draft or a revision of one reports nothing. Block menus have a producer of
their own: `wp_navigation` is not viewable, and the post gate goes on declining
it, as it declines every other type that is not viewable.

Which pages a menu change affects is the front-end's to decide; nothing is
revalidated page by page, and there is no setting for it.

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

Creating, editing or deleting a term of a revalidatable taxonomy reports a
`term` change, carrying its slug and the URI of its archive before and after:

```json
{ "subject": "term", "id": 42, "taxonomy": "category", "before": { "slug": "video", "uri": "/category/video/" }, "after": { "slug": "videos", "uri": "/category/videos/" } }
```

A creation has no `before` and a delete no `after`. An edit that moves nothing —
the name, the description — has two equal sides and is reported all the same: the
front-end shows the name. A term edited twice in one request is reported once,
from the first `before` to the last `after`, and a term created and deleted in
the same request is not reported at all. Terms of a taxonomy that is not
revalidatable are never reported.

A term's change never reports the posts in it. Deleting a category with 5,000
posts is one change: the pages that show it carry its tag on the front-end. The
one addition is the taxonomy's **default term** — *Uncategorized* — reported
once, with equal sides, when the delete moved posts into it, because its
archive gained them.

### Dependent terms

A child term's archive URI is its parent's plus its own slug, so changing a
term's slug or parent moves every descendant's archive without editing any of
them, and so does deleting it: WordPress moves its children up a level. Each
descendant whose URI moved is reported as a `term` change of its own, after the
term's, from the URI it had to the one it has:

```json
{ "subject": "term", "id": 42, "taxonomy": "category", "before": { "slug": "media", "uri": "/category/media/" }, "after": { "slug": "press", "uri": "/category/press/" } }
{ "subject": "term", "id": 43, "taxonomy": "category", "before": { "slug": "video", "uri": "/category/media/video/" }, "after": { "slug": "video", "uri": "/category/press/video/" } }
```

An edit that moves nothing reports the edited term alone.

A post whose *permalink* contains a term — a `%category%` permalink structure,
or a theme's `post_type_link` filter — also moves when the term's slug changes,
and is not reported: reading every post of the term before the edit is the
enumeration a term's change avoids
([ADR 0040](docs/adr/0040-a-term-reports-itself-and-a-post-the-terms-it-is-in.md)).
Report such a post with [`nextjs_revalidate_post()`](#nextjs_revalidate_post).

**On a headless site, viewable is not the same as displayed.** A taxonomy
registered `public => false` and exposed in WPGraphQL is rendered by the
front-end all the same, and is never named: revalidate all of its post type
sends `"taxonomies": []`. Admit it with
[`nextjs_revalidate_should_revalidate_taxonomy`](#nextjs_revalidate_should_revalidate_taxonomy).
The same goes for a post type that is `show_in_graphql` but not
`publicly_queryable`: admit its posts with
[`nextjs_revalidate_should_revalidate_post`](#nextjs_revalidate_should_revalidate_post),
and offer it in the admin with core's `is_post_type_viewable` filter — see
[Which post types the admin offers](#which-post-types-the-admin-offers).

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
*Settings → Next.js Revalidate*; its full path is printed beneath that switch.
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

### Yoast SEO

With [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) active, its title
templates and schema defaults (`wpseo_titles`) and its social defaults
(`wpseo_social`) are [site settings](#site-settings): saving either reports a
`settings` change. They are added to the list through
[`nextjs_revalidate_site_setting_options`](#nextjs_revalidate_site_setting_options),
the same filter a site uses, so a site can remove them there too.

`wpseo` is **not** added: nothing a front-end renders lives there, and Yoast
writes it on its own — indexing progress, activation timestamps, notification
state — so watching it would rebuild every page from Yoast's background work. A
front-end that does read it adds it through the filter.

### Polylang

With [Polylang](https://wordpress.org/plugins/polylang/) active, its language
list and default language are [site settings](#site-settings). A `settings`
change is reported when:

- a language is **added, edited or deleted** — a language is a term of
  Polylang's `language` taxonomy, not an option, so no option list could catch
  it;
- the **default language** changes, from Polylang's Languages screen or by a
  write of the `polylang` option whose `default_lang` differs.

- a language's **string translations** are saved — the translated site title
  and tagline, and every string registered with Polylang. They are kept in the
  language's term meta rather than in an option.

Nothing else in the `polylang` option reports a change. `hide_default`,
`force_lang` and `rewrite` decide whether a language prefix is in the path at
all, which moves pages between paths rather than changing what every page
renders; `version` and the rest are Polylang's bookkeeping.

**A language is never reported as a term.** The `language` taxonomy is
publicly queryable, so it would otherwise be a
[revalidatable taxonomy](#which-terms-are-revalidated) like any other: adding a
language would report a `term` change next to its `settings` one, and every
translated post would list its language in `terms`, so every save would expire
whatever carries that language's term — a language switcher, or every page of
the language. The integration declines it through
[`nextjs_revalidate_should_revalidate_taxonomy`](#nextjs_revalidate_should_revalidate_taxonomy)
instead, which also leaves it out of the `taxonomies` revalidate all of a post
type names: what a language changes is reported as the `settings` change above.
A post's other terms — its categories and tags — are reported as on any site.
Polylang's other taxonomies, which store translation groups, are not publicly
queryable and were never revalidatable. A front-end that does tag pages with a
language's term can admit the taxonomy again from a later priority:

```php
add_filter( 'nextjs_revalidate_should_revalidate_taxonomy', function( $should_revalidate, $taxonomy_name ) {
	return 'language' === $taxonomy_name ? true : $should_revalidate;
}, 20, 2 );
```

With **synchronisation** on, Polylang copies what you chose — the parent, the
order, the date, custom fields, the featured image, terms — to a post's
translations with direct SQL and the meta and term APIs, and saves none of them:
editing the French page edits the German page too. So with anything
synchronised, every save of a post also reports its translations, each where it
stands, whether Polylang changed anything of theirs or not. A translation is also
one of the post's [dependent posts](#dependent-posts), so one whose URI moved is
reported from the URI it had — and when the save changes the parent, their
descendants are too. With nothing synchronised, a save reports its translations
only when one moved.

Per-language options — acf-options-for-polylang's, or a theme's own
`my_setting_{lang}` — cannot be listed ahead of time: add them to
[`nextjs_revalidate_site_setting_options`](#nextjs_revalidate_site_setting_options)
by the prefix they share, `my_setting_*`.

Both integrations are supported, never required: with the plugin absent, nothing
registers.

### Nested Pages and Simple Custom Post Order

[Nested Pages](https://wordpress.org/plugins/wp-nested-pages/) and
[Simple Custom Post Order](https://wordpress.org/plugins/simple-custom-post-order/)
reorder posts by drag and drop, and Nested Pages moves a page under another
parent the same way. Both write the posts table with direct SQL and save nothing,
so no save hook sees it. With either active, each post a drag and drop moved is
reported as a `post` change:

- **moved under another parent** in Nested Pages, from the URI it had to the one
  it has — its children too, since they are dragged with it;
- **reordered**, where it is, with both sides its current URI: nothing on the
  wire carries an order, but the listings of its type changed, and a `post`
  change is what tells the front-end about its type.

A post the drag and drop did not move is not reported. The posts are read from
the plugin's own request, before it handles it; whether the request is allowed
is still the plugin's question, and nothing is reported unless it goes on to
write. Simple Custom Post Order's "move to position" in the order column reports
the post it places, not the others it renumbers around it: the listings they are
in are its type's, which its change covers. Nor is the renumbering Simple Custom
Post Order does as its list screen renders: it keeps every post where it was.
With Nested Pages' "update post hook" setting on, the `wp_update_post()` it runs
before its own write reports the page where it was, and the sort carries it to
where it is: one change.

Ticking or unticking a post type in Settings → SCPOrder — "Reset order" among
them, which unticks the types it resets — puts every listing of that type in
another order, and reports it whole, as an `all` change of that type.

The request body each plugin sends is read here, not declared by the plugin. If
a plugin update changes it, what the plugin's own action still says is reported,
and the plugin's log file says why, with **Enable logs** on under the **Debug**
tab: Nested Pages' pages each where it stands, so a page it moved keeps its old
URI cached; Simple Custom Post Order's types each whole.

Both are supported, never required: with the plugin absent, nothing registers.

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
Its verdict covers a term's own `term` change, the post `terms` the taxonomy's
terms appear in, and the `taxonomies` revalidate all of a post type names.
The [Polylang](#polylang) integration hooks it at the default priority to
decline Polylang's `language` taxonomy.

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

### nextjs_revalidate_dependent_posts

Filters the posts a post's update may move without saving them: the posts whose
permalink is built from its own. Each is reported after the update when its URI,
order or parent moved, and left alone when none did — see
[Dependent posts](#dependent-posts).

The IDs it starts with are the post's descendants, when it is of a hierarchical
type and the update changes its slug or its parent, and none otherwise. Asked on
every update, before the post is written, so a callback that names posts only
when what their permalinks are built from changes costs nothing on the other
saves.

#### Usage
```php
// A feature lives under its linked page: /features/a-feature/.
add_filter( 'nextjs_revalidate_dependent_posts', function( $post_ids, $post_id, $post_before, $data ) {
	if ( 'page' !== $post_before->post_type || $data['post_name'] === $post_before->post_name ) return $post_ids;
	return array_merge( $post_ids, get_posts( [
		'post_type'  => 'feature',
		'meta_key'   => 'linked_page',
		'meta_value' => $post_id,
		'fields'     => 'ids',
		'numberposts' => -1,
	] ) );
}, 10, 4 );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| post_ids | int[] | The dependent post IDs |
| post_id | int | The post about to be updated |
| post_before | WP_Post | The post as it is before the update |
| data | array | The post's fields as they are about to be written |

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

### nextjs_revalidate_site_setting_options

Filters which options are [site settings](#site-settings): values the front-end
renders as they are, on any page, whose change — added, updated to a different
value, or deleted — reports a `settings` change. Read every time an option
changes, so a filter added late still counts.

Add an option your front-end renders, or remove a default it does not. An entry
ending in `*` names every option starting with what comes before it, for options
stored once per language; a bare `*` names none. Do not add one that moves which
content lives at which path, such as `permalink_structure`: expiring what every
page carries does not fix what that leaves stale.

#### Usage
```php
add_filter( 'nextjs_revalidate_site_setting_options', function( $option_names ) {
	$option_names[] = 'my_theme_footer_text';
	$option_names[] = 'landbot_config_url_*'; // landbot_config_url_fr, landbot_config_url_de…
	return array_diff( $option_names, [ 'time_format' ] );
} );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| option_names | string[] | The site setting options: WordPress's defaults, and those the active integrations add. An entry ending in `*` is a prefix |

### nextjs_revalidate_purge_action_permalink

**Retired in 2.0.0, and no longer applied.** It filtered the permalink 1.x's
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
event, which changes are **pending** — a post's save or delete, a redirect
edited, a path reported through the public API?

From a fresh checkout:

```bash
npm install
composer install
npm run test:integration
```

wp-env installs the Redirection, Yoast SEO and Polylang plugins alongside this
one, on the development site and on the test site, so the integrations can be
exercised without assembling an install by hand. The suite's bootstrap loads each
of them when it is there — and creates Redirection's tables — and skips the tests
that need one when it is not. The two
sites are two config files, `.wp-env.json` and `.wp-env.tests.json`, and wp-env
has no way for one to extend the other: a plugin added to one has to be added to
both.

Neither config pins a version of any of them, so the suite runs against whatever
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

Write a test by extending `NextJsRevalidate\Tests\PendingChangesTestCase`, which
configures the site and reads what the request holds before anything is
delivered:

```php
$this->configure_site();                             // an unconfigured site refuses
nextjs_revalidate_path( home_url( '/hello-world/' ) );
$this->assertPendingChanges( [ Change::path( '/hello-world/' ) ] ); // in order
$this->assertNoPendingChanges();                     // or none at all
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
