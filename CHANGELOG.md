# Changelog

What changed in each release of Next.js Revalidate, newest first, and what a
site has to do about it. Releases before 2.0.0 are listed in the changelog of
[`readme.txt`](readme.txt).

## 2.1.0

Contract version **2**, unchanged: everything below is a new subject or a new
field, which a front-end written for 2.0 ignores (rule 1 of the
[front-end contract](README.md#two-rules)).

- **Added:** a `term` change. Creating, editing or deleting a term of a
  revalidatable taxonomy reports its slug and the URI of its archive before and
  after — `null` before a creation and after a delete, equal sides for a name
  or description edit. Changing a term's slug or parent, or deleting it, also
  reports each descendant whose archive moved, and a delete that moved posts
  into *Uncategorized*, or another taxonomy's default term, reports that term
  once. A term's change never reports the posts in it: **tag whatever shows a
  term with that term's tag**, so one change reaches it all. 2.0 reported no
  term at all, and a term's archive stayed stale until somebody ran revalidate
  all ([ADR 0040](docs/adr/0040-a-term-reports-itself-and-a-post-the-terms-it-is-in.md)).
  The README's reference route tags a term's archive, and every entry showing
  it, `term:{id}`, and expires `uris` too when the term's URI moved.
- **Added:** `terms` on each side of a `post` change — the post's terms in
  revalidatable taxonomies, as `{ id, taxonomy, slug }`, sorted by taxonomy then
  ID — so a front-end reaches the archives a post joined and the ones it left.
  A publish now reaches the archives of the post's categories, and a move from
  one category to another reaches both. Every producer of a post change carries
  them; one with no record of the terms before lists the current ones on both
  sides. A post's terms written with no save — `wp_set_object_terms()`,
  `wp_add_object_terms()`, `wp_remove_object_terms()`, from an import, a plugin
  or Polylang's synchronisation — are now reported as that post's change, with
  equal URIs; 2.0 reported nothing for them. The posts a term's delete moves are
  still not reported: the term's own change covers them. The reference route
  expires `term:{id}` for every term on either side of a post change.
- **Added:** the Debug tab shows the end of the log file while logging is on:
  its last 200 lines, with how many lines the file holds and how big it is.
  **Refresh** reads it again without reloading the page, and **Live refresh**
  does so every five seconds. Nothing to do: with logging off the tab is
  unchanged.
- **Added:** the log file is rotated at 5 MB, keeping one archive
  ([ADR 0041](docs/adr/0041-the-log-rotates-at-a-size-and-keeps-one-archive.md)).
  A line written to a log at or past the limit first renames it to
  `nextjs-revalidate-<suffix>.1.log` beside it, replacing the previous archive,
  and then starts a new log. The new
  [`nextjs_revalidate_log_max_size`](README.md#nextjs_revalidate_log_max_size)
  filter changes the limit, in bytes; `0` or less never rotates. A site whose
  log is already over 5 MB has it archived by its first line under 2.1.0. The
  Debug tab's viewer continues into the archive when the log is short, and
  uninstalling leaves both files behind.
- **Changed:** with Polylang, its `language` taxonomy is no longer a
  revalidatable taxonomy. A language is a site setting, already reported as a
  `settings` change; as a taxonomy it would have put a `term` change next to
  that one, and listed every translated post's language in its `terms`, so
  every save would have expired whatever carries the language's term. The
  Polylang integration declines it through
  `nextjs_revalidate_should_revalidate_taxonomy`, so revalidate all of a post
  type no longer names `language` in its `taxonomies`, as 2.0 did. A site that
  does tag pages with a language's term can admit it again from a later
  priority of that filter.

## 2.0.1

### Fixed

- **A redirect to the front-end's own URL keeps the basic-auth credentials.** A
  307 or a 308 to the same origin was followed with the credentials of the
  revalidate domain only when its `Location` was a path, the shape Next.js
  answers with. One given as an absolute URL — the shape a proxy rewriting
  `Location` answers with — was followed without them, so a staging front-end
  behind basic auth answered it 401 and the delivery failed. The credentials now
  carry over to both; a `Location` naming credentials of its own keeps them, and
  a redirect to another origin is still not followed (#184).
- **A Simple Custom Post Order drag and drop is read whole.** Its list of posts
  was read with `parse_str()`, which stops at `max_input_vars` and warns as it
  does. On a host whose limit is lower than the number of posts dragged, the
  warning — printed with `display_errors` on — broke the JSON the drag and drop
  answers with, and the posts past the limit were never reported. The list is
  now read by hand, the way Simple Custom Post Order 2.8.9 reads it, so the
  posts reported are the ones it writes; a list whose `[]` arrives
  percent-encoded is read too (#186).
- **A reorder request is read only when it carries the plugin's own nonce.**
  The Simple Custom Post Order and Nested Pages integrations read the posts a
  drag and drop names, and looked each one up, before the plugin checked its
  nonce. Any logged-in user, a subscriber included, could send one request
  naming as many posts as they liked and have every one looked up, although
  the plugin then refused it. Each integration now checks the nonce the plugin
  is about to check first, and reads nothing when it fails; the plugin still
  answers the request its own way (#187).
- **Trashing or deleting a parent reports the descendants it moves.** Trashing
  a page adds `__trashed` to its slug, and deleting one reattaches its children
  to its own parent, so every descendant's URI changes — `/about/team/` becomes
  `/about__trashed/team/`, then `/team/` — without a save of its own. Only the
  parent was reported, and deleting a parent already in the trash reported
  nothing. Each descendant whose URI moved is now reported as its own `post`
  change, from its old URI to its new one, as a save that renames or moves a
  parent already did (#144).

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
- **Seven subjects** — `post`, `redirect`, `path`, `menu`, `templates`,
  `settings` and `all` —
  each with the fields the README's
  [front-end contract](README.md#the-front-end-contract) lists. A post change
  carries where the post was before and where it is after, so a slug change now
  reaches the old path too.
- **A `uri` is a path**, for every subject. 1.x sent a post's permalink with
  its query string; 2.0 sends only its path, so a post on plain permalinks,
  `/?p=42`, is reported at `/`. A private post is reported at its page even
  when it changes with nobody logged in — from cron or WP-CLI — where 1.x sent
  the `?p=` link core hands out there.
- **One route for everything.** A saved FSE template or a switched theme is a
  `templates` change on the same route, not a request to a second endpoint.
- **Revalidate all is one change**, and a menu save is one change, rather than a
  request per page.
- **Any 2xx is a success**, where 1.x wanted a 200. A 4xx, a 5xx, or no
  answer within **five seconds** — down from sixty — is a failure.
- **Only a 307 or a 308 to the same origin is followed.** 1.x followed any
  redirect, to anywhere; 2.0 follows one that keeps the method, the body and
  the scheme, host and port, and records any other 3xx as a failure. A route
  served only at `/api/revalidate/` — `trailingSlash: true` — is still reached,
  and a path typed with its trailing slash now keeps it, which saves the round
  trip.
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
- **Added:** a block menu — the `wp_navigation` post the Site Editor saves —
  saved, trashed, restored or permanently deleted reports a `menu` change with
  its post ID and no locations. 1.x reported nothing for one: `wp_navigation` is
  not viewable, and the post gate still declines it. A classic menu's change is
  unchanged. The README's reference route now tags a menu `menu:{id}` rather
  than `menu:{location}`, so one mapping covers both kinds.
- **Added:** a site setting saved — the site title, the tagline, the date and
  time formats, the timezone, the site address, the site icon, the site
  language — reports one `settings` change per request. The
  `nextjs_revalidate_site_setting_options` filter decides which options count.
  New Yoast SEO and Polylang integrations add their SEO defaults, and their
  language list and default language. 1.x reported none of these. The README's
  reference route tags FSE templates `templates` and site settings `settings`,
  where it tagged templates `options`.
- **Added:** a save that moves other posts' pages reports them too. Renaming or
  moving a parent page reports every descendant whose URI moved, from the URI it
  had to the one it has; the new `nextjs_revalidate_dependent_posts` filter adds
  a post whose permalink a theme builds from another, and the new
  `nextjs_revalidate_post( $post_id, $before_url )` reports one whose permalink
  a term moved. With Polylang's synchronisation on, every save reports the
  post's translations too, since Polylang writes them with direct SQL
  ([ADR 0038](docs/adr/0038-a-save-reports-the-posts-it-moves.md)).
- **Added:** Nested Pages and Simple Custom Post Order integrations. A drag and
  drop reports each post it reordered or, in Nested Pages, moved under another
  parent — both write with direct SQL, and saved nothing a save hook could see.
  Ticking or unticking a type in Simple Custom Post Order's settings reports that
  type whole.
- **Added:** `site_logo` is a site setting, and so are Polylang's string
  translations — the translated site title and tagline among them. An entry of
  `nextjs_revalidate_site_setting_options` ending in `*` names every option
  starting with it, for options stored once per language.
- **Changed:** the admin says **Revalidate** where it said **Purge** — the row
  action, the bulk action, the admin bar menu and its entries, and their
  notices, which now say that a revalidation was sent rather than counting pages
  left to purge. A revalidate all the `nextjs_revalidate_change` filter dropped
  says that nothing was sent. The admin's own query args lose the word too, so
  `nextjs-revalidate-purged` is now `nextjs-revalidate-revalidated`. The French
  translation follows.
- **Changed:** the plugin is named **Next.js Revalidate**.
