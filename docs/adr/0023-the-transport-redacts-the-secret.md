# The transport redacts the secret, because it is the only place that knows one is in play

Decided while triaging #95, which was surfaced by the review of #90.

Every request this plugin makes carries the secret as a query arg — a
revalidation and an FSE snapshot invalidation differ only in the URL they
compose (ADR-0017), and both compose it with `secret=` on the end. Since #90,
two of the outcomes `Traits\FrontEndRequest::send_front_end_request()` answers
carry a string of **arbitrary origin** back to the caller: `unreachable` carries
whatever the HTTP transport said, and `exception` carries whatever anything in
the request path threw. Those strings are written verbatim to a log file in
`wp-content/uploads`, which most hosts serve directly over HTTP.

**The redaction happens where the `WP_Error` is minted**, in the transport trait,
and nowhere else. `send_front_end_request()` is the one place in this plugin
where a string of external origin meets a URL that holds the secret; upstream of
it there is no message, and downstream of it there are five separate surfaces
that would each have to remember.

Only `unreachable` and `exception` are redacted. `no_response` and
`http_{status}` are `__()` literals this plugin wrote and `not_configured` comes
from `Settings`, so passing them through a redaction buys nothing and only adds
surface on which a short secret could mangle a string we already know is safe.

## Considered Options

**Redact in `Logger::log()`.** The obvious home, and the one a future reader will
reach for — which is the main reason this record exists. It is a wider net for
the log file specifically: nothing the plugin can ever append would carry the
secret, regardless of which caller wrote it. It was rejected because it is
strictly narrower **overall**. `RestApi.php` hands `get_error_message()` to an
authenticated HTTP client and `Probe` puts it in an admin notice; neither passes
through `Logger`, so a `Logger`-only redaction leaves both exposed while looking
complete. It also makes `Logger` know what a secret is, which is the one thing a
dumb sink should not have to.

**Redact in `Revalidate::purge()`.** This is what #95 proposed, and it was posed
against a wrong premise: the message is not minted in `purge()` but in the trait
`purge()` shares with `FseSnapshot`. Redacting there would leave the FSE snapshot
path fully exposed, and would do it invisibly — the revalidation tests would all
pass.

**Both seams, trait and sink.** Belt and braces, and not obviously wrong. Rejected
for the ordinary reason: two places to keep honest, and the second one only ever
fires on strings the first has already cleaned.

## The redaction is by shape *and* by value

`secret=` is blanked **by shape**, with the value untouched: that catches the
case the issue is actually about — a request URL echoed into a transport message
— without needing the configured secret to be readable or current at the moment
of redaction. The configured secret is then also replaced **by value**, because a
message that names the secret without a URL around it is not reachable by parsing
query args.

**By value means every spelling, not the configured one.** The secret reaches a
URL through `add_query_arg()`, which `urlencode()`s it, so a secret holding a
space or a `/` is never quoted back the way an operator typed it — `two words`
travels as `two+words`. While that sits in a `secret=` arg the by-shape pass
covers it, but a transport is free to quote a bare fragment instead, and a
by-value pass that knew only the configured spelling would walk past it. So the
configured value, its `urlencode()` and its `rawurlencode()` are all replaced;
the last two differ only on the space (`%20` against `+`) and which one comes
back is the transport's choice. The spellings are applied longest-first, because
`str_replace()` rescans what earlier needles left behind and a shorter spelling
that is part of a longer one would otherwise strand the remainder in the message.

**There is deliberately no minimum-length guard.** A secret's only validation in
this plugin is that it is non-empty (`Settings::missing_settings()`), so a
one-character secret is a legal configuration, and a `strlen() >= 8` guard would
mean the configuration where the hole is worst is the one that silently opts out
of the fix. Blind replacement against a short or common secret will mangle
unrelated diagnostic text — `in 1.2s` becoming `in ***.2s` — and that is accepted
as the cheaper failure. Garbled diagnostics fail safe; a length guard fails open.

## Consequences

**The failure window does not inherit any of this**, and this is worth stating so
that nobody re-derives it. `FailureWindow::record()` stores
`(string) $outcome->get_error_code()` and never touches the message, so
ADR-0007 is unaffected as specified.

The guarantee this ADR makes is about **messages leaving the transport**, not
about the log file. A log written before this change still holds whatever was
written then, and the file remains world-readable on most hosts — it is full of
permalinks and error text regardless of the secret. Hardening or relocating it is
a separate concern with a separate remedy, filed as its own issue rather than
folded in here, because it is the only fix that helps a site whose log is
*already* dirty.

A structural test pins the seam, on the model of `tests/psr4-autoload-test.php`:
a new transport call site that carries a transport or exception message into a
`WP_Error` without redacting it fails the gate rather than shipping. Without it
this decision survives only as long as everybody has read this file.

That test is `tests/transport-redaction-test.php`, and it holds both halves of
this record. The behaviour — including the cases the redaction is allowed to be
clumsy about — is driven through a bare class using the trait, because the whole
claim is that a caller cannot opt out. The seam is a token scan over `include/`
for every `new WP_Error(...)` whose message argument mentions
`get_error_message()` or `getMessage()`, each of which must also mention
`redact_secret()`. `purge-outcome-test.php` and `FseSnapshotTest.php` each pin
one end-to-end trip through a real caller reading a real setting (the second
one moved in v2 — see the amendment below), which is what would catch the
redaction going through a `settings` the trait's own fixture supplies and no
real class does.

## Amended for v2: the secret travels in a header

Built in #156, under ADR 0034. The v2 request is a `POST` carrying the secret as
`Authorization: Bearer <secret>`, minted by the same trait —
`send_front_end_changes()` beside v1's `send_front_end_request()`, both through
one private method that is the only place a request's `WP_Error` is made. So
the seam this record pins is still one seam, and the structural test still
counts the trait's mints and checks each one.

**The by-value pass is what covers the header.** A transport quoting the request
back quotes `Bearer <secret>`, not a `secret=` arg, and only the configured
value can find that. `tests/transport-redaction-test.php` drives it through the
`POST` as well as the `GET`.

**The by-shape pass stays.** While the revalidation queue still sends v1's `GET`
with `secret=` in its URL, it is doing exactly the work it was written for. Once
nothing sends that `GET`, the pass finds nothing in a message about a request of
this plugin's own, and it is left in place as a harmless no-op rather than
removed: it costs one `preg_replace()` per failure, it still catches a secret
that has been changed since the message was minted, and taking it out would be
a change to the one function whose whole job is to fail safe.

**The end-to-end trip moved.** `FseSnapshot` no longer sends anything, so the
trip through a real caller reading a real setting is
`tests/pending-changes-test.php` — the real `Settings` and the real `Logger`,
with a transport error quoting the header and a log file that must not hold the
secret afterwards — beside `purge-outcome-test.php` for the `GET`.
