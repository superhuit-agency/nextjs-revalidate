# The public API answers whether a revalidation was accepted, and cannot answer whether it was delivered

Decided while fixing #50.

`nextjs_revalidate_purge_url()` is the only programmatic entry point third-party
code has, and the README documented it as returning *"a boolean to indicate
whether the purge has been successful"*. Two things were wrong with that, and
only one of them was a bug.

The bug — the function enqueued the permalink and returned a hardcoded `true`,
discarding what `RevalidateQueue::add_item()` answered — was fixed as a
consequence of #37 (ADR 0007): the queue grew a `not_configured` **refusal**, and
the API function had to stop asserting success to be able to report one. The
return is `$accepted && !is_wp_error($accepted)`, so a refusal and a failed
insert are both `false`.

The documented contract is the half #50 owns, and it is the half that cannot be
fixed by reading a return value more carefully. **This function could never
report whether the purge succeeded**, because the purge has not happened when it
returns. It enqueues a permalink; the **revalidation queue** is drained by cron,
afterwards, in another request. Whatever the front-end eventually answers, this
function has long since returned.

So the contract is: **the return value means accepted into the queue.** The
README now says that, and says what a caller cannot learn from it.

## Considered Options

**Grow the signature to distinguish accepted / refused / rejected** — return the
`WP_Error` the queue produces, or a status string. Rejected. It changes the type
of the one function this plugin promises to outside code, to distinguish cases a
caller can already distinguish where it matters: `RevalidateQueue::add_item()` is
public and returns the `WP_Error` itself, which is how `RestApi::process_items`
reports a refusal per item with a 207. A caller wanting the reason has a route to
it; a caller wanting a yes or no is the reason this function exists, and it is
not worth breaking `if ( nextjs_revalidate_purge_url( $url ) )` for both.

**Make the function wait for the delivery** so the documented promise becomes
true — purge synchronously instead of enqueuing. Rejected outright: it puts an
outbound HTTP request in the request that saved a post, which is precisely the
thing the queue exists to keep out of it, and it would make every caller of the
public API pay a front-end round trip. The asynchrony is the design (ADR 0004),
not an implementation detail the documentation should be trying to hide.

**Leave the README alone**, on the grounds that "successful" is loose enough to
read as "accepted". Rejected because the reading a caller most plausibly takes is
the one that is wrong: an integration that checks the return value is checking
whether its page is fresh, and the answer it gets says only that the plugin will
try.

## Consequences

The return value of `nextjs_revalidate_purge_url()` can be `false`, where before
#37 it was the constant `true`. This is a compatible change in the only direction
that matters — a caller that ignored the value is unaffected, and a caller that
tested it was testing a constant and now gets an answer. It is still worth saying
out loud wherever a release is described, because a caller that logs or reports
on a `false` branch will start reaching that branch on an unconfigured site, and
the cause is this plugin telling the truth rather than a new failure. This repo
keeps no changelog for that to go in, so until one exists this ADR is the record.

**The sibling was checked and does not have the same defect, quite.**
`nextjs_revalidate_schedule_purge_url()` returns `ScheduledPurges::schedule_purge()`'s
result, and that result was meaningful — `false` when the URL is already
registered for that date time. It did assert one thing it had not observed: the
`update_option()` that saves the entries. That result is now returned, which is
safe to read as a failure because the duplicate case has already returned by that
point, so the entries always differ from the stored ones and `update_option()`'s
"nothing changed" `false` is unreachable here.

Its contract needed the same correction as the other for a different reason: a
scheduled purge that is registered is not a revalidation that is accepted. The
permalink reaches the queue when its time passes, and can be refused there — ADR
0007 records that a due scheduled purge on an unconfigured site is refused and
dropped rather than kept. A `true` from this function is a promise to enqueue
later, one step further from the front-end than the other function's.

The two functions are pinned by `tests/integration/PublicApiTest.php`, which is
the first thing in this repo to assert on the public API's *return value* rather
than on the queue's contents. That includes the failed write above, forced
through `pre_update_option`: it is the only case the return of `$registered`
changed, and without a test for it the constant could come back with the suite
still green. It belongs to the wp-env suite for the ordinary
reason (ADR 0008): the acceptance path runs through `NextJsRevalidate::init()`,
the settings and `$wpdb`, none of which a standalone script can stub its way to.
It therefore does not run in the AFK sandbox's gate (ADR 0006), and did not run
in the container this change was written in.
