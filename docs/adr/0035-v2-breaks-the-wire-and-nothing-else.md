# v2 breaks the request to the front-end and nothing else: v1 names stay as deprecated aliases until v3

Decided while grilling #81, for v2.

ADR 0033 and ADR 0034 change the request this plugin sends to the front-end,
which every site must follow with a Next.js change shipped in lockstep. That
break is the point of v2. Everything else outside code touches — the PHP
functions, the filters, the inbound REST routes — could break too, since a major
release licenses it. It does not: **a site upgrading to 2.0 changes its Next.js
route and nothing in its PHP**, except in the one place below where v1 had no
meaning left to keep.

## The decision

**Functions get v2 names, and the v1 names become wrappers.**
`nextjs_revalidate_path( $url )` and
`nextjs_revalidate_schedule_path( $datetime, $url )` report a **path** change.
`nextjs_revalidate_purge_url()` and `nextjs_revalidate_schedule_purge_url()`
call them through `_deprecated_function()`, which warns only under `WP_DEBUG`
and fires `deprecated_function_run` either way. `$priority` stops at the
wrapper: there is no queue for it to order.

**Filters keep their meaning where they have one.**

| v1 filter | v2 |
| --- | --- |
| `nextjs_revalidate_should_revalidate_redirect` | unchanged |
| `nextjs_revalidate_should_revalidate_taxonomy` | unchanged |
| `nextjs_revalidate_purge_should_revalidate_post_on_save` | renamed `nextjs_revalidate_should_revalidate_post`, matching its siblings; the old name runs through `apply_filters_deprecated()` |
| `nextjs_revalidate_purge_action_permalink` | **retired** — `_deprecated_hook()` names the replacement to a site still hooking it |

The v1 post filter is asked by every entry point, not only on save, so the
rename corrects a name rather than a behaviour.

**One new filter, `nextjs_revalidate_change( $change )`**, applied to every
change before it joins the pending changes: return it altered, or `false` to
drop it. It replaces the permalink filter, which rewrote or declined the
permalink of a manually revalidated post. There is no permalink left to rewrite
— a post change is keyed by its ID — so that filter cannot be kept as an alias;
this one does both of its jobs, for every entry point instead of three.

**The inbound REST routes are unchanged**, under `nextjs-revalidate/v1`. Each
item becomes a path change; `priority` is accepted and ignored. A REST namespace
versions the REST API rather than the plugin — WordPress 6 serves `wp/v2` — so
plugin 2.0 serving `/v1/` is correct, and a `/v2/` would be added *beside* it
only when the inbound contract itself has to break: callers reporting more than
paths, or waiting for delivery by default.

**The aliases go in v3**, the next release allowed to break anything. Not in a
minor release, whatever their usage looks like.

## Considered Options

**Keep the v1 names as the only names.** Nothing breaks and nothing is
deprecated. Rejected because they are named for the act the glossary retired —
**purge** — and v2 is the release where renaming costs nothing to a caller.

**Remove the v1 names in 2.0.** The major release permits it. Rejected because it
adds a PHP migration to every site's upgrade for no change in behaviour, and
that migration lands in the same deploy as the Next.js one. Two unrelated
failures in one upgrade are harder to tell apart than one.

**A `/v2/` REST namespace now.** Nothing about the inbound contract breaks, so
it would be a second copy of the same routes.

## Consequences

**The one PHP migration** is a site that *rewrote* permalinks through
`nextjs_revalidate_purge_action_permalink`. It moves that logic to
`nextjs_revalidate_change`, and the release notes name it.

**A general `nextjs_revalidate_report_change( $change )`**, letting site code
send any subject, is the natural extension point and is deliberately not in
2.0: nothing in v1 does it. It is additive when it comes.
