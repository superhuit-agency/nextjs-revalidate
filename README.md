
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

### nextjs_revalidate_purge_url

Allows to purge & re-build aby URL. Return a boolean to indicate whether the purge has been successful.

#### Usage
```php
nextjs_revalidate_purge_url( $url );
```

#### Arguments

| Name | Type | Description |
| --- | --- | --- |
| url  | string | The URL to purge |

### nextjs_revalidate_schedule_purge_url

Schedule a URL purge from Next.js cache. Will triggers a revalidation of the given URL at the given date time. Returns a boolean tp indication whether the schedule is registered.

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

## Which posts are revalidated

A post is revalidated when the front-end could hold a page for it: its post type
is viewable — WordPress's own `publicly_queryable` test, via
[`is_post_type_viewable()`](https://developer.wordpress.org/reference/functions/is_post_type_viewable/)
— and its status is `publish` or `private`, or it has just left `publish` for
`draft` or `trash`. Posts of a post type that is not viewable are never
revalidated, whatever their status.

A headless site registering post types with `publicly_queryable => false` while
its front-end still renders their permalinks can say so with the filter below.

## Filters

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
