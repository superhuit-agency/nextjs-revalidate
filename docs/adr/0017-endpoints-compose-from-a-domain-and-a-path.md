# Endpoint URLs are composed from a stored domain and a per-endpoint path, and the migration that splits them is guarded on the data

Decided while implementing #29, whose sub-issue #30 needs a second endpoint on
the same Next.js app.

Until 1.6.9 the plugin stored one fully-qualified revalidate URL —
`https://site.com/api/revalidate` — in `nextjs_revalidate-url`, and sent every
revalidation to it. There was no way to address a second endpoint on the same
app.

From 1.7.0 the settings hold a **revalidate domain** and one **path per
endpoint**, and an endpoint URL is **composed at the moment a revalidation is
sent**. Nothing in the options table is an endpoint URL. The paths are optional:
each falls back to a default (`/api/revalidate`, `/api/revalidate-fse`), so a
standard install still supplies exactly two values — the domain and the secret.

## Considered Options

**Derive the second endpoint from the first by string surgery** — swap the last
path segment of the stored URL. Rejected, and it is the option the issue was
opened against: it is correct only for apps that name the route exactly what this
plugin guesses, and it fails silently rather than loudly on every other one. The
path is the operator's app's business, and nothing in WordPress knows it.

**Store both full URLs.** Honest, and it makes the composition disappear. Rejected
because the domain is then stored twice: the two can drift, and the failure that
follows — one endpoint pointing at staging — is invisible on the settings screen.
It also scales badly, and #30 is unlikely to be the last endpoint.

**Keep the single URL and add the FSE one beside it.** The smallest change, and
the same duplication as above with none of the symmetry.

## The migration is guarded on the data, not on the ledger

Splitting the legacy URL is a data migration, and ADR-0001 says migrations are
gated on the DB version ledger. This one is not, and the reason is structural
rather than a preference: sites predating the ledger are **backfilled to the
running release** before any gate is read, so a gate on the release that
introduces the ledger — 1.7.0, the same release as this split — is read after
every existing site has already been stamped past it, and never fires for anyone.
`Settings::backfill_db_version()` says so in as many words, and this is the case
it was warning about.

So the split runs **iff this site holds no domain and a non-empty legacy URL**.
That condition is on the data itself, which makes it idempotent by construction:
it runs exactly once per site, in whatever order it is reached, and cannot be
re-entered. That property is doing real work here rather than being a nicety —
`migrate_db()` runs on **every** `admin_init`, so an unguarded re-split would
overwrite an operator's edits to either field on every page load.

From 1.8.0 onward the ledger is authoritative again and a version gate is enough.

### What the split keeps, and what it drops

`wp_parse_url()` does the work, so a port, a subdirectory and basic-auth
credentials land on the domain side without being special-cased. The credentials
are worth naming: a protected staging front-end is exactly the kind of site that
carries them, and dropping them turns a working install into one that 401s with
nothing on screen to explain it. The **path is preserved verbatim** — the whole
point of the exercise — so `https://site.com/fr/api/purge` becomes domain
`https://site.com` plus path `/fr/api/purge`, and never the default. Only scheme,
credentials, host, port and path are carried over, so query args an operator
pasted in with the URL (a trailing `?secret=`) are dropped **by construction**
rather than stripped by a rule that could miss one.

A URL too broken for `wp_parse_url()` to find a host in is **left where it is**,
neither split nor deleted. The site is unconfigured either way until somebody
retypes the value; discarding their only record of what it was is the one outcome
worse than that.

## Consequences

`Settings::missing_settings()` now names `domain` rather than `url`, and the
unconfigured notice (ADR-0015) says so. The paths are absent from it on purpose:
a site is configured without them.

**`Settings` gained a `__isset()`**, and it is not a tidy-up. PHP routes
`empty( $this->some_setting )` and `isset( $this->some_setting )` to `__isset()`,
never to `__get()` — so a `Settings` without one answers *empty* for every
setting on a fully configured site, silently, and only for code written the
obvious way. It cost a debugging session on this migration's own guard.
`missing_settings()` had been dodging it since before anyone noticed, by reading
each setting into a local first. That is a workaround the next contributor has no
reason to imitate, so the trap is closed at the source and pinned by a test in
`tests/SettingsTest.php`.

`Settings::endpoint_url()` answers the empty string on a site with no domain,
rather than a bare path. Nothing composes an endpoint without an
`is_configured()` guard first; this is what keeps a mistake there from becoming a
request to a relative URL.
