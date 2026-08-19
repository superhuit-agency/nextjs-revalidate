
# Next.js revalidate

Next.js plugin allows you to purge & re-build the cached pages from the WordPress admin area.
It also automatically purges & re-builds when a page/post/... is saved or updated.

The revalidation request will be sent to the configured URL endpoint with two query arguments.

1. The relative `path` to revalidate
2. The `secret` to protect the revalidation endpoint.

### Example
```
https://example.com/api/revalidate?path=/hello-world/&secret=my-super-secret-string
```

> Based on the Next.js [On-demand revalidation](https://nextjs.org/docs/basic-features/data-fetching/incremental-static-regeneration#on-demand-revalidation) documentation

## Requirements

- Requires PHP 7.4+
- Requires WordPress 5.0+

## API functions

Neither function can tell you whether the front-end has rebuilt anything. Each
answers what the plugin took on: `nextjs_revalidate_purge_url` whether the
revalidation was **accepted** into the queue, and
`nextjs_revalidate_schedule_purge_url` whether the schedule was registered —
which is one step further away, since that revalidation is only enqueued when
the date time passes, and can be refused then. The queue is drained afterwards,
by cron, so there is no return value in this plugin that could report the
outcome of a delivery that has not happened yet. Delivery is at most once — a
revalidation that is attempted and fails is written to the log and dropped,
never retried.

### nextjs_revalidate_purge_url

Enqueues a revalidation of any URL, to be delivered to the front-end by the next
run of the queue's cron.

#### Usage
```php
nextjs_revalidate_purge_url( $url );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| url  | string | The URL to purge |
| priority | int | Optional. Lower numbers are purged earlier; equal priorities keep insertion order. Default `10`. |

#### Returns

`bool` — whether the revalidation was accepted into the queue. It is `false`
when the site is unconfigured, which is a **refusal**: the revalidate URL or the
secret is missing, nothing has been queued, and nothing will be. It is also
`false` if the queue insert failed. A URL already waiting in the queue is
accepted (`true`) without being queued twice.

It is never a statement about the front-end. A `true` says the plugin will try.

### nextjs_revalidate_schedule_purge_url

Registers a revalidation of the given URL for a future date time. Nothing is
enqueued until then, so a schedule registered on a configured site is still
refused at its due time if the site is unconfigured by then.

#### Usage
```php
nextjs_revalidate_schedule_purge_url( $datetime, $url );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| datetime  | string | The date time when to purge |
| url  | string | The URL to purge |

#### Returns

`bool` — whether this call registered the scheduled purge. It is `false` when
that URL is already registered for that date time: the schedule stands, and this
call added nothing to it. It is also `false` if the write failed.

## Which posts are revalidated

A post is revalidated when the front-end could hold a page for it: its post type
is viewable — WordPress's own `publicly_queryable` test, via
[`is_post_type_viewable()`](https://developer.wordpress.org/reference/functions/is_post_type_viewable/)
— and its status is `publish` or `private`, or it has just left `publish` for
`draft` or `trash`. Posts of a post type that is not viewable are never
revalidated, whatever their status.

A headless site registering post types with `publicly_queryable => false` while
its front-end still renders their permalinks can say so with the filter below.

## Integrations

An integration is a third-party plugin whose changes this plugin reacts to when
that plugin is present. It is never a dependency: with the plugin absent nothing
registers, and no feature here requires one.

### Redirection

A headless front-end resolves a redirect inside the cached page of the path it
redirects *from*, so creating, editing, deleting, enabling or disabling a
redirect in [Redirection](https://wordpress.org/plugins/redirection/) leaves that
path answering as it did before until its cache entry expires. With Redirection
active, this plugin enqueues a revalidation of the source path whenever a
redirect changes, so the redirect starts — or stops — working within the time the
queue takes to drain.

Only a redirect the front-end could resolve for a single path produces a
revalidation: its source is a literal path rather than a regular expression, and
it is enabled. A regular expression source matches an unbounded set of paths, so
there is no single path to rebuild; it is skipped, and the skip is written to the
log when logging is switched on. Source paths are reduced to their path
component, dropping any query string or domain the source was stored with, and
given the site's trailing slash convention, so they match the form post
permalinks are enqueued in.

Changing a redirect's *source* revalidates the path it stopped redirecting as
well as the new one, on Redirection versions whose update action carries the
redirect's previous state. Redirection 5.9.0 passes the redirect's id instead,
where only the new source path is revalidated.

## Filters

### nextjs_revalidate_should_revalidate_redirect

Filters whether a redirect's source path is revalidated. Return `false` to leave
the path alone — the escape hatch for a site whose front-end resolves redirects
some other way.

#### Usage
```php
add_filter( 'nextjs_revalidate_should_revalidate_redirect', function( $should_revalidate, $path, $redirect ) {
	if ( 0 === strpos( $path, '/legacy/' ) ) return false;
	return $should_revalidate;
}, 10, 3 );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| should_revalidate | bool | Whether the source path is revalidated |
| path | string | The source path, normalised |
| redirect | object | The redirect the path is the source of, as Redirection's own `Red_Item` |

### nextjs_revalidate_purge_should_revalidate_post_on_save

Filters whether the given post is revalidated. Applied last, so it can admit a
post the rules above decline, or decline one they admit.

#### Usage
```php
add_filter( 'nextjs_revalidate_purge_should_revalidate_post_on_save', function( $should_revalidate, $post_id ) {
	if ( 'my-headless-type' === get_post_type( $post_id ) ) return 'publish' === get_post_status( $post_id );
	return $should_revalidate;
}, 10, 2 );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| should_revalidate | bool | Whether the post is revalidated |
| post_id | int | The post ID |

### nextjs_revalidate_purge_action_permalink

Filters the permalink added to the purge queue by the "Purge cache" row and bulk
actions. Return `false` to keep it out of the queue.

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| permalink | string\|false | The post permalink. False if the post is not revalidatable |
| post_id | int | The post ID |


## Tests

Two commands, and which one a test belongs to depends on whether it needs real
WordPress state. See `docs/adr/0008-two-testing-idioms.md`.

### `npm run test:php` — standalone scripts

Scripts under `tests/` that stub the handful of WordPress functions their
subject touches. No framework, no database, no Docker; they run anywhere PHP
does, including a sandbox with neither.

### `npm run test:integration` — the integration suite

PHPUnit tests under `tests/integration/` that boot WordPress with this plugin
active and assert on the **revalidation queue**: given some WordPress state and
an event, which paths does the queue revalidate, in what order, at what
priority?

From a fresh checkout:

```bash
npm install
composer install
npm run test:integration
```

wp-env installs the Redirection plugin alongside this one, in both environments,
so the redirect integration can be exercised without assembling an install by
hand. The suite's bootstrap loads it and creates its tables when it is there, and
skips the tests that need it when it is not.

The command starts wp-env itself — `wp-env start` is idempotent, so running it
again costs seconds. It runs with `--no-scripts` and against the **tests**
environment, so the development site keeps its database and its settings;
wp-env does bring the development containers up alongside the tests ones, which
means port 8080 has to be free. Docker must be running. The first run downloads
WordPress and its PHPUnit test library and takes a few minutes.

Write a test by extending `NextJsRevalidate\Tests\QueueTestCase`, which
configures the site, enqueues paths and reads the queue back:

```php
$this->configure_site();                       // a configured site — an
                                               // unconfigured one refuses
$this->enqueue( '/hello-world/', 5 );          // enqueue a path at a priority
$this->assertQueueRevalidates( [ '/hello-world/' ] );  // paths, in drain order
$this->assertQueueRevalidatesAtPriorities( [ '/hello-world/' => 5 ] );
$this->assertQueueHolds( [ home_url( '/hello-world/' ) ] ); // the permalinks
```

The queue **holds permalinks**, and those permalinks **revalidate paths** — the
two are kept apart because on a network they can disagree. `assertQueueHolds()`
takes permalinks; `assertQueueRevalidates()` takes paths and normalises against
the site's home url, so a test survives a change of `testsPort`.

The queue table is created once in the bootstrap and emptied around every test.
It has to be: `RevalidateQueue::add_item()` runs its own transaction, whose
`COMMIT` also commits the one `WP_UnitTestCase` uses to roll a test back.

## Checks

Four npm scripts, and they are the whole gate:

```bash
npm run typecheck      # tsc --noEmit over src/
npm run lint:php       # php -l over every tracked PHP file, on PHP 7.4
npm run test:php       # the standalone scripts above
npm run analyse:php    # PHPStan over the 7.4–8.4 span the plugin claims
```

`.github/workflows/ci.yml` runs all four on every pull request against `main` and
on every push to `main`. Nothing is defined there that is not an npm script here,
so a green CI run means what a green local run means — and no more: syntax,
types, PHP-range compatibility and the handful of behaviours the standalone
scripts pin. It is not a claim that a change works.

`lint:php` refuses to run on anything but PHP 7.4, the version
`nextjs-revalidate.php` declares, because a newer parser accepts PHP 8-only
syntax and proves nothing. Point `PHP_BIN` at a 7.4 binary, or set
`ALLOW_PHP_VERSION_MISMATCH=1` and know the lint proves nothing. `analyse:php`
needs `composer install` first — PHPStan is a dev dependency.

`npm run test:integration` is **not** in CI yet: it needs Docker, and wiring it up
is its own piece of work.

Requiring a green run to merge is a repo setting, not a file — branch protection
on `main`, with **Typecheck** and **PHP 7.4** as required status checks. Until
that is switched on, CI reports and merging is still possible over a red run.
