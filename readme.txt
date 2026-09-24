=== Next.js Revalidate ===
Contributors: kuuak
Tags: Next.js, Nextjs, Next, Cache, revalidate, Purge
Requires at least: 5.6
Tested up to: 7.1
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

Saving an FSE template or template part, resetting one to its theme default, or
switching themes sends one request to the FSE endpoint instead — the front-end
holds the whole template structure as a single cached value, so there is no page
to name. "Revalidate on FSE update" starts on for a new install and off for a
site upgrading from an earlier release, whose front-end may not serve that
endpoint yet; switch it on once it does.

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

Only a **revalidatable redirect** is revalidated — one the front-end could
resolve for a single path, which means its source is a literal path rather than
a regular expression, and it is enabled. One that is not revalidatable produces
no revalidation at all: it is not refused, it was never a candidate. Disabling
one is the single exception that does not ask whether it is enabled, because
that it stopped being enabled is itself the change the front-end has not heard
about yet.

**A redirect whose source is a regular expression is skipped entirely.** It
matches an unbounded set of paths, so there is no single path to rebuild and
nothing is enqueued for it: the front-end keeps serving the page it already
holds, with nothing on screen to say why. The skip is recorded in the plugin's
log file and nowhere else, and only while **Enable logs** is switched on under
the **Debug** tab of *Settings → Next.js revalidate* — the file's path is shown
beneath that switch.

Other reasons a redirect change enqueues nothing, each recorded in that same log
file and nowhere else:

1. The redirect is disabled. Creating, editing, deleting or enabling one that is
   stored as disabled changes nothing the front-end resolves for its source;
   disabling one is the exception, and does revalidate.
2. Its source names no path to rebuild — it is empty, it is not a URL a path can
   be read out of, or it is the bare site root.
3. A filter declined that path. See below.
4. The site is unconfigured, so the queue refuses the revalidation: the
   revalidate domain or the secret is missing, nothing is queued, and nothing
   will be until both are filled in.

Each of them is one line of the same shape, so the whole set is one grep away:

```
[2026-04-28 11:04:07]	[INFO]	[Redirection.php] ↪️ Redirect #12 not revalidated (source: ^/blog/(.*)) — its source is a regular expression, which names no single path
```

A redirect that *is* revalidated writes no line at that point. Its source path
waits under the **Queue** tab of *Settings → Next.js revalidate* until cron
drains it, and the log line — revalidated, or failed — comes from the drain.

A bulk operation — deleting, enabling or disabling many redirects at once, or an
import creating them — reaches this plugin once per redirect, and enqueues one
revalidation per **distinct** source path: redirects sharing a source cost a
single queue entry. Nothing is capped. The queue is drained by cron rather than
in the request that filled it, so a large import reaches the front-end over the
following cron runs rather than immediately. A drain is scheduled as soon as
something is enqueued, works through the queue until PHP's max execution time is
nearly up, then schedules the next one while anything is left. WordPress fires
its cron on site traffic unless a real system cron is wired up, so a quiet site
drains when somebody visits it.

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
* Added: the `nextjs_revalidate_show_unconfigured_notice` filter, for a site
  that is unconfigured on purpose, such as a network's staging subsite. Returning
  `false` hides the unconfigured notice on every admin screen, and hides the
  degraded notice in the block editor, where it stands in for the unconfigured
  one. The site still refuses and logs every revalidation, and a configured
  site's notices are unchanged.
* Changed: the plugin now requires WordPress 5.6, not 5.0. `wp_after_insert_post`
  — the hook a save revalidates from — has only existed since 5.6, so on 5.0
  through 5.5 the plugin activated and showed every admin screen, and saving a
  post silently revalidated nothing. The declared requirement now matches what
  the code needs, and from WordPress 5.2 on, WordPress itself refuses the
  activation below it.
* Fixed: permanently deleting a post now revalidates its path. Only trashing did
  before, so a post deleted outright — "Delete Permanently", `wp_delete_post()`,
  or `wp post delete` on a custom post type — left the front-end serving its
  cached page indefinitely. A post already in the trash is unchanged: trashing it
  revalidated that page, so emptying the trash enqueues nothing.
* Fixed: unpublishing a post to Pending Review, re-scheduling it to a future
  date, or moving it to a status an editorial workflow plugin registers now
  revalidates its page. Only draft and trash did before, so the front-end kept
  serving the page indefinitely. A private post moved out of private is covered
  the same way.
* Changed: the REST routes answer a failure status when nothing was queued. Every
  failed request answered 207 Multi-Status before, which is a success class — so
  a deploy hook or a CI job checking the status was told a revalidation it never
  got was fine. A request in which nothing was accepted now answers 400 when the
  items were unreadable, 503 when the site is unconfigured and 500 when a queue
  write failed; 207 is left to a batch in which some items were accepted and some
  were not, and a request whose items were all accepted still answers 200. The
  routes are documented in the README for the first time. An entry of a batch's
  `items` that is not an object is now reported as an item with no path rather
  than skipped, so it can no longer vanish from a batch that answered 200.
* Fixed: the revalidation queue's table is now created on standard MySQL. Its
  unique key was declared over a `TEXT` column with no prefix length, which only
  MariaDB accepts — everywhere else the `CREATE TABLE` was refused outright, the
  site was left with no queue table at all, and every revalidation it enqueued
  failed silently. The key now sits on a fixed-width hash of the permalink, so
  the deduplication the queue depends on means the same thing on every database.
  An existing table is migrated to that shape on the first admin request or the
  first revalidation after the upgrade, whichever comes first, carrying whatever
  it was holding; a site that never managed to create one gets it there too. A
  table that cannot be created or migrated is now reported in the log. Two
  permalinks differing only in case are now queued as the two paths they are.
