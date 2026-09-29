# The queue dedups on a hash of the permalink, because a TEXT column cannot be keyed portably

> **Status: superseded by [ADR 0034](0034-changes-are-delivered-when-the-request-ends.md).**
> v2 removed the revalidation queue and its table (#160), so there is no
> permalink left to key, portably or otherwise. The upgrade to 2.0 drops the
> table from every site that still holds one, whichever key it was carrying.
> `tests/queue-schema-portability-test.php` went with the schema it held. Kept as
> the record of why the table had the shape a 1.7 site's upgrade finds.

Decided while fixing #121.

`RevalidateQueue::create_table()` declared the queue as:

```sql
CREATE TABLE `{$table_name}` (
    id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    permalink  text                NOT NULL,
    priority   int(10)             NOT NULL DEFAULT 10,
    UNIQUE KEY permalink (permalink),
    PRIMARY KEY  (id)
) $charset_collate;
```

A `UNIQUE KEY` over a `TEXT` column with no prefix length. **MariaDB alone
accepts that.** From 10.4 it supports a `UNIQUE` constraint over a `BLOB`/`TEXT`
column by maintaining a hidden hash column behind it — run against wp-env's
database, `SHOW INDEX` reports the key as `Index_type: HASH` with
`Sub_part: NULL`, which is that feature and nothing else. Standard MySQL has no
equivalent: a `BLOB`/`TEXT` column in a key requires an explicit prefix length,
and without one the statement is error 1170, *"used in key specification without
a key length"*. Run against MySQL 5.7.44 and 8.0.46, the statement above is
refused with exactly that error, and the one below is accepted.

`dbDelta()` inspects nothing it runs, and `create_table()` inspects nothing
`dbDelta()` did. So on MySQL the consequence is not a missing index, it is **no
queue table at all** — and every enqueue on such a site fails, silently, for as
long as the plugin has been installed there. The whole test suite is green
throughout, because every test in it runs on the one engine where the statement
is legal.

## The decision

**The permalink is stored whole, and keyed through a fixed-width hash of itself.**

```sql
CREATE TABLE `{$table_name}` (
    id              bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    permalink       text                NOT NULL,
    permalink_hash  char(64)            NOT NULL,
    priority        int(10)             NOT NULL DEFAULT 10,
    UNIQUE KEY permalink_hash (permalink_hash),
    PRIMARY KEY  (id)
) $charset_collate;
```

`permalink_hash` is the column MariaDB was maintaining invisibly, made explicit
and portable: one entry per distinct permalink, expressed in a way every engine
WordPress supports can keep, and now the *same* constraint on every install
rather than one guarantee on the database the tests run against and another on
the database a site runs.

It is not quite the constraint MariaDB kept, and the difference is deliberate.
The old key and the old `WHERE permalink = %s` compared permalinks under the
column's collation — `utf8mb4_unicode_520_ci` on most sites, so `/Foo/` and
`/foo/` were one entry. A digest compares bytes, so they are now two. They are
two paths to the front-end, which routes case-sensitively, so two entries is the
honest answer; and a migration only ever splits what the old key merged, so it
cannot manufacture a duplicate the new key would refuse.

**The hash is the queue's identity of an entry, everywhere.** The lookup that
decides whether a permalink is already queued, the `UPDATE` that promotes it, and
the key that refuses a duplicate all read `permalink_hash`. Splitting them —
looking up by `permalink` and constraining by its hash — would let the two
disagree about whether two entries are one entry, which is the only way an
astronomically unlikely digest collision could turn into something worse than a
revalidation not happening.

**sha256, and it is not a security boundary.** It names a row. Nothing
authenticates with it, nothing is derived from it, and an attacker who could mint
two colliding permalinks *on a site they already publish to* would win one
suppressed revalidation. The algorithm is a constant, `PERMALINK_HASH_ALGO`, and
`tests/queue-schema-portability-test.php` pins the column's declared width
against the digest that has to fit in it — in every place the width is declared,
because the migration declares it too and a column one character short would
truncate every hash to a common prefix, turning the unique key from a dedup into
a refusal of everything.

**The `SELECT`-then-`INSERT` race is closed by the key, which is what the key was
always for.** ADR 0021 left it open and named it as belonging here: two enqueues
of a permalink the queue does not hold can both read nothing and both insert. One
of them is now refused by the unique key, and that one re-reads the row the other
wrote and treats the answer as what it is — the permalink is queued, and the
priority it asked for is still owed, so it promotes.

The re-read comes *after* the loser's `COMMIT`, and it is a plain read. Inside
the transaction a plain read answers from the snapshot the first read opened,
under which the winner's row does not exist. `SELECT … FOR UPDATE` would see past
the snapshot, and it deadlocks. A refused insert
holds a shared lock on the row it collided with until its transaction ends, so
with three enqueues racing, the two losers each hold a shared lock and each ask
for an exclusive one, and InnoDB rolls one of them back — answering `false` for a
permalink that is queued. Ended, the transaction holds nothing, and the next read
is fresh. The promotion needs no lock of its own: its `UPDATE` repeats the
comparison in its `WHERE`, so it can only ever move the entry earlier.

No read here locks. A locking read over a row that is not there locks the gap the
permalink would sort into, which would serialise enqueues that have nothing to do
with each other.

**Existing tables migrate, guarded on their own shape.** `migrate_table()` runs
from `Settings::migrate_db()`, alongside the settings split and the log move and
for the same reason: every site predating the migration ledger is backfilled to
the release that introduces this, so a version gate would be read after the site
had already been stamped past it and would never fire for anybody
(`backfill_db_version()` has the argument in full). It has two populations to
serve, and the second is the louder one — a site whose `CREATE TABLE` was refused
has no table, and gets one here.

**An enqueue migrates too, when its write fails on a table not yet in this
shape.** The admin request is the trigger the other data migrations wait for,
and it is the wrong one to wait for here. Until it arrives, an upgraded MariaDB
table has no `permalink_hash` column, and the lookup and the insert both name it
— so every enqueue from cron, a REST client or a scheduled post going live would
fail, on a site that was revalidating fine before the upgrade, for as long as
nobody opened wp-admin. On a network too large for the migration sweep, that is
indefinitely for most of its sites. `add_item()` therefore asks, once a write has
failed and only then, whether the table carries its key; if not, it migrates and
writes once more. A site in its current shape never pays for the question.

The guard reads for the *key* rather than for the column, because the column
arrives first and the key last: a request that died between them leaves a table a
column-guard would call finished.

## Considered Options

**A prefix length — `UNIQUE KEY permalink (permalink(191))`.** The conventional
WordPress answer, portable, and a one-line change with a one-statement migration.
Rejected, and it is the option worth arguing against at length because it is the
one a reader will reach for.

It keys a *prefix*. Two distinct permalinks sharing their first 191 characters —
a deep hierarchy with long slugs, a site whose permalinks carry a query string —
are one key, so the second one's insert is refused as a duplicate of a page it is
not. The queue then reports the permalink as already waiting, the caller is told
`true`, and that page never revalidates. That is worse than what it fixes: the
bug it replaces is loud on the sites it affects (no table, nothing revalidates at
all), and this one is silent and affects exactly the permalinks nobody checks.

191 rather than some larger number is not a free choice either: it is the utf8mb4
index limit under InnoDB's older row formats, and a length chosen against a
modern server's 3072-byte limit would refuse to be created on an older one —
which is the family of problem this ADR is about.

**`VARCHAR(191)`, keyed whole.** The same truncation question, moved into the
stored data where it also loses the permalink. A queue entry that cannot say
which page it is for is not a queue entry.

**Keep the schema and make the PHP dedup authoritative.** Tempting, because the
dedup *is* in PHP already — the unique key has never been what `add_item()`
consults. It founders on the race: making a read-then-write atomic without a
unique constraint means either a locking read, which on an unindexed `TEXT`
column is a table scan that locks every row and gap it passes, or a lock outside
the database. Both are heavier than the column this adds, and neither leaves the
table with anything a second writer — a WP-CLI command, a plugin, a restored
dump — is held to.

**Declare MariaDB a requirement and leave the schema alone.** Honest about what
the code does today, and a large fraction of WordPress installs run MySQL. It
also converts a fixable defect into a support policy, and the plugin would then
have a database floor that nothing in it enforces or reports: the failure on a
MySQL site stays exactly as silent as it is now.

**Nothing — the concern is theoretical.** The issue raised this as the reading to
rule out first, on the evidence that a plugin creating no table on MySQL would
have been reported by now. It was not ruled out, and the argument cuts the other
way once the failure is traced: a site with no queue table enqueues nothing,
revalidates nothing, and looks to its operator like a front-end that is not
updating — which is indistinguishable from an unconfigured site, a wrong secret,
or a front-end that does not serve the endpoint. There is no notice anywhere that
names a missing table. An unreported bug is weak evidence when the bug's symptom
is the one everybody already has a different explanation for.

**`INSERT … ON DUPLICATE KEY UPDATE`, now that the key is dependable.** ADR 0021
rejected it *because* the key was not dependable, and said to revisit if this
issue concluded otherwise. This issue does conclude otherwise, and the answer is
still no — for the other reason ADR 0021 gives, which portability was never the
whole of. The statement's return value is an affected-row count: `1` for an
insert, `2` for an update, and **`0` when the new value equals the old**. #93
reads a falsy `add_item()` for failure, so the idempotent case — the second
identical request a retrying deploy hook sends — would report failure. The
explicit read-then-branch also has somewhere to put the promotion's log line,
which a single statement does not.

## Consequences

**The dedup now means the same thing on every install.** It is the only claim in
this repository's test suite that was true of the test database rather than of
the plugin. `QueueTestCase::assertQueueRevalidatesAtPriorities()` keys by path
and says in its docblock that this is lossless "only because the queue's
`permalink` column is UNIQUE"; that sentence was true on MariaDB and false
elsewhere, and now names the column that carries the constraint.

**A migrated table and a created one are the same table.** The column is added
with a `DEFAULT ''` the created shape does not carry — a `NOT NULL` column added
to a table with rows has to say what those rows get, and what a server does when
it does not is its SQL mode's business — and the default is dropped again once
the rows are hashed.

**Existing rows are hashed in PHP, one `UPDATE` each.** The server's own `SHA2()`
would be one statement, and it is not portable in the way this change is about: a
MySQL built without SSL support answers `NULL`, into a `NOT NULL` column. The
cost is bounded by the *pending* queue, which a running site drains continuously;
a site caught mid-revalidate-all pays for the rows it is holding, once.

**A table that cannot be created or migrated says so.** `dbDelta()` reports
nothing it runs, which is how #121 went unnoticed. `create_table()` now checks
that the table exists afterwards, and `migrate_table()` that the key does, and
each writes the server's own error to the plugin's log when it does not. That is
also the runtime report: an enqueue that fails on an unmigrated table runs the
migration, and the migration is what logs.

**The migration reads two pieces of table metadata on every admin request.** A
`SHOW TABLES` and a `SHOW INDEX`, for as long as the plugin is installed. That is
the price of a data guard — the ledger cannot gate a migration introduced by the
release that first stamps every site with it — and it is the same price
`split_legacy_url()` and `Logger::migrate_legacy_log()` pay in their own currency.

**A duplicate permalink in a table whose key was absent is deleted, not merged.**
Nothing this plugin creates can hold one, but a hand-made table or a restored
dump can, and the unique key would then refuse to be created at all — on every
admin request, forever. The survivor is the entry that would have drained first:
the more urgent priority, and the earlier arrival within it.

**No database floor is declared, and none is needed now.** The plugin's schema
asks for nothing WordPress does not already require of its own tables. That was
the documentation question #121 raised, and this is the answer: there is nothing
to document, because there is no longer anything to require.

**The portability rule is held by a test rather than by this document.**
`tests/queue-schema-portability-test.php` reads the `CREATE TABLE` this plugin
composes and holds every key in it to the rule the two engines disagree about. It
runs in the gate (ADR 0006) because it needs no database — which is also its
limit: it cannot run the statement, on MySQL or anywhere else. `QueueSchemaTest`
in the integration suite reads the key back off a real table, and runs where
there is one.

Those tests do real DDL, which the test library does not expect. It rewrites
every `CREATE TABLE` and `DROP TABLE` a test issues into the `TEMPORARY` form, so
a test that "dropped" the queue table left the real one in place and passed
without ever reaching the code it named. `QueueSchemaTest` switches that rewrite
off for itself, asserts the precondition it set up before acting on it, and
rebuilds the real table after every test. wp-env's database is MariaDB, so the
MySQL branch of those tests — the legacy table refused, the test skipped — runs
only when the suite is pointed at a MySQL server.
