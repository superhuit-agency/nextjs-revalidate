# Viewability selects the post types the admin offers, and an offer is not a gate

Decided while fixing #53, split out of #25. The taxonomy half was split out again
into #54 and decided in [ADR 0022](0022-taxonomy-viewability-gates-term-revalidation.md).

[ADR 0005](0005-post-type-viewability-gates-revalidation.md) made viewability the
rule for whether a *post* is revalidated, and left four selections still asking
`get_post_types([ 'public' => true ])`. ADR 0022 took the taxonomy one, and found
it was not a selection at all but the decision itself, because terms had no gate
downstream. The three that remain are the post-type ones, and for those 0005 was
right: `should_revalidate()` is asked about every post by every entry point, so
none of them can enqueue anything it declines.

What they can do is *offer*. `public` and `publicly_queryable` default to each
other but are registered independently, so the two disagree in both directions,
and both readings were wrong on the surface:

- **`public => true, publicly_queryable => false`** — a "Purge caches" bulk
  action on that type's list screen which purges nothing, and two settings
  switches an operator can turn on to no effect. Nothing fails; nothing says so
  either.
- **`public => false, publicly_queryable => true`** — a type with a real
  front-end page, missing from the bulk action, from both switch lists, and from
  the post types a revalidate all walks. The quieter one: its pages revalidate
  on save and never in bulk.

## The decision

**One selection, `Revalidate::offered_post_types()`, asked by all three.** It is
`is_post_type_viewable()` over every registered post type, less `attachment` —
an uploaded file is not a Next.js route, and `get_post_permalink()` refuses one
whatever its type says, so the three copies of that exclusion collapse into this
one. The three callers are the bulk action registration, revalidate-all's post
type list, and the settings page's two switch lists, which share it rather than
each asking their own question. This is what **Offered post type** in
`CONTEXT.md` names.

**It is an offer, and the gate stays where it is.** Nothing here decides whether
a revalidation is enqueued: `should_revalidate()` still answers that, once per
post, and this does not repeat its status axis or pre-empt its verdict. The
settings page's list is not legitimately different from the action lists —
an operator who is *about to* make a type queryable is one hook away from the
switch appearing, and a switch that does nothing until then teaches them the
plugin is broken.

**Asking core's function, not the property, is what keeps the two from drifting
again.** The offer and the gate's type axis are now the same call, including
core's own `is_post_type_viewable` filter — so a headless site that overrides
viewability there moves both at once, and this plugin agrees with every other
viewability decision WordPress makes. That filter is the lever such a site should
reach for.

**Nothing prunes a stored switch.** Both switch lists are keyed by post type
name, so a type that leaves the list leaves rows behind. They stay: the value is
the operator's, a type can come back, and a migration that deleted settings on
the strength of what is registered *at the moment it runs* would silently drop
switches on any site whose types register conditionally. The rows do not survive
indefinitely either — the settings form posts only the switches it rendered, so
the next save of that page drops them without anything having to look for them.

**The admin bar and the on-menu-save loop ask the offer too, because a stored
row is otherwise unreachable.** A "Purge all Editor notes" entry standing on a
row for a type no longer offered is an entry that purges nothing *and* has no
switch left to turn it off; an on-menu-save row for such a type walks every one
of its posts on every menu save to be told no, with the same missing switch. So
both read only the ticked rows the selection still contains. That is the whole
of the read-side narrowing: `revalidate_all( $type )` is handed a post type by
name, acts on it, and lets the gate answer for each post — an explicit
instruction is not an offer, where a row the operator can no longer see is not
an instruction any more.

## Considered Options

**Select every registered post type and let the gate decide**, as ADR 0022 does
for taxonomies, on the argument that a selector which pre-empts a filterable gate
is worse than no gate. Rejected, and the asymmetry is the reason: the taxonomy
gate sits *above* the enumeration — one call per taxonomy, then its terms — while
the post gate sits *below* it, one call per post. Walking every registered type
means reading every revision, menu item, changeset and product variation on the
site in order to be told no, on the slowest operation this plugin has. For the
two switch lists it is worse than slow: an operator would be offered a switch for
`wp_template_part`.

**Keep the settings lists on `public` and fix only the action lists**, per the
issue's own open question. Rejected: it keeps two authorities on one question,
which is the shape #25 dismantled, and the switch it preserves is precisely the
one that does nothing.

**Prune the stored rows, by migration or on save.** Rejected above. A migration
here would be a destructive answer to a question that answers itself.

## Consequences

**A site using `nextjs_revalidate_purge_should_revalidate_post_on_save` to admit
a non-viewable type loses the bulk action, the switches, and that type's share of
a revalidate all.** Its posts still revalidate on save, which is what that filter
is documented against, but the bulk surfaces narrow to viewability and no
per-post filter can widen them — there is no list of types to derive from a
filter that answers about one post. Such a site hooks core's
`is_post_type_viewable` instead, which restores all of it at once and leaves the
gate agreeing. This is the cost of one selection rather than two, and it is
stated in the README.

**A site registering `public => false, publicly_queryable => true` gains
surfaces it never had**, including that type's posts in a revalidate all. The
forgiving direction: it costs a purge all some duration, not correctness.

**Neither `should_revalidate()` nor **Revalidatable post** changes.** No post
becomes revalidatable that was not, and none stops being. The issue asked whether
this needs a term for the type itself: it needs one for the *offer*, which is why
**Offered post type** is worded as an admin surface and not as a third
revalidatable-something.

**The row action is not covered by this.** "Purge cache" is added to every row
of every list screen a configured site renders, whatever the post's type or
status, and the gate answers when it is clicked. It is the same shape of
inconsistency, over a post rather than over a post type, and #53 does not name
it — it is left as it was rather than fixed in passing.
