# A wholly-failed REST request answers a failure status, and 207 is left to the mixed batch

Decided while fixing #118, split out of #93's triage.

`RestApi::process_items()` ended with one line deciding the status of both
routes:

```php
$status = $had_error ? 207 : 200; // 207 Multi-Status when some items failed
```

So every request holding a failed item answered **207** — one bad item in a batch
of ten, ten bad items out of ten, or the single route's only item.

**207 is a success class.** `fetch`'s `res.ok` is true for it,
`WP_REST_Response::is_error()` is false — it is `get_status() >= 400` — and the
raise-on-error helper of most HTTP clients stays quiet. A caller doing the
ordinary thing, issuing the request and checking the status, was told everything
was fine when nothing had been queued.

That matters more here than it would elsewhere. These routes are how a front-end
deploy hook, a CI job or an external CMS asks this plugin to revalidate, and #93
records the reason: those callers have no other feedback channel. They cannot see
the **revalidation queue**, the **log file** or the drain. #93 made the *body*
honest, so a caller reading `results[].success` learns the truth — leaving a 2xx
on a wholly-failed request moved the lie rather than removing it.

**And 207 did not fit the single route at all.** 207 Multi-Status (RFC 4918 §13)
exists to say "one code cannot describe this body, look at the per-element
statuses". With one item there is one outcome and nothing to disambiguate.

## The decision

The status is decided by the **outcomes**, never by the route that produced them.

| What happened | Status |
| --- | --- |
| Every item accepted | `200` |
| Some accepted, some not | `207` |
| None accepted | the failure's own status, below |

The single route sends one item, so it can never reach the 207 branch: one
outcome is the request's outcome. No branch names a route, which is why the two
cannot drift apart again.

An item that was not accepted carries the status of **whose problem it is**:

| Why it was not accepted | Status |
| --- | --- |
| No `path` — this route cannot read the item | `400` |
| The site is unconfigured — a **refusal** (ADR 0015) | `503` |
| The queue write did not happen, or something threw | `500` |

When a wholly-failed request's items disagree about why, the answer is
**503 over 500 over 400**.

A refusal comes first because it describes the site rather than the item: an
unconfigured site refuses everything it is sent, so wherever a refusal is among
the outcomes it is the truth about the whole request, and the well-formed item
next to the unreadable one would have been refused too. Both 5xx kinds outrank
400 because a caller told its request was bad goes looking at the request — and a
request that was fine is the one place that answer must never send it.

The two handlers' own 400s are unchanged: a single call with no `path` and a
batch with no `items` array never reach `process_items()` at all, and were
already 400. They agree with the table above rather than being exceptions to it.

**An entry of `items` that is not an object is an item with no `path`.** The
batch handler used to skip such an entry, so the body came back a result short
and a batch that lost an item beside an accepted one answered 200 — the same
lie as the 207, told about an item instead of a request. It now reaches
`process_items()` and is reported like any unreadable item. A batch holding
nothing *but* such entries still answers 400, now with a result per entry rather
than the handler's own "No valid items" message.

## Considered Options

**Keep 207 for every failed request** — the state before this, on the grounds
that the body is honest since #93 and a caller can read it. Rejected: the status
line is the first thing every HTTP client offers and the only thing some of them
check. A contract that is only honest to a caller who knows to distrust the
status is not a contract; and the routes exist for callers — a deploy hook, a CI
job — whose whole integration is "did this 2xx".

**One failure status for everything, `400` or `500`.** Simple, and wrong in one
direction each. A blanket 400 tells a caller its request was bad when the site is
merely unconfigured, sending it to inspect a payload that was fine. A blanket 500
claims something broke here when the caller sent an item with no path, and claims
an internal error for a site that is working exactly as a site with no settings
should. The three kinds already exist in the body; the status can carry them for
the price of one array.

**`409 Conflict` for the refusal** instead of 503. It reads well — the request
conflicts with the current state of the target resource, which is "unconfigured".
Rejected because 409 is overwhelmingly used for *edit* conflicts, where the fix
is to re-read the resource and send a different request. Nothing the caller sends
can resolve this one. 503 is the closest standard fit for "this service cannot do
what you asked until somebody changes something here", and it is the status a
caller most plausibly already treats as "not now, not my fault".

503's usual implication of *temporary* is the honest weakness of that choice: an
unconfigured site stays unconfigured until an operator acts, which can be never.
No `Retry-After` is sent, precisely because this plugin has no idea when — and a
caller that retries on a schedule of its own is not harmed, since every attempt
is refused at the door without touching the queue.

**A status per item in the body.** Rejected as out of scope and as the wrong
shape: #93 settled the per-item body — `success`, `message`, and `data` only on
an acceptance — and a per-item status would be a second vocabulary for what
`message` already says, on a surface this issue was explicitly told not to
change.

**Document the 207 instead of changing it.** Rejected for the reason the issue
gives: the routes had no documented contract at all, so nothing promised a 207
and nothing depended on the promise. Writing down a status that lies was never
the cheaper half of the fix. The contract *is* now documented, in the README's
"REST routes" section, which is the part of this option worth keeping.

## Consequences

**This is caller-visible on a shipped surface.** An integration that treated 2xx
as success will start seeing failures it previously missed, which is the point. A
caller reading `results[].success` sees no change; a caller reading the status
sees requests it thought had succeeded become 400, 500 or 503. Nothing that was a
success becomes a failure — a request in which every item was accepted answered
200 before and answers 200 now — and a genuinely mixed batch still answers 207.
This repo keeps no changelog, so as with ADR 0010 this ADR is the record until one
exists.

**The README now documents both routes** — their arguments, their body and every
status they answer. It described only the *outbound* endpoints this plugin calls
on the front-end; the inbound routes had no documented contract, which is why
this change could be made at all.

**Two ADRs mention the old status in passing and are superseded on that point,
not corrected in place.** ADR 0010 says `process_items` "reports a refusal per
item with a 207", and ADR 0015 says it "reports it per item with a 207, which is
how a REST client learns of a refusal". Both were true when written. A refusal is
still reported per item, and still in the body; the status a request carrying one
answers with is 503 when nothing was accepted, and 207 only when something was.

**A refused item is not a failed revalidation.** `CONTEXT.md` reserves **failure**
for a revalidation that was enqueued, attempted against the front-end and did not
succeed. Nothing in this ADR is about one: these statuses cover items that never
reached the queue — a **refusal**, an unreadable item, or a write that did not
happen — and what a route answers is still an **acceptance** and never a delivery
(ADR 0010). A 200 here says the permalink is in the queue and nothing more.

**Tested twice, at both of this repo's automated idioms** (ADR 0008).
`tests/rest-response-status-test.php` is the rule itself — every kind of outcome,
every mixture, and that the single route can never answer 207 — as a standalone
script, so it runs in the gate an unattended agent is judged by. The reach is
`tests/integration/RestApiTest.php`: the statuses coming back out of a real
`rest_do_request()`, with a real queue and real settings behind them, including
the wholly-failed batch and the precedence between a refusal and an unreadable
item. `tests/rest-queue-answer-test.php` keeps its own status assertions, which
moved with this change; the rule is not its subject.
