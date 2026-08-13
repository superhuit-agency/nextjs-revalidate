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

## Amended once the harness was built

Two of the decisions above were made from the wp-env documentation and did not
survive contact with the containers. The decisions they served are unchanged;
the means are not.

**The suite does need one new dev dependency: `yoast/phpunit-polyfills`.** The
claim that wp-env ships everything was half right — it clones
`WordPress/wordpress-develop` sparsely, `tests/phpunit` only, without that
clone's `vendor/`. The WordPress test bootstrap requires the Polyfills and
exits if it cannot find them, and points plugin authors at exactly this: a dev
requirement in the plugin's own `composer.json`. It is `require-dev`, so
`composer install --no-dev` in the release workflow leaves it out of the zip.

**The suite runs `vendor/bin/phpunit` inside the container, not the container's
own `phpunit`.** The Polyfills require PHPUnit, so composer installs one
regardless; running the container's global PHPUnit alongside it would put two
PHPUnit versions in one process, with the plugin's composer autoloader able to
resolve a class to the wrong one. One version, pinned by `composer.lock`, is
both safer and more reproducible than "whatever `@wordpress/env` ships".

**Consequently `composer.json` now pins `config.platform.php` to 7.4.** Composer
resolves against the PHP it is run on, and on a modern one it picked a PHPUnit
requiring PHP 8.1 — which would have broken `composer install` for anyone on the
7.4 the plugin declares. Pinning the platform to the declared floor resolves
every dependency for the version the plugin actually claims to support.

### Three smaller things the build settled

**The reset goes through the queue's own `reset_queue()`, by reflection.** The
decision above says the base case truncates the table and resets its
`AUTO_INCREMENT`; doing that literally would mean writing `$wpdb->prefix .
'revalidate_queue'` in test code, which is the one expression this suite must
not own. `reset_queue()` is private, so the harness reflects into it. The cost
is a test suite coupled to a private name: rename it and the suite fails at
runtime, where neither `lint:php` nor PHPStan — which reads `include/` only —
will have warned. That is the cheaper of the two couplings, but it is a real one,
and the argument for widening the queue's surface instead is open.

**Pinning `config.platform.php` moved no existing package.** The lock was rebuilt
from `main`'s and re-resolved for the Polyfills alone, then diffed: every
pre-existing package, production and dev, kept its version. The lock grows by
the PHPUnit tree and nothing else.

**The release zip was verified rather than argued about.** With
`composer install --no-dev`, the workflow's own rsync allowlist was run over a
checkout and the payload searched: no test file, no phpunit, no polyfill. To
redo it, run the `rsync --files-from` block of
`.github/workflows/release-plugin.yml` and search the result.
