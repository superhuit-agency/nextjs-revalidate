# Changelog

What changed in each release of Next.js Revalidate, newest first, and what a
site has to do about it. Releases before 2.0.0 are listed in the changelog of
[`readme.txt`](readme.txt).

## 2.0.0

2.0 changes the request the plugin sends to the front-end, and nothing else a
site's PHP relies on
([ADR 0035](docs/adr/0035-v2-breaks-the-wire-and-nothing-else.md)). **Deploy it
together with the matching change to your Next.js route**: a route written for
1.x cannot read the request 2.0 sends.

### Breaking: the request to the front-end

1.x asked the front-end to revalidate one path at a time:

```
GET {revalidate URL}?path=/hello-world/&secret=my-super-secret-string
```

2.0 reports **changes** — what happened to which WordPress subject — and leaves
which cache tags they expire to the front-end
([ADR 0033](docs/adr/0033-the-plugin-reports-changes-not-tags.md)):

```http
POST {revalidate domain}{revalidate path}
Authorization: Bearer <secret>
Content-Type: application/json

{ "version": 2, "changes": [ { "subject": "post", "id": 42, "type": "post", "before": { "uri": "/hello/" }, "after": { "uri": "/hello-world/" } } ] }
```

- **The secret travels in the `Authorization` header**, never in the URL.
- **Six subjects** — `post`, `redirect`, `path`, `menu`, `templates` and `all` —
  each with the fields the README's
  [front-end contract](README.md#the-front-end-contract) lists. A post change
  carries where the post was before and where it is after, so a slug change now
  reaches the old path too.
- **One route for everything.** A saved FSE template or a switched theme is a
  `templates` change on the same route, not a request to a second endpoint.
- **Revalidate all is one change**, and a menu save is one change, rather than a
  request per page.
- **Any 2xx is a success**, where 1.x wanted a 200. A 3xx, a 4xx, a 5xx, or no
  answer within **five seconds** — down from sixty — is a failure.
- **Changes are sent when the WordPress request that produced them ends**, in
  one request, or in several of up to 100 changes for a long one such as an
  import. Nothing waits for cron.

The README carries the whole contract, the two rules that let it grow without
breaking you, and a [reference route](examples/next/app/api/revalidate/route.ts)
in TypeScript to copy.

### Removed settings

Three settings are gone, and their stored values are deleted from every site on
the first admin request after the upgrade:

- **FSE revalidate path** (`nextjs_revalidate-fse_endpoint_path`) — there is one
  route now, the **Revalidate path**.
- **Revalidate on FSE update** (`nextjs_revalidate-revalidate-on-fse-save`) — a
  template change is always reported; a front-end that does not cache templates
  ignores it.
- The post-type switches of the **On menu update** tab
  (`nextjs_revalidate-revalidate-on-menu-save`) — a menu save reports one `menu`
  change, so there is no longer a revalidate all per menu save to bound.

### Retired: the `nextjs_revalidate_purge_action_permalink` filter

It rewrote or declined the permalink the row action, the bulk action and the
admin bar entry revalidated. A post change is keyed by the post's ID and carries
no permalink to rewrite, so the filter is **no longer applied**; a site still
hooking it gets a `_deprecated_hook()` notice under `WP_DEBUG` naming the
replacement. This is the one PHP change 2.0 can ask of a site.

Move the callback to **`nextjs_revalidate_change`**, which receives every change
before it is sent — from every entry point, not only those three — and returns
it, altered or not, or `false` to drop it:

```php
// 1.x
add_filter( 'nextjs_revalidate_purge_action_permalink', function( $permalink, $post_id ) {
	return 123 === $post_id ? false : $permalink;
}, 10, 2 );

// 2.0
add_filter( 'nextjs_revalidate_change', function( $change ) {
	if ( 'post' === $change['subject'] && 123 === $change['id'] ) return false;
	return $change;
} );
```

### Deprecated, until 3.0

The 1.x names keep working, as wrappers around the 2.0 ones, and warn under
`WP_DEBUG`:

| 1.x | 2.0 |
| --- | --- |
| `nextjs_revalidate_purge_url( $url, $priority )` | `nextjs_revalidate_path( $url )` — `$priority` is accepted and ignored |
| `nextjs_revalidate_schedule_purge_url( $datetime, $url )` | `nextjs_revalidate_schedule_path( $datetime, $url )` |
| the `nextjs_revalidate_purge_should_revalidate_post_on_save` filter | `nextjs_revalidate_should_revalidate_post`, asked by every entry point as it always was |

The inbound REST routes are unchanged, under `nextjs-revalidate/v1`; `priority`
is still accepted there, and ignored.

### Dropped: the revalidation queue, and whatever it still held

The queue, its table, its cron and its **Queue** tab are gone
([ADR 0034](docs/adr/0034-changes-are-delivered-when-the-request-ends.md)). On
the first admin request after the upgrade — on a network, for every site at
once — the plugin drops each site's `{prefix}revalidate_queue` table and
unschedules its cron. **Paths still waiting in the queue are dropped, not
sent.** Their number is written to the log file when logging is on:

```
🗑️ Upgraded to 2.0: dropped the revalidation queue, and the 12 path(s) still waiting in it
```

That is safe because 2.0 ships with a front-end deploy, and a Next.js deploy
starts from an empty cache: every path still queued is already fresh on the new
front-end. A site upgrading straight from 1.6.x is migrated the same way, with
its single revalidate URL split into a domain and a path in the same request.

### Also in 2.0.0

- **Added:** the `nextjs_revalidate_change` filter, above.
- **Changed:** the admin says **Revalidate** where it said **Purge** — the row
  action, the bulk action, the admin bar menu and its entries, and their
  notices, which now say that a revalidation was sent rather than counting pages
  left to purge. The French translation follows.
- **Changed:** the plugin is named **Next.js Revalidate**.
