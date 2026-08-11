# Migrations are gated by a per-site DB version ledger, backfilled from legacy options

Until 1.6.9 the plugin decided which data migrations to run by comparing the
*running code's* version against thresholds. That comparison never worked — it
read the plugin header from a file that has no header, so the version parsed as
`0` and the oldest migration re-ran on every admin request while newer ones never
ran at all. Repointing it at the real header would not have fixed it: the code
version is always the current release, so no threshold would ever match and
migrations would silently stop running for everyone. The version being compared
was simply the wrong one.

We now store a **DB version** in a per-site `nextjs_revalidate-db_version`
option — the migration ledger — and gate each migration on that. Sites predating
the ledger are **backfilled** by inferring their DB version from which legacy
options are present in their data, then stamped. The ledger lives in per-site
options because the data it describes does; a network-wide sweep so unvisited
subsites migrate without an admin visit is deferred to #24.

## Considered Options

**Assume the oldest version and re-run every migration.** Simplest, and it works
today only because both existing migrations happen to be idempotent. That
accident is precisely what hid the original bug for several releases, and the
next non-idempotent migration would clobber data. Rejected: it preserves the
property we are trying to stop depending on.

**Stamp only on activation.** `register_activation_hook` does not fire on plugin
update, so this correctly identifies fresh installs but says nothing about the
entire existing install base — which then falls back to the option above.

**Infer from legacy options (chosen).** The historical option names are a
reliable version fingerprint, and the inference is self-correcting: it runs once,
then the ledger takes over.

## Consequences

Version comparison uses `version_compare()` on version strings, not the previous
`intval(str_replace('.', '', …))`. That scheme concatenated digits rather than
parsing, so `1.7.0` (`170`) compared as *older* than `1.6.10` (`1610`) — a bug
one patch release away from occurring at the time this was written.

`NJR_VERSION` is derived at bootstrap from the plugin header rather than
hardcoded. It had drifted to `1.6.0` while the plugin was at `1.6.9` because
release bumps edited the header and missed the constant. Once the ledger writes
`NJR_VERSION` after each migration, a stale constant re-runs migrations forever,
so the drift had to become structurally impossible rather than merely policed.

The backfill can under-shoot: a 1.5.x site that never enqueued anything has no
queue option to fingerprint and derives as current, skipping a migration. That
migration only deletes options such a site does not have, so the under-shoot is
harmless — but a future migration whose skip is *not* harmless cannot rely on
the fingerprint alone.
