# Term viewability gates revalidation, and revalidate-all selects taxonomies by it

Decided while fixing #54, split out of #53, itself split out of #25.

Revalidate-all selected taxonomies with `get_taxonomies([ 'public' => true ])`
and enqueued `get_term_link()` for every term of each, with no viewability check
anywhere on that path. [ADR 0005](0005-post-type-viewability-gates-revalidation.md)
left that line open explicitly, as one of four selection sites still asking for
`public`; the other three over-offer and are caught downstream by
`should_revalidate()`, which is why they are cosmetic and this one is not. Terms
had no gate at all, so the selector *was* the decision.

`is_taxonomy_viewable()` is a bare `return $taxonomy->publicly_queryable`, with
none of the `_builtin && public` fallback `is_post_type_viewable()` applies. The
two settings are independent, so the old selector could disagree with viewability
in both directions, and both were wrong:

- **`public => true, publicly_queryable => false`** — every term enqueued,
  ungated. The over-revalidation ADR 0005 fixed for posts.
- **`public => false, publicly_queryable => true`** — a taxonomy with a real
  front-end archive, absent from revalidate-all entirely. The quieter failure:
  nothing enqueued, nothing logged, the archive simply never updates.

## The decision

**Terms get a gate of their own, on the model of the post one.**
`Revalidate::should_revalidate_term()` is one axis rather than two — a term has
no status — and asks core's `is_term_publicly_viewable()`, which is
`is_taxonomy_viewable()` of the term's taxonomy plus the term existing.
`Revalidate::get_term_permalink()` is its `get_post_permalink()`: the gate, then
`get_term_link()`, then `false` for a `WP_Error` or an empty url rather than a
queue row nothing could ever revalidate. This is what **Revalidatable term** in
`CONTEXT.md` names.

**The site has the last word, through a filter of its own.**
`nextjs_revalidate_should_revalidate_term` is applied after the axis and can
admit or decline any term. The headless argument ADR 0005 made for
`nextjs_revalidate_purge_should_revalidate_post_on_save` applies unchanged:
`publicly_queryable` governs *WordPress's* routing, and a headless install does
none of it, so a site may quite reasonably register a taxonomy WordPress will not
route while Next.js renders its archive.

It is a sibling rather than a widening of the post filter, and it is named for
the question rather than for an event: there is no term lifecycle hook in this
plugin yet — ordinary term editing revalidates nothing, which is its own
enhancement — so `…_on_save` would have been a lie on the only path that asks
today. It follows `nextjs_revalidate_should_revalidate_redirect`, the newer of
the two naming habits in this repo.

**Both directions are fixed in the same change.** Switching the selector to
viewability is one edit that closes both, and shipping half of it would leave the
selector and the gate disagreeing — which is the whole of the bug. The
under-revalidation direction *adds* revalidations on a site holding such a
taxonomy, and that is the behaviour change to know about on upgrade: a site with
a `public => false, publicly_queryable => true` taxonomy will see its term
archives in a purge-all for the first time.

## Considered Options

**Fix only the selector, with no term gate.** The smallest change that closes the
issue as titled. Rejected because the selector would then be the only thing
deciding, which is the shape ADR 0005 spent a whole decision undoing for posts:
the site gets no say, and the term paths a lifecycle hook would add later would
each have to re-derive the rule. A gate is asked by every entry point; a selector
is asked by one.

**Extend the post filter to terms** rather than adding a second name. Rejected:
its `$post_id` argument has a documented meaning, sites filter on it with
`get_post_type()`, and passing a term id through it would silently change what
every existing implementation of that filter is answering about.

**Enumerate every taxonomy and let the gate alone decide**, so that the filter
can admit terms of a taxonomy the selector would not have offered. Tempting for
symmetry with the post loop, and rejected on cost: a non-viewable taxonomy with a
large number of terms would be read out of the database and resolved term by term
on every purge-all, to be declined by default. The consequence is real and is
stated below rather than hidden.

**Reading `$taxonomy->publicly_queryable` directly.** Rejected for the reason
ADR 0005 gives for post types: matching core's own function keeps the plugin
agreeing with every other viewability decision WordPress makes.

## Consequences

**A site whose taxonomies are all non-viewable gets no term archives from
revalidate-all, and the filter does not change that.** The selector bounds what
the gate is ever asked about, so within purge-all the filter can decline a term
but cannot resurrect a whole taxonomy. The post loop has the same shape and the
same limit — `get_post_types([ 'public' => true ])`, tracked in #53 — and the
remedy for a headless site is the same in both: register the type or taxonomy
`publicly_queryable`, which is what it means to the front-end anyway. If that
turns out not to be enough, the filter is the place to widen, not the selector.

**WordPress 5.7 is now the floor.** `is_term_publicly_viewable()` arrived in 5.7
and `is_taxonomy_viewable()` in 5.1, against a header claiming 5.0 — a claim
`wp_initialize_site` (5.1) and `wp_after_insert_post` (5.6) had already outlived
silently. Both headers now say 5.7, which is a lower bound established by this
change rather than a survey of every core call the plugin makes.

**The admin-flavoured term url is not fixed here.** A taxonomy's `query_var` is
forced to `false` only when `! is_admin()`, registration runs at `init`, and
revalidate-all always runs in an admin request — so with no permastruct to
compose from, `get_term_link()` yields `?my_query_var=slug` where the same term
computed during a front-end request yields `?taxonomy=X&term=Y`. The newly
admitted direction (`public => false`) is exactly where the two disagree, so this
change is what makes the hazard reachable. It is left alone deliberately: it
applies only with rewrites off or no pretty permalink structure, where post
permalinks are `?p=123` and a Next.js front-end has bigger problems, and the
remedies — mutating the global taxonomy registry around the call, or
reimplementing `get_term_link()` — are both worse than the thing they fix.

**Counting is unchanged in shape but not in value.** `revalidate_all()` still
returns the number of items it queued, and the "Purge all: N pages added to
purge" notice still reports it; N moves on any site whose taxonomies the two
settings disagreed about.
