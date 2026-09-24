=== Next.js Revalidate ===
Contributors: kuuak
Tags: Next.js, Nextjs, Next, Cache, revalidate, Purge
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.0
license: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

=== Description ===

Tells a Next.js front-end which WordPress content changed — posts, menus,
templates, redirects — so it can revalidate whatever it cached from it. Changes
are reported as they happen, on save, and on demand from the admin.

The plugin reports changes as WordPress sees them and never names the
front-end's cache tags: which cache entries a change expires is the front-end's
decision, made in one route handler.

Each request that changes something ends with one request to the front-end:

```
POST {revalidate domain}{revalidate path}
Authorization: Bearer <secret>
Content-Type: application/json

{ "version": 2, "changes": [ { "subject": "post", "id": 42, "type": "post", "before": { "uri": "/hello/" }, "after": { "uri": "/hello-world/" } } ] }
```

The subjects are `post`, `redirect`, `path`, `menu`, `templates` and `all`. Any
2xx answer is a success. The full contract — every field, when it is `null`, the
two rules that let it grow, and a reference Next.js route in TypeScript — is in
the plugin's README on GitHub:
https://github.com/superhuit-agency/nextjs-revalidate#the-front-end-contract

The domain and the secret are required. The revalidate path (default
`/api/revalidate`) is optional, for an app that routes its endpoint elsewhere.

Sites upgrading from 1.6.x had a single, fully-qualified revalidate URL. It is
split into a domain and a path automatically on the first admin request after
the upgrade, custom paths and all.

**Upgrading from 1.x changes the request the front-end receives.** Deploy 2.0
together with the matching change to the Next.js route; see the 2.0.0 changelog
below.

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
plugin reports a `redirect` change for the redirect's source path whenever a
redirect changes. The changes that trigger one:

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
nothing is reported for it: the front-end keeps serving the page it already
holds, with nothing on screen to say why. The skip is recorded in the plugin's
log file and nowhere else, and only while **Enable logs** is switched on under
the **Debug** tab of *Settings → Next.js revalidate* — the file's path is shown
beneath that switch.

Other reasons a redirect change reports nothing, each recorded in that same log
file and nowhere else:

1. The redirect is disabled. Creating, editing, deleting or enabling one that is
   stored as disabled changes nothing the front-end resolves for its source;
   disabling one is the exception, and does revalidate.
2. Its source names no path to rebuild — it is empty, it is not a URL a path can
   be read out of, or it is the bare site root.
3. A filter declined that path. See below.
4. The site is unconfigured, so the change is refused: the revalidate domain
   or the secret is missing, nothing is sent, and nothing will be until both
   are filled in.

Each of them is one line of the same shape, so the whole set is one grep away:

```
[2026-04-28 11:04:07]	[INFO]	[Redirection.php] ↪️ Redirect #12 not revalidated (source: ^/blog/(.*)) — its source is a regular expression, which names no single path
```

A redirect that *is* revalidated writes no line at that point. Its change is
sent when the request that saved it ends, and the log line — revalidated, or
failed — comes from that delivery.

A bulk operation — deleting, enabling or disabling many redirects at once, or an
import creating them — reaches this plugin once per redirect, and reports one
change per **distinct** source path: redirects sharing a source cost a single
change. Nothing is capped. The changes are sent when the request that made them
ends — in one request to the front-end, or in several of up to 100 changes each
when a long request, such as an import, produces more.

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

= 2.0.0 =

2.0 changes the request the plugin sends to the front-end, and nothing else a
site's PHP relies on. Deploy it together with the matching change to the Next.js
route. The full notes are in CHANGELOG.md on GitHub.

* Changed (breaking): the request to the front-end. 1.x sent
  `GET {url}?path=…&secret=…` once per path; 2.0 sends one `POST` per request
  that changed something, with the secret in an `Authorization: Bearer` header
  and a JSON body `{ "version": 2, "changes": [ … ] }` describing what changed —
  a post (before and after), a redirect, a path, a menu, the templates, or a
  revalidate all. Which cache tags a change expires is the front-end's decision.
  Any 2xx is a success, and the timeout is five seconds. See the README's
  front-end contract and its reference route.
* Removed: the FSE revalidate path and "Revalidate on FSE update" settings — the
  templates are reported on the one route like every other change — and the "On
  menu update" post-type switches — a menu save reports one menu change. Their
  stored values are deleted on upgrade.
* Retired: the `nextjs_revalidate_purge_action_permalink` filter. A post change
  carries no permalink to rewrite, so it is no longer applied, and a site still
  hooking it is told so under `WP_DEBUG`. Move the callback to the new
  `nextjs_revalidate_change` filter, which can alter or drop any change.
* Deprecated until 3.0: `nextjs_revalidate_purge_url()` for
  `nextjs_revalidate_path()`, `nextjs_revalidate_schedule_purge_url()` for
  `nextjs_revalidate_schedule_path()`, and the
  `nextjs_revalidate_purge_should_revalidate_post_on_save` filter for
  `nextjs_revalidate_should_revalidate_post`. The old names still work, and warn
  under `WP_DEBUG`. `$priority` is accepted and ignored.
* Removed: the revalidation queue, its table, its cron and its Queue tab.
  Changes are sent when the request that produced them ends. On upgrade, each
  site's queue table is dropped and its cron unscheduled; paths still waiting in
  it are dropped rather than sent, and their number is written to the log. A
  deploy starts the front-end's cache afresh, so none of them is stale there.
* Changed: the admin says "Revalidate" where it said "Purge", in English and in
  French, and the plugin is named Next.js Revalidate.
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
* Fixed: the settings are now cleaned up when they are saved. They used to be
  stored exactly as typed, so a domain pasted in with a trailing space, or a
  secret copied out of a `.env` file with its line break, left a site that looked
  configured and failed every revalidation with nothing on screen saying why.
  The domain, the paths and the secret are trimmed, and a pasted `?query` or
  `#fragment` is dropped from the domain and the paths. A domain that is not an
  `http://` or `https://` address is not saved: the settings screen says so,
  and the domain saved before is kept. The secret is read trimmed wherever it is
  used — sent to both endpoints, and checked on the REST routes — so a secret
  saved with whitespace before this release works without being saved again.
