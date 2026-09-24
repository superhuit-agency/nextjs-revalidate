# The plugin reports changes as WordPress sees them, and never names the front-end's cache tags

Decided while grilling #81, for v2.

Until v2 this plugin asked the front-end to revalidate a **path**:
`GET {endpoint}?path=/hello-world/&secret=…`. Next.js 16's Cache Components move
invalidation from paths to tags — `cacheTag()` declares what a cache entry
depends on, `revalidateTag( tag, 'max' )` announces what changed — and a path is
the wrong identity under that model: a slug change leaves the old path cached,
an archive listing a post is a separate entry from the post's page, and a
rewritten path has to be reverse-engineered by the route.

v2 sends a **change**: a description of what happened to one WordPress subject,
in WordPress's terms. Which cache tags that change expires is the front-end's
decision, never this plugin's. `CONTEXT.md` defines **change** and redefines
**revalidation** around it.

v2 is a **port, not an expansion**: every change it reports corresponds to
something v1 already revalidated. Nothing here adds a reaction v1 did not have.

## The contract

```http
POST {revalidate domain}{endpoint path}
Authorization: Bearer <secret>
Content-Type: application/json

{ "version": 2, "changes": [ … ] }
```

Six subjects in v2.0:

| Subject | Shape | Produced by |
| --- | --- | --- |
| `post` | `{ id, type, before: { uri } \| null, after: { uri } \| null }` | a save, a status change, a delete, the row, bulk and admin bar actions |
| `redirect` | `{ uri }` | the Redirection integration, one change per affected source path |
| `path` | `{ uri }` | the public API, the inbound REST routes, a scheduled purge, the probe |
| `menu` | `{ id, locations }` | a menu save |
| `templates` | `{}` | an FSE template or template part saved or deleted, a theme switched |
| `all` | `{}`, or `{ type, taxonomies }` | revalidate all, of the whole site or of one post type |

`uri` is always the path from the domain root — what v1 sent as `path`, and what
WPGraphQL calls `uri`.

A **post**'s `before` and `after` describe it *as the front-end sees it*, and
`null` means it is not there: `before` is `null` for a publish, `after` for a
post that left the front-end — unpublished, trashed or deleted alike, since the
page is gone either way. A change with both sides `null` cannot exist; a draft
saved as a draft was never a candidate. The front-end compares the two sides
rather than being told an event name: an edit, a slug change, a publish and an
unpublish all fall out of the comparison, and no front-end has to learn what
`future` or `pending` mean.

**Two rules make the contract extensible** and are part of it from v2.0:

1. **A front-end ignores what it does not recognise** — a subject, or a field on
   a known subject. Adding either is therefore not a breaking change, and ships
   in a minor release.
2. **`version` is raised only for a breaking change** — a field removed,
   renamed, or its meaning changed. A front-end answering a version it does not
   know with an error is recorded as a **failure**, so the mismatch reaches the
   degraded notice instead of expiring the wrong things in silence.

## Considered Options

**Send tags.** `{ "tags": ["node:123", "type:post", "uris"] }` from a built-in
naming scheme, with a PHP filter for sites whose scheme differs. The front-end
route becomes a loop. Rejected because whether a post save should expire
`type:post` depends on how that app caches, not on what happened in WordPress —
Superstack's own tag table says a single post page must *not* carry
`type:post`. A plugin sending tags has to know every front-end's scheme, and
every site whose scheme differs undoes the defaults in PHP. A new tag on the
front-end would become a plugin release.

**An event enum on the post** — `save | trash | untrash | delete |
status_change`, as #81 first proposed, with a `slugChanged` flag. Rejected for
before/after: an enum makes every front-end map WordPress's status machine onto
"is the page still there", and a flag loses the old URI an app still keying some
entries by path needs.

**Before/after on a redirect**, to match the post. Rejected because Redirection
stopped passing the previous rule on update in 5.9 (ADR 0014, amended): `before`
would often be *unknown* rather than *absent*, and `null` cannot say which. One
`uri` per affected source path is honest about what is known.

**Folding redirects into `path`.** Rejected because "the redirect at `/old/`
changed" and "someone named `/old/`" are different facts to a front-end that
caches redirects separately from pages.

**A `term` subject in v2.0.** Nothing in v2.0 would produce one: terms reach the
front-end only through revalidate all, which reports an `all` change whose
`taxonomies` list already covers term archives. A documented shape nothing sends
is a guess, so `term` is defined with #55, where the term lifecycle and a post's
terms are designed together. Rule 1 makes that addition non-breaking.

**Naming which template changed.** Rejected for v2.0: v1 does not, the front-end
caches the snapshot as one value, and it is not one value to name — a theme
switch changes every template, and a site-editor save can change several.
Additive later, under rule 1.

**Carrying the post's terms.** Only the terms *after* the save are available on
`wp_after_insert_post`; a post leaving "Video" for "Podcast" would name Podcast
and silently miss Video. Deferred to #55, which captures the old terms on
`set_object_terms`.

## Consequences

**A front-end must write a mapping** from changes to its tags, where a tag
contract would have given it a loop. The README carries the contract and a
reference route handler that maps every subject onto an example scheme; there is
deliberately no npm package, which would be a second artifact to release in
lockstep.

**The plugin never learns a tag name**, so a front-end can reshape its caching
without a plugin release, and two sites with different schemes run the same
plugin unmodified.

**A slug change now reaches the old path.** v1 revalidated only the new
permalink on a rename; `before.uri` hands the front-end the old one too.

**Revalidate all reports one change instead of every permalink**, and keeps its
per-post-type scope: `type` and the **revalidatable taxonomies** registered for
it, which only WordPress knows.

**Two settings disappear.** A menu save reports one `menu` change, so the
per-post-type **revalidate on menu save** setting, which existed only to bound
the cost of a revalidate-all per menu save, has nothing left to bound. The FSE
snapshot's own endpoint path and its on/off switch go with the separate endpoint
(ADR 0034).
