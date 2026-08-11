# Revalidation delivery is at most once — a failure is recorded and dropped, never retried

The queue drain called `Revalidate::purge()` and discarded its return value,
logging `✅ Revalidated` unconditionally. `purge()` returns falsy on an
unconfigured site, on a `WP_Error` from the HTTP call, on any non-200 response,
and from a `catch` that swallows every `Throwable`; all four were reported as
successes. Because `get_next_item()` deletes the row inside its transaction
*before* the drain attempts the purge, nothing survived the lie either — the item
was already gone from the queue.

Correcting the log forced a question the plugin had never actually answered: what
*is* a revalidation that was attempted and did not succeed? The answer is now a
**failure** (see `CONTEXT.md`), and a failure is recorded in the log and dropped.
There is no retry, no dead-letter table and no backoff. Delivery is **at most
once**, and this is a deliberate guarantee rather than an accident of the
implementation.

`purge()` returns `true|WP_Error` so the drain can log *which* failure happened —
`unreachable` and `http_401` send an operator to completely different places, and
collapsing them into one ❌ throws away most of the diagnostic value. The
signature was free to change: `purge()` has exactly one caller, and the
documented public API (`nextjs_revalidate_purge_url()`) only enqueues.

## Considered Options

**Bounded retry — N attempts with backoff, then drop.** The obvious answer, and
the one a reader will assume was taken. Rejected on cost and blast radius: the
queue table is `id / permalink / priority` with no attempt counter, so this needs
a versioned migration through the **migration ledger** plus a **network sweep**
to reach unvisited subsites — the machinery of #28 and #36 recruited in service
of a logging fix. It also needs a backoff schedule invented from nothing, because
`schedule_next_cron()` currently schedules every run at `now`.

**Unbounded re-enqueue at lower priority.** Three lines, no schema change, and
`add_item()` already dedupes on `permalink`, so a re-enqueued path collapses into
any pending one. Rejected because with no attempt counter and no backoff a
front-end that is down produces a hot loop: fail → re-enqueue → cron fires at
`now` → fail, forever, filling the log at line speed. The plugin would become a
self-inflicted denial of service against the site it exists to serve.

**Persisting failures for display in the settings queue tab.** The most useful
outcome for an operator, and rejected for the same reason as bounded retry — it
reintroduces the schema migration, and adds a retention policy on top.

## Consequences

The plugin offers no delivery guarantee, and callers must not assume one. A
revalidation can be enqueued, attempted, fail, and vanish, leaving a stale page
and a log line. This is the correct trade for a cache-warming tool — a stale page
is a cost, not a corruption — but it is exactly the property a future reader is
likely to try to "fix" without realising it was chosen.

The log is the only surface on which a failure ever appears, and logging is
**off by default**. An operator who does not already suspect a problem is still
told nothing. That gap is real and deliberately left open here; closing it is
separate work, and overlaps #37's admin-notice pattern.

Verification depends on #19. `Logger::log`'s guard returns early precisely when
logging is switched on, so no change to logging is observable until that inverted
condition is fixed. This ADR's change is sequenced behind it.

`Logger::ERROR` has never been called anywhere in the codebase; the failure log
line is its first caller. The level survives a round trip through
`strtolower()` on an integer, which is coercion rather than a deprecation and
holds on 7.4 through 8.4 — but it is the sort of thing #34's PHPStan gate is
likely to flag once it lands.

The unconfigured branch in `purge()` returns `not_configured` and is logged as a
**refusal**, not a failure, preserving the distinction the glossary draws. After
#37 lands it is unreachable from the drain, which refuses at enqueue time
instead; it stays as a guard rather than a live path.
