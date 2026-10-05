# A save reports the posts it moves, and a direct write the posts it wrote

Decided while implementing #180, for v2.0, from gaps found porting a site's
front-end to Cache Components.

A `post` change carries the post as the front-end saw it before and as it sees
it after (ADR 0033), and the only producer of one was that post's own save. But
a post's page can move, or its listings reorder, without the post being saved:

- **A child page's permalink is its parent's**, plus its own slug. Renaming or
  moving the parent moves every descendant, and saves none of them.
- **A theme can build a permalink from another post or a term**, through a
  `post_type_link` filter — a site can put a post under its category's linked
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
- **Polylang's synchronised translations are reported on every save** when the
  site synchronises anything, each where it stands — or from the URI it had,
  when it moved. Polylang copies more than the parent and the order: the date,
  custom fields, the featured image, terms. Any of them can change the
  translation's page or its place in a listing, and none of them is a save.
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

The request's shape is the plugin's, not declared by it. **Should it stop being
read, what the plugin's action still says is reported, and the log says why.**
Nested Pages' action names each level's posts, and each is reported where it
stands. Simple Custom Post Order's names none, so each type it orders is reported
whole, as an `all` change. Which types it orders is its setting, and a type
added to it or taken out of it — its "reset order" among them — is reported
whole too: every listing of that type is in another order.

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

**Compare each field Polylang synchronises, and report a translation only when
one differs.** Precise, but a snapshot of the date, every custom field, the
featured image and the terms on every save, re-deriving what Polylang's options
mean. A site has a handful of languages; reporting them all costs less.

**Leave Simple Custom Post Order's fallback to its integration tests.** They
drive the real handler and would fail on a plugin update. Rejected because a site
updates the plugin before this one catches up, and its listings would go stale
with nothing said.

## Consequences

- A rename of a parent page with many descendants reports as many changes, sent
  in requests of up to 100 each (ADR 0034).
- The dependent posts' `before` costs a permalink per candidate on every update
  that names any — none, for an edit of a post with no translations and no
  theme callback.
- With synchronisation on, every save of a translated post also reports its
  translations, whether Polylang changed anything of theirs or not.
- If Nested Pages' request changes, a page it moved loses its old URI: its
  listings are still told, and the log says so. If Simple Custom Post Order's
  does, each type it orders is expired whole.
- Simple Custom Post Order's `refresh()`, which renumbers a type as its list
  screen renders, is not reported: it closes the gaps and keeps every post where
  it was.
