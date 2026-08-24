# Redirect changes revalidate the source path, from an integration this plugin supports but never requires

A headless front-end resolves a redirect inside the cached page for the source
path, so creating, editing, deleting, enabling or disabling a redirect leaves
that path serving a stale answer until its cache expires — up to an hour on our
stack. This plugin now enqueues a revalidation of the source path when a redirect
changes, listening to the Redirection plugin's own `redirection_redirect_updated`,
`redirection_redirect_deleted`, `redirection_redirect_enabled` and
`redirection_redirect_disabled` actions when that plugin is active, and doing
nothing at all when it is not. This is what **Integration** in `CONTEXT.md` names:
supported, never required.

Only a **revalidatable redirect** produces a revalidation — a literal source path,
enabled. A regex source matches an unbounded set of paths and is therefore not a
candidate, in the same sense a non-viewable post type is not one (ADR 0005).

## Considered Options

**Putting the integration in `wp-graphql-redirection` instead.** That plugin
already models `Red_Item` and is what makes redirects visible to the front-end,
and this plugin already exposes `nextjs_revalidate_purge_url()`, so the feature
would have been about fifteen lines there and zero here. Rejected because
deciding *when the front-end's cache is stale* is this plugin's whole subject —
it is what every hook in `Revalidate` does — and scattering that knowledge across
whichever plugin happens to sit near the event makes it unfindable. It would also
invert the GraphQL bridge's responsibility: a plugin that exposes a read schema
would acquire a write path into another plugin's queue, and a hard dependency on
it.

**Treating a regex source as a trigger for revalidate all**, on the grounds that
we cannot know which paths it covers. Rejected as a foot-gun disguised as
thoroughness: `is_regex()` is a per-rule checkbox an editor can set casually, and
wiring it to revalidate all means one careless rule enqueues every page on the
site. Losing a revalidation is recoverable; a self-inflicted stampede against the
front-end is worse than the staleness it cures.

**Gating the feature behind a setting**, as `revalidate_on_menu_save` does.
Rejected because that toggle guards a *revalidate all* — thousands of paths —
whereas a redirect change enqueues exactly one, so there is no cost to gate. A new
option would also have to answer what its absence means for every site that
upgrades, and `CONTEXT.md` is explicit that an **empty value** is what absence
means, not a preference. The `nextjs_revalidate_should_revalidate_redirect`
filter gives an operator the escape hatch without any stored state.

**Revalidating the destination as well as the source.** Rejected: creating a
redirect changes nothing about what the destination renders, and a bulk import
pointing hundreds of redirects at one landing page would revalidate it hundreds
of times for no change at all.

## Consequences

Source paths are normalised with `user_trailingslashit()` before being enqueued,
because `revalidatePath()` keys on the exact string while Redirection matches
either form. A source typed without a trailing slash would otherwise enqueue a
path that no cache entry matches, and under ADR 0004 that revalidation is not
retried — it would fail silently. This ties redirect revalidations to the same
permalink convention the rest of the queue already follows; if it is ever wrong
for a site, it is equally wrong for every post permalink that site enqueues.

A regex redirect gets no revalidation and the editor is not told. A `Logger` line
records it, but logging is gated on the debug setting, so on a normal site the
skip is silent. An admin notice was considered and dropped: the redirect is saved
through Redirection's own REST routes from a React admin that never reloads, so
any notice would have to be stored and rendered on some later, unrelated admin
page — late enough to confuse more than it explains.

Redirection fires `redirection_redirect_updated` from two call sites with
different signatures — `(int $id, Red_Item $new)` on create and
`(Red_Item $old, Red_Item $new)` on update — so the handler branches on the first
argument's type. The update case is the only one where the old item matters: a
changed source leaves two stale paths, and both are enqueued.

Bulk operations fire these actions once per redirect within a single REST
request, so a bulk delete of hundreds of redirects enqueues hundreds of
revalidations. This is accepted rather than capped: the queue is durable and
cron-drained, revalidate all routinely enqueues far more, and the count is
bounded by rules an operator actually created. Collapsing above a threshold would
reintroduce the stampede rejected above, and capping would silently drop
revalidations that ADR 0004 guarantees no retry for. Source paths are deduplicated
within the request so one path costs one row.

## Amended once the integration was built

**Redirection 5.9.0 passes the redirect's id from both call sites.** The
signature this record describes — `(Red_Item $old, Red_Item $new)` on update —
was true until upstream's "Match hook to docs" change (July 2026), which made
`update()` fire `(int $id, Red_Item $new)` like `create()` already did, and
covered it with a test of its own. The handler still branches on the first
argument's type, because the two signatures now differ by Redirection version
rather than by call site, and a site can be running either. What changes is the
reach of the old-source case: on 5.9.0 and later nothing carries the previous
state, so changing a redirect's source revalidates the new path and leaves the
old one to expire on its own. Recovering it would mean reading the row before
Redirection writes it — from its REST route, or from the `redirection_update_redirect`
filter, which is fired without the id of the redirect it belongs to. Both are a
deeper reach into another plugin's internals than one stale path is worth; if
editors report it, that is the issue to open, not this one to widen.

**The development environment tracks Redirection's latest release** rather than
pinning one. The argument for pinning was reproducibility, and the argument
against it was that upstream had fired these actions stably for years — which
turned out not to be true. Tracking latest is what makes the suite notice the
next such change; a version pin would have hidden this one.

**Deduplication is the queue's, not the integration's.** The decision above is
unchanged — one path costs one row — but nothing in this integration remembers
which paths it has seen. `RevalidateQueue::add_item()` already enqueues a
permalink it holds exactly once, so a second answer here would only add a way to
be wrong: a set kept in memory outlives the queue entry it describes, and in a
long-running process (a WP-CLI import) it would decline a path that had already
been enqueued, drained, and made stale again.

**The escape hatch declines; it does not admit.** `nextjs_revalidate_should_revalidate_redirect`
is applied after the revalidatable redirect rules and over the source path they
produced, which is what lets a site decline one path rather than the feature —
and what stops it resurrecting a revalidation those rules never had a path for. A
regular expression source returns before the filter is reached, so no amount of
returning `true` turns it into a revalidation, and the stampede rejected above
cannot be filtered back into existence. This is where it parts company with
`nextjs_revalidate_purge_should_revalidate_post_on_save`, which admits as well as
declines: every post has a permalink whatever its type, so there is always a
single path for that filter to be right about. The reach is the whole of the
integration — every event that enqueues a source path asks, and an update that
changes the source asks twice, once per path. Issue #60.

**A source names a path from the domain root, not from the site.** Redirection
matches its redirects against `REQUEST_URI`, and its post slug monitor stores the
path component of a post's permalink, so on a site served from a subdirectory
every source it holds already carries that directory. The permalink handed to the
queue is therefore the site's scheme, host and port with the source path after
it, rather than `home_url( $path )`, which would name the directory a second time
and enqueue a path the front-end has nothing behind — a revalidation ADR 0004
does not retry. On a site at the root of its domain, which is most of them, the
two are the same string, which is why the difference went unnoticed until the
integration was read against upstream's matching.
