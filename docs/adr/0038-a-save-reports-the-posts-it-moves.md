# A save reports the posts it moves, and a direct write the posts it wrote

Decided while implementing #180, for v2.0, from gaps found porting tipee.ch's
front-end to Cache Components.

A `post` change carries the post as the front-end saw it before and as it sees
it after (ADR 0033), and the only producer of one was that post's own save. But
a post's page can move, or its listings reorder, without the post being saved:

- **A child page's permalink is its parent's**, plus its own slug. Renaming or
  moving the parent moves every descendant, and saves none of them.
- **A theme can build a permalink from another post or a term**, through a
  `post_type_link` filter — tipee.ch puts a feature under its category's linked
  page.
- **Polylang synchronises translations with `$wpdb->update()`**, on purpose and
  without `wp_update_post()`: moving the French page under another parent moves
  the German page too.
- **Nested Pages and Simple Custom Post Order** reorder, and Nested Pages
  reparents, with direct SQL.

In each case the front-end went on serving the old page, at the old URI, until
somebody ran revalidate all.

## The decision

**A post whose page a save moves is a dependent post of that save, and is
reported as a `post` change of its own**, from the URI it had to the one it has —
only when its URI, its order or its parent moved, the question a reorder is asked
below. Its `before` is read on `pre_post_update`, the last
moment the saved post's row still holds what it held: WordPress writes the row
and clears its cache before any hook that follows the write, and a child's
permalink is read off its parent's row. Its `after` is read when the saved
post's own `wp_after_insert_post` ends the save, and the change is reported
after the saved post's own.

- **The default is the descendants**, of a post of a hierarchical type whose
  slug or parent the update changes. An edit moves nothing, and a parent page's
  edit does not walk its tree.
- **`nextjs_revalidate_dependent_posts` adds the rest.** It is asked on every
  update, with the post before and the data it is saved with, so a theme can name
  the posts its permalinks build from this one. The Polylang integration adds a
  post's translations there, and their descendants when the parent changes.
- **A permalink built from a term** has no post save to hang on, and term
  changes are not yet reported at all (#55). `nextjs_revalidate_post( $post_id,
  $before_url )` reports one from the URI it had: the theme reads the permalink
  before the term changes and reports it after.

**A plugin writing posts with direct SQL is an integration that reads the posts
before the plugin handles the request, and reports those it wrote after.** Its
own action says a write happened, but not what each post was before it, so the
integration reads the posts the request names at priority 1 of the plugin's own
AJAX action — reading only, since whether the request is allowed is the plugin's
question — and reports each post whose URI, order or parent moved once the
plugin's action fires. A reordered post whose URI did not move is reported on
both sides where it is: nothing on the wire carries an order, but the listings of
its type changed, and a `post` change is what tells the front-end about its type.

## Considered Options

**Report every descendant on every save of a parent.** Simpler, and it cannot
miss a move. Rejected because most saves are edits, and each would rebuild the
tree.

**Report a reorder as one `all` change of the type.** The issue proposed it, and
it is one change however many posts moved. Rejected because an `all` change of a
type expires every page of it and its term archives, where the posts that moved
say exactly what changed, and merge into one change each.

**Hook Nested Pages' per-post action.** It fires with each post, but before
Nested Pages clears that post's cache, so the URI read there is the old one. Its
action for each level of the tree fires after the level is written and cleared.

**Read the `before` of a dependent post from the saved post's `$post_before`.**
Only the saved post has one; a descendant's old URI can be recomposed from the
parent's old slug only while every permalink is `get_page_uri()`, which a theme's
filter breaks.

**Make "exposed in GraphQL" the viewability test for a headless site.** The
issue raised it for revalidate all's taxonomies. Not decided here: ADRs 0022 and
0025 chose viewability for reasons that still hold, and
`nextjs_revalidate_should_revalidate_taxonomy` and `is_post_type_viewable` are
the documented way for a headless site to say otherwise.

## Consequences

- A rename of a parent page with many descendants reports as many changes, sent
  in requests of up to 100 each (ADR 0034).
- The dependent posts' `before` costs a permalink per candidate on every update
  that names any — none, for an edit of a post with no translations and no
  theme callback.
- A synchronised order is reported, where the translation is, as a reorder is.
  A synchronised date that moves no URI is not: nothing but the URI, the order
  and the parent is compared.
- Nested Pages' and Simple Custom Post Order's request shapes are read, not
  declared by them. If one changes, the integration falls back to reporting each
  post the plugin's action names as it stands — the listings are still told, and
  a moved page loses its old URI.
