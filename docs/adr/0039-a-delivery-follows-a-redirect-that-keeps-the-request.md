# A delivery follows a redirect that keeps the request, to the origin it was sent to

Decided while reviewing #181, for v2.0, from a delivery that never reached
tipee.ch.

v1 sent a `GET` through `wp_remote_get()`, which follows up to five redirects of
any kind, to anywhere. v2's `POST` was sent with `'redirection' => 0`: a front-end
answering 301 has not taken the changes, and following it would turn the `POST`
into a `GET` of some other page, whose 200 would be recorded as a success.

That also stopped at the one redirect a front-end answers routinely. **A Next.js
app with `trailingSlash: true` answers `POST /api/revalidate` with a 308 to
`/api/revalidate/`**, so on such an app — tipee.ch, and superstack, the starter
most of our front-ends come from — every delivery was recorded as `http_308`, and
nothing was ever revalidated. Typing the path as `/api/revalidate/` did not help
either: `Settings::endpoint_url()` took the slash off.

## The decision

**A 307 or a 308 to the origin the request was sent to is followed**, with the
same method, headers and body — which is what those two statuses ask for. The
origin is the scheme, host and port, with a default port spelled out, so
`https://example.com` and `https://example.com:443` are one origin. A `Location`
that is a path is joined to the URL the request went to, credentials and all:
it is the shape Next.js answers with, and a staging front-end behind basic auth
would answer the next hop 401 without them.

**Nothing else is followed**, and the redirect is the outcome, `http_{status}`:

- **A 301, a 302 or a 303**, for the reason above.
- **A redirect to another origin**, a subdomain, another port or another scheme
  among them. The request carries the secret in its `Authorization` header, and
  a redirect is the front-end naming a URL the operator never typed.
- **A `Location` that is a relative path, or given more than once.** Nothing a
  front-end has a reason to send, and guessing at one is worse than reporting it.
- **A third redirect.** Two are followed, which is one for a trailing slash and
  one to spare; the cap is what keeps a front-end redirecting to itself from
  holding the request.

**The timeout is the caller's, for the whole delivery** (ADR 0034's five
seconds), and a redirect is followed only with what is left of it. A delivery
that has spent it reports the redirect rather than starting another request.

The transport's own following stays off. It knows no origin, and it follows a
301 as a `GET`; the redirects this plugin follows, it follows itself, in
`Traits\FrontEndRequest`, where every request it makes is sent.

**A path typed with a trailing slash keeps one.** `Settings::endpoint_url()` joins
the domain and the path with exactly one slash, as before (ADR 0017), and keeps
one at the end when the operator typed one. An app with `trailingSlash: true` is
then reached in one request instead of two. A path of nothing but slashes is
still a field left empty.

## Considered options

**Keep not following, and only keep the trailing slash.** The smallest change,
and the one that does not touch the transport. Rejected because the default path
is `/api/revalidate`: every site on superstack would fail until an operator
worked out, from `http_308` in a log, which field to change and how.

**Let the transport follow, and check where it ended up.** `wp_remote_post()`
with `'redirection' => 2`. Rejected because the check comes too late: by the time
the response names its final URL, the secret has already been sent there, and a
301 has already become a `GET`.

**Also follow an upgrade from `http` to `https` on the same host.** Safe for the
secret, and a common redirect. Left out because it is an operator's
configuration to fix, not a front-end's routing: a domain saved as `http://`
sends the secret in the clear on every first hop, and following the upgrade would
hide that rather than surface it.

## Consequences

A delivery to an app with `trailingSlash: true` costs a round trip more than it
has to until the path is typed with its slash. Nothing logs the redirect it
followed: the outcome is the only trace a delivery leaves (ADR 0004), and a
delivery that arrived has nothing to report.

The README's contract says a 307 or a 308 to the same origin is followed and any
other 3xx is a failure, and the 2.0.0 changelog says 1.x followed any redirect
and 2.0 does not.
