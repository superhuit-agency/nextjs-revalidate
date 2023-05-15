
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




