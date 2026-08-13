# Tests that need WordPress boot it; tests that don't must not, and the queue cannot inherit its own isolation

Decided while triaging #56, before any of the harness existed.

This repo will have two ways to run tests, and that is a decision rather than an
accident. The split is not by subject matter or by taste — it is by whether the
unit under test needs real WordPress state.

## The rule

**If the unit under test is reachable by stubbing a handful of WordPress
functions, it is a standalone script.** It defines its stubs, requires the file
under test, and exits non-zero on the first bad expectation. No framework, no
autoload, no database, no Docker. `npm run test:php` runs these.

**If it needs real WordPress state — the revalidation queue, options, posts,
`switch_to_blog()` — it is a PHPUnit test** on `WP_UnitTestCase`, run against
wp-env's tests environment under its own command.

## Why not one idiom

The obvious tidy-up is to port the standalone scripts into PHPUnit and have a
single suite. That spends the only property those scripts have that the PHPUnit
suite can never have: **they need nothing.**

The AFK sandbox (ADR-0006) is a container with no Docker and no MySQL. The
standalone scripts run inside it; a wp-env suite cannot, and no amount of work on
the wp-env suite will change that. So the scripts are the only tests in this repo
that could ever join the gate an unattended agent is judged by. Converting them
to PHPUnit would forfeit that to remove an inconsistency.

The cost is real and is not being denied: a contributor now has to decide which
idiom a new test belongs to, and the answer is only obvious once you know why the
split exists — hence this record. The tension is filed as its own issue rather
than resolved by making the repo tidier and less capable.

The related trap, worth naming because it looks like a kindness: making
`npm run test:php` an umbrella that runs both suites. The moment that command
requires Docker, the obvious next move — putting it into the ADR-0006 gate
alongside `typecheck` and `lint:php` — becomes impossible, and it fails in the
sandbox for a reason nobody will connect to this decision. The commands stay
distinct.

## The queue does not get transactional isolation, and must reset itself

`WP_UnitTestCase` isolates each test by opening a transaction and rolling it back
on teardown. That works for posts, options and transients. **It does not work for
the revalidation queue.**

```php
// RevalidateQueue::add_item(), in production code:
$wpdb->query( "START TRANSACTION" );
// …
$wpdb->query( "COMMIT" );          // ← commits the *test's* transaction too
```

MySQL has no nested transactions, so the plugin's own `COMMIT` commits everything
open, and queue rows survive the rollback into the next test. `TRUNCATE` and the
table's DDL force implicit commits for the same reason.

Therefore: the queue table is created **once, in bootstrap**, outside any
transaction, and a shared base test case **truncates it and resets its
`AUTO_INCREMENT` in `setUp`**. Isolation for this one table is something the
harness implements; everything else inherits it. A test asserting isolation —
two enqueues in one file, the second observing only its own entry — is what keeps
that honest, because the failure mode only appears on the *second* run and is
invisible in a source review.

## Considered Options

**Change `add_item()` to stop managing its own transaction** and let rollback do
the work. Rejected: it alters production behaviour on the exact code path #49 and
#50 are open against, to suit a test framework, and it would land untested by
definition — modifying the thing the harness is being built to be able to test.
If the hand-rolled transaction is wrong, that is its own issue, argued on its own
merits, with the harness already in place to prove it.

**A fresh database per test file** rather than per test. Slower, and it buys
nothing the explicit reset does not.

**Adding PHPUnit to `require-dev`.** wp-env already clones
`WordPress/wordpress-develop`, mounts the test library, sets `WP_TESTS_DIR` and
ships a `phpunit` binary — so the suite needs no new dependency at all. A
composer PHPUnit would enable a faster CI job that skips wp-env, at the price of
a *third* way to run tests and two `WP_UnitTestCase` versions that have to agree.
Having just declined to merge two idioms, adding a third would be a strange move.
