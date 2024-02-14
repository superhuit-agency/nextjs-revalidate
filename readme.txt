=== Next.js Revalidate ===
Contributors: kuuak
Tags: Next.js, Nextjs, Next, Cache, revalidate, Purge
Requires at least: 5.0
Tested up to: 6.1
Requires PHP: 7.4
Stable tag: 1.6.3
license: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

=== Description ===

Next.js plugin allows you to purge & re-build the cached pages from the WordPress admin area.
It also automatically purges & re-builds when a page/post/... is save or updated.

The revalidation request will be sent to the configured URL endpoint with two query arguments.

1. The reliative `path` to revalidate
2. The `secret` to protect the revalidation endpoint.

== Example ==
```
https://example.com/api/revalidate?path=/hello-world/&secret=my-super-secret-string
```

> Base on the Next.js [On-demand revalidation](https://nextjs.org/docs/basic-features/data-fetching/incremental-static-regeneration#on-demand-revalidation) documentation

