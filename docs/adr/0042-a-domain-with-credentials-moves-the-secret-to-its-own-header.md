# A domain with credentials sends them as basic auth, and moves the secret to its own header

Decided while triaging #199, for v2.1.0. Amends ADR 0034.

A staging front-end behind basic auth is configured by putting the credentials
in the **revalidate domain** — `https://user:pass@staging.example.com`. 1.x sent
its secret as a query arg, so nothing else claimed the `Authorization` header,
and the transport built `Authorization: Basic …` from the URL. ADR 0034 moved
the secret into `Authorization: Bearer <secret>`. A request carries one
`Authorization` header, and the plugin's own header wins on both transports:
libcurl builds `Basic` from the URL only when no `Authorization` header is set,
and the fsockopen transport never builds it at all. **From 2.0.0, every delivery
to a front-end behind basic auth was answered 401**, and ADR 0039's carrying of
credentials across a redirect (#184) could never take effect.

## The decision

**Whether the request's URL carries credentials decides where the secret goes.**
Nothing to configure: credentials in the domain already say the front-end is
behind basic auth, and a setting would be one more thing to get wrong.

- **No credentials** — unchanged: `Authorization: Bearer <secret>`.
- **Credentials** — `Authorization: Basic <base64(user:pass)>`, and the secret,
  bare, in **`X-Nextjs-Revalidate-Secret`**. No `Authorization: Bearer`.

The secret is in exactly one header in every request, never both.

**The plugin builds the `Basic` header itself**, rather than leaving it to the
transport, so that both transports send it. The credentials are percent-decoded
first, as libcurl decodes them; a user with no password is sent as `user:`.

**Per hop.** The headers are worked out from the URL each request goes to.
ADR 0039 already decides which credentials that URL carries — the previous hop's
carried over, or the `Location`'s own — so a redirect needs no new rule.

**Every request the plugin sends goes through this** — the delivery of the
pending changes, and the probe, since both go through
`send_front_end_changes()`. A probe therefore tests the request deliveries
actually send.

**The contract stays version 2.** The README's front-end contract gets one more
place to read the secret from: `X-Nextjs-Revalidate-Secret` when the request has
it, `Authorization: Bearer` otherwise. Read in that order, because a front-end
behind a proxy may still see the proxy's `Authorization: Basic`. The reference
route reads both.

## Considered options

**Always move the secret to its own header.** One request shape for every site.
Rejected because it breaks every v2 front-end on the day after 2.0 — a contract
version 3, and under ADR 0035 a plugin 3.0 — to fix the one setup that has
credentials. It also gives up, for every site, the reason ADR 0034 chose
`Authorization`: logging and tracing tools redact it.

**Document that basic auth has to be lifted for the revalidate route.** No code
and no contract change. Rejected because it withdraws a setup the plugin
supports on purpose: the 1.x migration keeps the domain's credentials, and ADR
0039 and #184 exist to carry them. It also pushes the fix onto every staging
operator, and some hosts' password protection cannot exempt one path.

**`Proxy-Authorization`.** Rejected: only a forward proxy reads it, and answers
407. A front-end behind basic auth answers 401 and reads `Authorization`.

**A setting** to choose the header. Rejected for the reason above: the domain
already says it, and a setting would let an operator contradict it.

## Consequences

**A site whose domain has credentials its front-end no longer checks breaks on
upgrade.** On 2.0 it worked, because `Bearer` reached a front-end that did not
look for basic auth. On 2.1 the secret moves, and a route reading only
`Authorization` answers 401 until it reads the new header too, or the
credentials are taken out of the domain. The 2.1.0 changelog says so.

**On those sites, the secret is in a header that tools don't redact by
default.** Access logs and tracing tools that hide `Authorization` will record
`X-Nextjs-Revalidate-Secret` unless told otherwise. That only affects sites whose
domain has credentials. ADR 0023's by-value pass already keeps the secret out of
what the plugin itself logs, whichever header carried it.

The credentials themselves are not redacted from transport messages, as before
this decision.
