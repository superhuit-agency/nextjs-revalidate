# A term reports itself, and a post the terms it is in

Decided while grilling #55, for v2.1, after v2.0's contract landed on `main`.

v2.0 reports no term at all (ADR 0038 left it to #55). Renaming a category, or
moving a post from one category to another, leaves every term archive involved
stale until somebody runs revalidate all. Even a publish misses them: a `post`
change names the post's type, and nothing names the archives the post appears in.

Two facts are involved, and they belong to different subjects: **a term changed**
(created, renamed, re-slugged, moved, deleted), and **a post's terms changed** (it
joined *Podcast* and left *Video*). Neither is allowed to grow with the number of
posts in a term.

## The decision

**A term's own lifecycle is a `term` change**, with the same sides as a `post`
change:

```json
{ "subject": "term", "id": 42, "taxonomy": "category",
  "before": { "slug": "video",  "uri": "/category/video/" },
  "after":  { "slug": "videos", "uri": "/category/videos/" } }
```

- `before` is `null` on create, and `after` is `null` on delete. An edit that
  changes no URI (the name, the description) has equal sides.
- **`slug` is carried** because a common tag scheme is `term:{taxonomy}:{slug}`.
  After a slug change, the cached entries still carry the old slug, so the
  front-end needs `before.slug` to reach them.
- Only terms of a **revalidatable taxonomy** (ADR 0022) are reported.
- **Dependent terms** are reported as `term` changes of their own: the
  descendants of a term in a hierarchical taxonomy whose slug or parent changes,
  or which is deleted. This is ADR 0038's dependent post rule, applied to terms.

**A term's change never carries its members, and never reports them.** Whatever
shows a term (its archive, a badge on a post's page, a term list) is the
front-end's to tag with that term, so one change reaches it all. Deleting a term
with 5,000 posts is one change. `wp_delete_term()` moves each of those posts
with `wp_set_object_terms()` or `wp_remove_object_terms()`, and those writes are
a consequence of the delete, not changes of their own. The single addition is
**the taxonomy's default term**, reported once with equal sides when the delete
moved posts into it. Its archive gained those posts, and nothing that shows the
deleted term reaches it.

**A post's term membership is part of where it is on the front-end, so it is on
both sides of every `post` change.** Each side that exists carries `terms`: the
post's terms in revalidatable taxonomies, as a flat list of
`{ "id", "taxonomy", "slug" }`, sorted by taxonomy then ID, and `[]` when there
are none.

```json
"before": { "uri": "/hello/", "terms": [ { "id": 7, "taxonomy": "category", "slug": "video" } ] },
"after":  { "uri": "/hello/", "terms": [ { "id": 9, "taxonomy": "category", "slug": "podcast" } ] }
```

The front-end expires the archive of every term on either side. A publish
reaches the archives the post joined, and an unpublish, a trash or a delete the
archives it left, with no rule of their own. Pending changes already merge a
post's changes into the first `before` and the last `after`, so a post saved
twice in a request keeps its terms right without a new rule.

**A post's terms written with no save are still a change of that post**: an
import, a plugin or Polylang calling `wp_set_object_terms()`,
`wp_add_object_terms()` or `wp_remove_object_terms()`. It is reported as a `post`
change with equal URIs and the terms before and after, merged into the save's
change when there is one. The only exception is the per-post loop inside
`wp_delete_term()`, as above.

The reference route gains a `term:{id}` tag, carried by a term's archive and by
everything that shows the term. A `term` change expires it, plus `uris` when the
URI moved. A `post` change also expires it for every term on either side.
`type:{taxonomy}` stays what revalidate all of a type expires.

Both additions are new subjects and fields, so they ship in a minor release with
`version` unchanged (the contract's rule 1).

## Considered options

**Report membership changes only (`terms` only when they changed).** Lighter on
the wire, but it needs three special cases to stay correct:
- A publish keeps the terms the draft already had, so nothing "changed", and the
  archives never hear of the new post. Unpublish, trash and delete have the same
  problem in reverse.
- A post saved twice in one request (the terms swapped, then the title edited)
  merges into a change whose `after` lacks `terms`, so the archive it joined is
  lost.
- A title edit never reaches the archives that show the title.

**Report membership as `term` changes with equal sides.** A bulk reassignment
collapses into one change per term. Rejected because the front-end could no
longer tell "this term was renamed" from "a post joined it": every page showing
the term would be rebuilt whenever any post joined or left it.

**Report every post of a deleted term, with its terms before and after.**
Consistent with the decision above, and 5,000 changes for one delete. Rejected:
the delete is the change, and the posts' pages already carry the deleted term's
tag.

## Consequences

- Every `post` change costs a term lookup on each side, cached by WordPress, and
  carries a few more fields.
- Every edit of a post expires the archives it is in, so their titles and
  excerpts stay current. A front-end that does not want that can ignore `terms`
  when the two sides are equal.
- A front-end that does not tag the pages showing a term with that term misses
  them on a rename or a delete. The README contract says so.
- **Not covered:** a post whose permalink contains a term (`%category%`, or a
  theme's `post_type_link` filter) moves when the term's slug changes, and is not
  reported. Reading every member's permalink before the edit is exactly the
  enumeration this decision avoids. Until that is decided on its own,
  `nextjs_revalidate_post( $post_id, $before_url )` remains the way a theme
  reports one (ADR 0038).
