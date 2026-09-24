# Changes are delivered when the request that produced them ends, and the revalidation queue is removed

Decided while grilling #81, for v2. Replaces ADR 0021 and ADR 0029, and amends
ADR 0004, ADR 0007, ADR 0010, ADR 0017 and ADR 0027 where noted below.

The revalidation queue existed because revalidating a **path** was expensive:
v1 gives the front-end a minute per path, Superstack's route even fetched the
page back to warm it, and revalidate all enqueued every permalink on the site.
None of that survives ADR 0033. Marking a tag stale takes the front-end
milliseconds, and revalidate all is one change.

The queue never bought durability either. An item is deleted inside the
transaction that reads it, *before* delivery is attempted, and a failure is
dropped (ADR 0004). Removing the queue loses no guarantee the plugin gave.

The pattern that replaces it already ships: `FseSnapshot` marks the snapshot
stale during the request, answers the editor first (`fastcgi_finish_request()`,
or LiteSpeed's equivalent), and makes one request from `shutdown`. v2 does that
for every change.

## The decision

**A request collects its pending changes and delivers them when it ends.**

- **Changes to the same subject merge**, keeping the state before the first and
  the state after the last. A post saved three times in one request is one
  change.
- **Delivery happens in chunks** once a request holds a bounded number of
  changes (about 100), rather than only at `shutdown`. A WP-CLI import is one
  process whose `shutdown` comes at the very end; without a chunk it would build
  an unbounded body.
- **The timeout is five seconds**, down from sixty. On a server that cannot
  answer the editor first, the editor waits for it; a front-end that needs longer
  than that to mark tags stale is a failure worth surfacing.
- **A change belongs to the site that was current when it was produced**, and is
  delivered with that site's domain and secret. A request that switches between
  sites keeps pending changes per site.

**One request is one revalidation**, and it succeeds or fails as a whole. The
front-end answers the request once; there are no per-change results. Any 2xx is
a success — a `POST` may answer 202 or 204 — and everything else is a
**failure** carrying the codes the transport already names (`http_401`,
`unreachable`, …). A failed request drops every change it carried, at most once
as before, and the log records how many and of which subjects.

**One endpoint.** The FSE snapshot is a `templates` change on the same route as
every other, so its endpoint path and its **revalidate on FSE save** switch are
removed. The switch existed so an older front-end would not be called on a
route it did not serve; a v2 front-end serves the contract and ignores a subject
it does not cache. ADR 0017's split of a domain from a path stays, with one
path.

**The secret travels as `Authorization: Bearer <secret>`**, the header most
logging and tracing tools already redact. ADR 0023's by-shape pass has nothing
left to find in a request of the plugin's own; its by-value pass stays, because
a transport message can quote a header back.

## Considered Options

**Keep the queue.** It is the only option under which no editor ever waits on
the front-end, and that is its whole remaining argument: on a server with no way
to answer before `shutdown` (mod_php), the editor waits out the request. Rejected
because the cost is 900 lines, a table, a migration history on two database
engines (ADR 0029), a priority model (ADR 0021) and a cron, all to defend a
request that now takes milliseconds and is capped at five seconds.

**Fire and forget** — a non-blocking `wp_remote_post()`. Nobody waits, ever.
Rejected because nothing then reads the answer: the **failure window** would
record nothing, and the degraded notice (ADR 0007) would never fire. A front-end
that rejected every change would be indistinguishable from one that took them.

**Per-change results**, each change succeeding or failing on its own. Rejected:
every front-end route would owe a per-change answer, and one outage during a bulk
edit would enter a hundred failures, pinning the degraded notice until ten more
requests succeeded — a window meant to detect a *flaky* front-end would be filled
by one bad minute.

**Keep the FSE endpoint separate.** It was separate because it was the one
request that did not revalidate a path; once none does, the reason is gone.

## Consequences

**Replaced outright:** ADR 0021 (promotion by priority) and ADR 0029 (the
queue's permalink hash). Neither has a queue to govern. `priority` survives only
as an argument that is accepted and ignored (ADR 0035).

**ADR 0004** stands — at most once, failures recorded and dropped — with its unit
widened from a queue item to a request.

**ADR 0007**'s failure window counts requests. A templates change is now a
revalidation like any other and enters it, where a failed snapshot invalidation
used to reach only the log. The probe still never enters it (ADR 0013).

**ADR 0010** stands: the public API and the REST routes answer *accepted*, never
*delivered* — the changes are delivered after the response is sent. ADR 0027's
status table carries over, less the 500 for a queue write that did not happen.

**The upgrade drops the queue**, guarded on the data rather than on the ledger:
a 1.6.9 site upgrading straight to 2.0 is backfilled to 2.0.0, so a `< 2.0.0`
gate would never fire for it — the trap ADR 0017 recorded for 1.7.0. The table is
dropped if it exists, its cron unscheduled, and the removed options deleted if
present. **Pending rows are dropped, and counted in the log.** v2 ships in
lockstep with a front-end deploy, and no Next.js cache survives a deploy — the
build ID is part of every key — so every path still queued is already fresh on
the new front-end. Converting the rows would send changes to a front-end that
has just been rebuilt, thousands of them if a revalidate all was mid-flight.

**The settings screen loses its queue tab** and its progress bar, and the notices
that counted queued pages report that a revalidation was sent.
