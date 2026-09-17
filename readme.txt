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

== Integrations ==

An integration is a third-party plugin whose changes this plugin reacts to when
that plugin is present. This plugin supports an integration; it never requires
one. With that plugin absent nothing registers, no feature here needs it, and
the revalidation of the site's posts is unaffected either way.

= Redirection =

A headless front-end resolves a redirect inside the cached page of the path it
redirects *from*, so a redirect change leaves that path answering as it did
before until its cache entry expires. With the
[Redirection](https://wordpress.org/plugins/redirection/) plugin active, this
plugin enqueues a revalidation of the redirect's source path whenever a redirect
changes. The changes that trigger one:

1. A redirect is created.
2. A redirect is edited.
3. A redirect is deleted.
4. A redirect is enabled.
5. A redirect is disabled.

Installing Redirection switches the behaviour on, and removing it switches it off
again; neither can break the revalidation of posts, and there is no setting to
turn the integration on or off.

Editing a redirect's *source* revalidates the path that stops redirecting as well
as the new one, on Redirection versions whose update action carries the
redirect's previous state. Redirection 5.9.0 and later pass the redirect's id
instead, where only the new source path is revalidated and the old one keeps
redirecting until its own cache entry expires.

Only a redirect the front-end could resolve for a single path is revalidated: its
source is a literal path rather than a regular expression, and it is enabled —
except when it is being disabled, which is itself the change the front-end has
not heard about yet.

**A redirect whose source is a regular expression is skipped entirely.** It
matches an unbounded set of paths, so there is no single path to rebuild and
nothing is enqueued for it: the front-end keeps serving the page it already
holds, with nothing on screen to say why. The skip is recorded in the plugin's
log file (`wp-content/uploads/nextjs-revalidate.log`) and nowhere else, and only
while **Enable logs** is switched on under the **Debug** tab of *Settings →
Next.js revalidate*.

A bulk operation — deleting, enabling or disabling many redirects at once, or an
import creating them — reaches this plugin once per redirect, and enqueues one
revalidation per **distinct** source path: redirects sharing a source cost a
single queue entry. Nothing is capped. The queue is drained by cron rather than
in the request that filled it, so a large import reaches the front-end over the
following cron runs rather than immediately.

== Filters ==

= nextjs_revalidate_should_revalidate_redirect =

Filters whether a redirect's source path is revalidated. Return `false` to leave
the path alone — the escape hatch for a site whose front-end resolves redirects
some other way, from build-time configuration, from middleware, or from anywhere
a per-path revalidation does not reach. A site can decline a redirect
revalidation this way without deactivating the plugin or losing the revalidation
of its posts.

```php
add_filter( 'nextjs_revalidate_should_revalidate_redirect', function( $should_revalidate, $path, $redirect ) {
	if ( 0 === strpos( $path, '/legacy/' ) ) return false;
	return $should_revalidate;
}, 10, 3 );
```

It receives whether the path is to be revalidated, the normalised source path,
and the redirect itself as Redirection's own `Red_Item`. It is applied last and
asked once per path, so an edit that changes a redirect's source puts the old
path and the new one to it separately, and either can be declined on its own.

The filter declines; it cannot admit. A redirect that was never a candidate —
one whose source is a regular expression — returns before the filter is reached,
so returning `true` there revalidates nothing.

== Changelog ==

= 1.7.0 =

* Added: the Redirection integration. Creating, editing, deleting, enabling or
  disabling a redirect enqueues a revalidation of its source path, so a headless
  front-end stops serving the cached page that resolved the old answer. Editing a
  source revalidates the old path as well as the new one, on Redirection versions
  whose update action carries the redirect's previous state. Redirects whose
  source is a regular expression are skipped. Redirection is supported and never
  required: with it absent the plugin is unchanged.
* Added: the `nextjs_revalidate_should_revalidate_redirect` filter, for a site
  whose front-end resolves redirects some other way.
