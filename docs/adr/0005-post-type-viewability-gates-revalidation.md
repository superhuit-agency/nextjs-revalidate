# Post type viewability gates revalidation, and only a site filter may override it

`should_revalidate()` decided whether to enqueue a revalidation with
`is_post_publicly_viewable()`, which ANDs two independent axes: the post's *type*
is viewable (`publicly_queryable || (_builtin && public)`) and its *status* is
viewable. Core's status test rejects `private`, so the plugin added a
`!in_array($status, ['publish','private'])` escape hatch to re-admit private
posts — the behaviour its own docblock describes wanting. But the hatch was ORed
against the *already-collapsed* predicate, so it re-admitted anything with a
publish status, including posts of a type that was never viewable at all. A
non-queryable post type had every one of its published posts revalidated.

The two axes are now checked separately. Type viewability is decided by
`is_post_type_viewable()` and gates everything, including the unpublish
carve-out; the `publish|private` rule is a status-axis exception and can no
longer speak for the type axis. This is what **Revalidatable post** in
`CONTEXT.md` names.

The one thing that can still override the type axis is the existing
`nextjs_revalidate_purge_should_revalidate_post_on_save` filter, which is applied
last and can force `true`. That is deliberate, and it is the part a future reader
is most likely to try to "tighten".

## Considered Options

**Make the type check an absolute early return, ahead of the filter.** The
strongest invariant and the obvious reading of the bug report. Rejected because
`publicly_queryable` governs *WordPress's* front-end routing — whether core will
resolve a query var to that type — and this plugin exists to serve installs where
WordPress does no front-end routing at all. A headless site can quite reasonably
register a type with `publicly_queryable => false` while `get_permalink()` still
yields a path that Next.js renders. For those sites an absolute check silently
stops revalidations that were working, with no notice and no log line. The filter
is the documented way such a site says "I know better", and it already exists.

**Reading `$post_type_object->publicly_queryable` directly**, per the issue's
wording. Rejected as a gratuitous divergence from core: for `_builtin` types core
also accepts `public`, and matching `is_post_type_viewable()` means the plugin
agrees with every other viewability decision WordPress makes — including the
`is_post_type_viewable` filter, which gives sites a second, site-wide lever.

**Widening the glossary's refusal to cover this.** Rejected because a refusal is
a revalidation declined as *undeliverable*, a meaning ADR 0004 and #37 both lean
on. A post with no front-end page is not undeliverable; it is not a candidate.
The positive term earns its place instead.

## Consequences

Sites relying on the old behaviour lose revalidations on upgrade. The failure is
silent — a page quietly stops updating — and the only remedy is the filter, which
an operator has to already know about. This is the accepted cost of the fix, but
it is the reason the filter override survives at all.

Tightening the type axis makes `RevalidateAll` pass `false` into
`RevalidateQueue::add_item()` for the posts now excluded, which has no guard and
would insert rows with an empty permalink. The guard the row and bulk actions
already apply is extended to that caller as part of the same change.

The carve-out for a post leaving publish moves inside `should_revalidate()` so
the filter observes the final answer. Previously it sat in the `wp_after_insert_post`
handler and overrode the filter's verdict after the fact — a post the filter had
explicitly declined would still be revalidated on unpublish.

Three places still select post types with `get_post_types(['public' => true])`
rather than by viewability, and one selects taxonomies the same way. They no
longer decide whether anything is *enqueued* — `should_revalidate()` is the only
gate — but they still offer bulk actions and settings toggles for types that will
now decline every post. That inconsistency is left open here and tracked
separately.
