# Taxonomy viewability gates term revalidation, and revalidate-all selects no taxonomy for itself

Decided while fixing #54, split out of #53, itself split out of #25.

[ADR 0005](0005-post-type-viewability-gates-revalidation.md) made viewability the
rule for whether a *post* is revalidated, and left four selection sites still
asking for `public`. It called all four cosmetic, and for three of them it was
right: the on-save gate catches whatever they over-offer, so nothing wrong is
ever enqueued. The fourth is the taxonomy call in revalidate-all, and 0005 was
wrong about that one for a reason it had no way to see from the post side —
**terms had no gate downstream at all**, so that selector was not offering a
menu, it *was* the decision.

`is_taxonomy_viewable()` is a bare `return $taxonomy->publicly_queryable`, with
none of the `_builtin && public` fallback `is_post_type_viewable()` applies.
`publicly_queryable` defaults to `public` but is independently settable, so the
old selector could disagree with viewability completely, in both directions, and
both were wrong:

- **`public => true, publicly_queryable => false`** — every term enqueued,
  ungated. The over-revalidation ADR 0005 fixed for posts.
- **`public => false, publicly_queryable => true`** — a taxonomy with a real
  front-end archive, absent from revalidate-all entirely. The quieter failure:
  nothing enqueued, nothing logged, the archive simply never updates.

No core taxonomy changes sides. `category`, `post_tag` and `post_format` are
viewable and selected before and after; `nav_menu`, `link_category`, `wp_theme`,
`wp_template_part_area` and `wp_pattern_category` are neither. Every divergence
comes from a registration that deliberately splits the two flags.

## The decision

**The gate is over the taxonomy, not over the term.**
`Revalidate::should_revalidate_taxonomy()` takes a taxonomy or its name and
answers "may this plugin revalidate this taxonomy's terms?". It is one axis
rather than the two a post has, because a term has no status — and core offers no
second axis either: `is_term_publicly_viewable()` is, in full, a term-existence
check plus `is_taxonomy_viewable( $term->taxonomy )`. A per-term gate would
return an identical answer for every term in a taxonomy, and would cost a call
per term across potentially tens of thousands of them to re-derive a constant.
This is what **Revalidatable taxonomy** in `CONTEXT.md` names.

It is a gate and not merely a selector, for the reason 0005 gives: #55 will add
term lifecycle hooks and every one of them needs this same decision. A rule
enforced at call sites rather than at one gate is the shape of the bug #25 fixed.

**The site has the last word, through a filter of its own.**
`nextjs_revalidate_should_revalidate_taxonomy` is applied after the axis, last,
and receives `( bool $should_revalidate, string $taxonomy_name, WP_Taxonomy|false
$taxonomy )`. It can force either verdict.

The hatch exists for the reason ADR 0005 gives, restated here rather than
referred to, because it is the part of this decision a future reader is most
likely to try to "tighten": `publicly_queryable` governs *WordPress's* front-end
routing, and this plugin serves installs where WordPress does no front-end
routing at all. A headless site may quite reasonably register a taxonomy
`publicly_queryable => false` whose term archives Next.js renders perfectly well.
**The filter's ability to force `true` is deliberate.** Anyone who narrows it to
"may only decline" has broken the headless case this plugin exists for.

It is a sibling rather than a widening of
`nextjs_revalidate_purge_should_revalidate_post_on_save`: that name ends in
`_on_save`, it is documented against a post ID, and sites already hook it. The
`purge_` segment two older filter names carry is vestigial and the newest filter,
`nextjs_revalidate_should_revalidate_redirect`, already dropped it; it is not
reintroduced here.

**Revalidate-all selects every registered taxonomy and asks the gate.** It no
longer passes `'public' => true`, and it does not pre-filter by viewability
either. Both directions are fixed by that one edit.

This is the decision most likely to be "optimised" back, so: a pre-filter is not
an optimisation, it is a second authority deciding one question, and it makes the
gate a liar. A site could hook the filter to force `true` and still get nothing,
because the selector removed the taxonomy before the gate ran — and the operator
has no way to diagnose that, because there is nothing to see. A filterable gate
that an unfilterable selector can pre-empt is worse than no gate. Narrowing the
selector back to viewability would restore the under-revalidation bug silently
for every site that uses the filter.

## Considered Options

**Keep `[ 'public' => true ]` and narrow it to viewability.** The smallest edit
that closes the issue as titled, and it does fix both directions for a site that
does not filter. Rejected for the reason above: it is the two-authorities
structure ADR 0005 dismantled on the post side, and it silently defeats the
filter this same decision adds.

**A per-term gate, on `is_term_publicly_viewable()`.** Symmetrical with
`should_revalidate()` on the post side, and wrong twice over: it re-derives a
per-taxonomy constant once per term, and it raises the version floor to 6.1
(where `is_term_publicly_viewable()` arrived) for an answer 5.1's
`is_taxonomy_viewable()` already gives. The floor matters — the plugin header
claims 5.0 and is already wrong, but wrong by less.

**Extend the post filter to terms** rather than adding a second name. Rejected:
its `$post_id` argument has a documented meaning, sites filter on it with
`get_post_type()`, and passing a taxonomy through it would silently change what
every existing implementation of that filter is answering about.

**Reading `$taxonomy->publicly_queryable` directly**, or guarding
`is_taxonomy_viewable()` with `function_exists()`. Rejected for the reason ADR
0005 gives for post types: matching core's own function keeps the plugin agreeing
with every other viewability decision WordPress makes. `is_taxonomy_viewable()`
is `@since 5.1` against a declared floor of 5.0, which is safe in fact — the
plugin's primary hook is `wp_after_insert_post`, `@since 5.6`, so any install
where this plugin does anything at all is well past 5.1. A fallback would make
the taxonomy path work on 5.1–5.5 installs where the post path does not,
manufacturing a half-working state nobody tests. The header mismatch is real and
is filed separately as #122; it is not fixed here.

## Consequences

**Sites gain revalidations they did not get before.** A site holding a
`public => false, publicly_queryable => true` taxonomy will see its term archives
in a purge-all for the first time. That is the intended fix of the
under-revalidation direction, and it is the forgiving failure mode: it costs
revalidate-all some duration, not correctness.

**The filter is now load-bearing for an entire taxonomy.** Because nothing
pre-selects, a site can admit a taxonomy WordPress would never route, and every
one of its terms is enqueued. That is the point. A site that wants the opposite
declines the taxonomy and no term of it is read at all.

**The admin-flavoured term url is a non-hazard, and must not be coded against.**
An earlier reading of #54 worried that a taxonomy's `query_var` is forced `false`
only when `! is_admin()`, so revalidate-all — always an admin request — would
compute `?my_query_var=slug` where the front end computes `?taxonomy=X&term=Y`,
and enqueue the admin-flavoured url.

Traced against core: it cannot happen once the gate is in place.
`WP_Taxonomy::set_props()` keeps `query_var` when
`is_admin() || false !== $publicly_queryable`, so the divergence exists only for
non-`publicly_queryable` taxonomies — exactly the ones the gate excludes. The
permastruct branch does not reintroduce it either:
`WP_Rewrite::get_extra_permastruct()` returns `false` whenever
`permalink_structure` is empty, branching on the option rather than on
`is_admin()`, so a site without pretty permalinks takes the query-var path in
both contexts. For every taxonomy that survives the gate, the admin-computed and
front-end-computed urls agree.

The one case that reopens it is a taxonomy the *filter* admits against its
`publicly_queryable => false` — where the site has told us it knows better about
its own front-end, which is the whole premise of the hatch. No context detection,
no `switch_to_blog`-style trickery, no front-end url dance: there is nothing to
handle, and the ceremony would be permanent.

**`get_term_link()` handling, the queue and the drain are untouched.** The term
loop still enqueues `get_term_link( $term_id )` exactly as it did; only which
taxonomies it walks has changed.

**Counting is unchanged in shape but not in value.** `revalidate_all()` still
returns the number of items it queued, and the "Purge all: N pages added to
purge" notice still reports it; N moves on any site whose two settings disagreed.
