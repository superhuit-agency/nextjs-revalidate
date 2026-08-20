=== Next.js Revalidate ===
Contributors: kuuak
Tags: Next.js, Nextjs, Next, Cache, revalidate, Purge
Requires at least: 5.0
Tested up to: 6.1
Requires PHP: 7.4
Stable tag: 1.6.9
license: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

=== Description ===

Next.js plugin allows you to purge & re-build the cached pages from the WordPress admin area.
It also automatically purges & re-builds when a page/post/... is save or updated.

The revalidation request is sent to an endpoint composed from the settings — the
revalidate domain joined to the revalidate path — with two query arguments.

1. The relative `path` to revalidate
2. The `secret` to protect the revalidation endpoint.

The domain and the secret are required. The revalidate path (default
`/api/revalidate`) and the FSE revalidate path (default `/api/revalidate-fse`)
are optional, for apps that route those endpoints elsewhere.

Sites upgrading from 1.6.x had a single, fully-qualified revalidate URL. It is
split into a domain and a path automatically on the first admin request after
the upgrade, custom paths and all.

== Example ==
```
https://example.com/api/revalidate?path=/hello-world/&secret=my-super-secret-string
```

> Base on the Next.js [On-demand revalidation](https://nextjs.org/docs/basic-features/data-fetching/incremental-static-regeneration#on-demand-revalidation) documentation

