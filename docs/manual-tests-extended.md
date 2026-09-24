# Manual tests — the extended pass

Everything the [core pass](manual-tests.md) leaves out. **No step appears in both
files**: if you want the whole picture, run the core pass and then this one.

Run a part of this document when you have touched its ground, and the whole of it
before a release that changes anything structural — the lifecycle, the settings,
the migrations, or how a revalidation is composed and sent.

Why this document exists and what belongs in it:
[ADR 0012](adr/0012-a-third-testing-idiom.md). Agents changing code:
[`agents/manual-tests.md`](agents/manual-tests.md).

The conventions are the core pass's: **never commit ticked boxes**, sections
state their preconditions in full, every step names its oracle, and cron needs a
page load or `npx wp-env run cli wp cron event run nextjs_revalidate-queue`.

---

# Part 1 — Single site, beyond the core pass

**Precondition for every section in this part: the spine of the core pass** —
sections 1 and 2 of [`manual-tests.md`](manual-tests.md). A configured site, logs
on, purge-all allowed for `post` and `page`, one published post and one published
page.

## A. Endpoint composition

- [ ] **Clear the revalidate path, leaving it empty, save.** Expect the field to
      show its placeholder `/api/revalidate`, not an empty box with no hint.
- [ ] **Update a post.** Expect `❌ Failed to revalidate 1 change (post)` in the
      log naming `http_404` — the dev server serves revalidations at
      `/revalidate` only, so the default path composing to `/api/revalidate` is
      *expected* to 404 here. That the request went to `/api/revalidate` at all is
      what this proves.
- [ ] **Type the path without its leading slash** — `revalidate` — save, update a
      post. Expect a success, `✅ Revalidated 1 change (post)`: exactly one slash
      is inserted between the domain and the path.
- [ ] **Put a trailing slash on the domain** — `http://host.docker.internal:8083/`
      — save, update a post. Expect a success, and the post change in the
      revalidate server console: a `//revalidate` would not have been served.
- [ ] **Set the domain to `ftp://host.docker.internal:8083` and save.** (The
      field is `type="url"`, so the browser itself stops a value with no scheme
      at all; `ftp://` gets past it.) Expect a single error notice on the
      settings screen, "The revalidate domain was not saved: it must be a web
      address starting with http:// or https://, such as https://example.com.",
      no "Settings saved." notice, and the domain field still showing the domain
      held before the save.
- [ ] **Restore the path to `/revalidate` and the domain to its seeded value.**

## B. The probe

Precondition: spine state, with the seeded API settings — section A restores
them at its end, so run this after it or check the Next.js API tab first.

- [ ] **Settings → Next.js revalidate → Probe.** Expect a sixth tab holding a
      path field showing `/`, a "Send probe" button, and the note that a probe
      uses the *saved* settings.
- [ ] **Type `/runbook-post/` and press Send probe.** Expect
      `= Revalidating (v2): {"subject":"path","uri":"/runbook-post/"}` in the
      revalidate server console — one v2 request carrying one **path** change;
      a probe is a real revalidation, not a dry run — and a success notice on
      the settings screen: "The front-end rebuilt
      http://localhost:8080/runbook-post/."
- [ ] **Check the log.** Expect one line
      `🔎 Probe: ✅ Revalidated in 0.04s http://localhost:8080/runbook-post/`,
      and no `✅ Revalidated 1 change (path)` line after it: a probe is
      delivered on its own, while the operator waits, and never joins the
      request's pending changes to be sent a second time when it ends.
- [ ] **Reload the settings screen.** Expect the notice gone and **no** second
      line in the console: the answer comes back through a redirect, so a
      refresh does not quietly probe again.
- [ ] **Empty the field and probe.** Expect the home page —
      `{"subject":"path","uri":"/"}` in the console, `http://localhost:8080/`
      in the notice.
- [ ] **Paste the full permalink** `http://localhost:8080/runbook-page/` **and
      probe.** Expect exactly what typing `/runbook-page/` gives: only the path
      is kept.
- [ ] **Set the secret to `wrong-secret`, save, and probe again.** Expect an
      error notice naming both the message and the code: "The front-end did not
      rebuild http://localhost:8080/runbook-post/ — The front-end answered 401.
      (http_401)", and a `🔎 Probe: ❌ Failed to revalidate … http_401` line in
      the log.
- [ ] **Forget the failure window**
      (`npx wp-env run cli wp option delete nextjs_revalidate-failure_window`)
      **and probe three times with the wrong secret still saved.** Expect
      `wp option get nextjs_revalidate-failure_window` to still answer "could
      not be found", and no degraded notice anywhere: a probe is never
      evidence, so this button can neither trip its own alarm nor silence it.
- [ ] **Clear the secret, save, and probe.** Expect an error notice "Nothing was
      sent for http://localhost:8080/runbook-post/. Next.js revalidate is not
      configured for this site…", a `🔎 Probe: ⛔ Refused` line in the log, and
      **nothing at all** in the revalidate server console.
- [ ] **Restore the secret, save, and probe once more.** Expect the success
      notice again.

## C. Which posts revalidate, and at which path

The oracle is the revalidate server console, which prints each post change as
`= Revalidating (v2): {"subject":"post",…,"before":…,"after":…}`.

- [ ] **Publish a post with visibility Private.** Expect a post change with
      `"before":null` and its URI as the `after` — private posts are
      revalidatable.
- [ ] **Publish a post with a password.** Expect a post change.
- [ ] **Save a draft that has never been published.** Expect **no** post change
      in the console. It was never a candidate, so nothing is logged as refused
      either — absence here is correct, not a swallowed error.
- [ ] **Register a non-viewable post type and publish one:**
      ```sh
      npx wp-env run cli -- bash -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/njr-runbook-cpt.php <<PHP
      <?php register_post_type("njr_hidden", ["public"=>false,"show_ui"=>true,"label"=>"Hidden"]);
      PHP'
      ```
      Publish one from the new **Hidden** menu. Expect **no** post change.
- [ ] **Look at what the admin offers for Hidden.** Expect **no** Purge caches
      entry in the Hidden list's Bulk actions dropdown, and no **Hidden** switch
      under either *Allow purge all options* or *On menu update options* in the
      settings: a post type the gate declines every post of is offered nothing.
- [ ] **Admit it with the filter.** Append to that mu-plugin
      `add_filter("nextjs_revalidate_should_revalidate_post", "__return_true");`
      and publish another Hidden post. Expect a post change with
      `"type":"njr_hidden"` — the site has the last word over the viewability
      gate. Expect the bulk action and the two switches to be **still absent**:
      that filter answers about one post, so it widens the gate and not what
      the admin offers.
- [ ] **Make the post type viewable instead.** Append to that mu-plugin
      `add_filter("is_post_type_viewable", function($v, $pt) { return "njr_hidden" === $pt->name ? true : $v; }, 10, 2);`
      and reload the settings page. Expect a **Hidden** switch in both lists, and
      **Purge caches** in the Hidden list's Bulk actions — core's own filter
      moves the offer and the gate together. Then
      `npx wp-env run cli -- rm wp-content/mu-plugins/njr-runbook-cpt.php`.
- [ ] **Change a published post's slug and Update.** Expect **one** post change
      whose `before` holds the old path and whose `after` holds the new one. v1
      revalidated only the new permalink and left the old path cached; the
      `before` is what reaches it now.

## D. Row action and bulk action

- [ ] **Posts list → hover a published post.** Expect a **Purge cache** row
      action beside Edit and Trash.
- [ ] **Click it.** Expect to land back on the posts list with "“Runbook post”
      cache will be purged shortly.", a post change in the console whose
      `before` and `after` are both `{"uri":"/runbook-post/"}`, and the purge
      query arg gone from the URL once the notice has been shown.
- [ ] **Hover a draft.** Expect **no** Purge cache action.
- [ ] **Select both published posts → Bulk actions → Purge caches → Apply.**
      Expect "2 caches will be purged shortly." and **one** console line
      carrying two post changes, each with both sides its current URI.
- [ ] **Repeat the bulk action on the Pages list.** Expect the same, with
      `"type":"page"` and the page's path.
- [ ] **Trash a post from the row action and confirm the list still works.**
      Expect no PHP notice and no broken action column.

## E. Purge this page, from the admin bar

- [ ] **Open a published post in the block editor.** Expect an admin bar menu
      **Next.js revalidate** with a **Purge this page** item under it.
- [ ] **Click it.** Expect to stay on the editor screen and see a block editor
      notice — dispatched to `core/notices`, not a classic notice strip —
      reading "“Runbook post” cache will be purged shortly."
- [ ] **Reload the editor.** Expect the notice **not** to reappear: the query arg
      is dropped from the URL once shown.
- [ ] **Open a page in the classic editor context and repeat.** Expect the same,
      rendered as a classic notice.
- [ ] **Open a brand-new unsaved post.** Expect **no** Purge this page item —
      there is no permalink to purge.

## F. Revalidate all

- [ ] **Admin bar → Next.js revalidate.** Expect items for **All**, **Posts** and
      **Pages**, and none for post types not ticked in the settings.
- [ ] **Click Posts.** Expect "Purge all: N pages added to purge…" with N
      matching your published post count, and N rows in the queue table.
- [ ] **Drain it.** Expect one console line per path and no duplicates.
- [ ] **Click All.** Expect posts and pages both queued.
- [ ] **Untick `page` in the settings, save, reopen the admin bar.** Expect the
      Pages item gone. Re-tick it afterwards.
- [ ] **As a subscriber, open the admin bar.** Expect no Next.js revalidate menu.
- [ ] **Register a post type with no published posts and allow it.** Expect
      "Purge all: 0 pages added to purge." rather than an error.

## G. Menu save

- [ ] **Settings → Next.js revalidate → On menu update: enable, save.**
- [ ] **Appearance → Menus → create a menu, add the page, Save Menu.** Expect a
      revalidate-all's worth of rows in the queue.
- [ ] **Disable the setting, save, and save the menu again.** Expect **no** new
      queue rows.

## H. FSE update

Precondition: a **block theme**, so the site editor exists. Note which theme is
active — `npx wp-env run cli wp theme list --status=active --field=name` — and
activate a block one if it is not; `npx wp-env run cli wp theme list` shows what
is installed, and the bundled Twenty Twenty-Four and later are block themes.
Restore the theme that was active when the section is done.

The oracle here is the **revalidate server console**, not the queue: an FSE
change is a **templates change**, delivered with the request's pending changes
in one v2 `POST`, and nothing about it reaches the queue. Expect
`= Revalidating (v2): {"subject":"templates"}` and no queue rows at all.

- [ ] **Appearance → Editor → Patterns → a template part (Footer) → move a block
      → Save.** Expect **exactly one** `= Revalidating (v2): {"subject":"templates"}`
      in the console — one request, carrying one change — and
      `✅ Revalidated 1 change (templates)` in the log. Not two: the site
      editor's save reaches more than one hook, and identical changes merge.
- [ ] **Confirm the queue stayed empty**:
      `npx wp-env run cli wp db query "SELECT COUNT(*) FROM wp_revalidate_queue"`.
      Expect 0. No page was named, and none needed to be.
- [ ] **Edit a template (Editor → Templates → Single) and Save.** Expect one
      templates change.
- [ ] **Reset that template to its theme default** — Editor → Templates → the
      template's ⋮ → **Reset**. Expect one templates change: the reset *deletes*
      the database post, and there is no save to hook.
- [ ] **Switch themes**: activate another installed theme, then switch back.
      Expect one templates change per switch — every template changed at once.
- [ ] **Save an ordinary post.** Expect a post change and **no** templates
      change: a post has not touched the snapshot.
- [ ] **Save a navigation menu** (Appearance → Menus, with the On menu update
      setting off). Expect **no** templates change. Menu items are fetched at
      request time by the front-end and are deliberately not in the snapshot.
- [ ] **Clear the secret, save, and edit a template part.** Expect
      `⛔ Refused a templates change — site not configured (missing: secret)` in
      the log and **no** request in the console. Restore the secret.

## I. Scheduled purge

- [ ] **Schedule a post for two minutes from now and publish.** Expect **no**
      immediate revalidation of its path — it is not published yet.
- [ ] **Confirm the schedule is recorded**:
      `npx wp-env run cli wp option get nextjs-revalidate-scheduled_purges`.
      Expect an entry carrying the post's future timestamp.
- [ ] **Confirm the cron is set**:
      `npx wp-env run cli wp cron event list | grep scheduled_purges`.
- [ ] **Wait for the time to pass and load an admin page.** Expect
      `{"subject":"path","uri":…}` for the now-published path in the
      revalidate server console — a due scheduled purge is reported as a
      **path** change by the cron request that finds it due — and the option
      entry gone.

## J. The log file

- [ ] **Read the path under Enable logs on the Debug tab.** Expect it to end
      `wp-content/uploads/nextjs-revalidate/nextjs-revalidate-<suffix>.log`.
- [ ] **Request the log over HTTP**:
      `curl -sI http://localhost:8080/wp-content/uploads/nextjs-revalidate/<filename>`.
      Expect `403 Forbidden` — the `.htaccess` denying it on Apache. wp-env is
      Apache; this proves nothing about nginx, which is what the suffix is for.
- [ ] **Request an upload beside it**: add any image to the Media Library, then
      `curl -sI` its URL. Expect `200 OK`. A `403` means a guard landed in
      uploads itself and the site no longer serves its own media.
- [ ] **Turn logging off** on the Debug tab, save, update a post.
      Expect the revalidation to still happen (console) and **no new lines** in
      the file. Every line the plugin can write passes through that one setting.
- [ ] **Turn logging back on** and confirm new lines appear.
- [ ] **Update a post and read its success line.** Expect
      `[timestamp]\t[INFO]\t[PendingChanges.php] ✅ Revalidated 1 change (post)`.
- [ ] **Break the secret, update a post, read the failure line.** Expect
      `[ERROR]` and `❌ Failed to revalidate 1 change (post) — http_401: The
      front-end answered 401.` — the code and message the front-end actually
      produced, not a generic failure. Restore the secret.
- [ ] **Enqueue while configured, then clear the secret before running cron.**
      Expect `⛔ Refused` with `not_configured` — a refusal given at the drain
      rather than at enqueue, and visibly not the same thing as a failure.
      Restore the secret.
- [ ] **Delete the log file and load wp-admin.** Expect no warning: on a site
      that has never logged, its absence is the normal state. The next logged
      line recreates it.

## K. Who sees the notices

Create a subscriber once:
`npx wp-env run cli wp user create sub sub@example.com --role=subscriber --user_pass=sub`

- [ ] **Clear the secret, then load wp-admin as the subscriber.** Expect **no**
      unconfigured notice — it is shown only to users who can edit posts or
      manage options.
- [ ] **Break the secret, fail three times, then load wp-admin as the
      subscriber.** Expect the degraded notice, ending "Please contact a site
      administrator." instead of offering a settings link.
- [ ] **As the subscriber, load the posts list.** Expect no Purge cache row
      action and no Purge caches bulk action. Restore the secret afterwards.

A site that is unconfigured on purpose can silence its notice with the
`nextjs_revalidate_show_unconfigured_notice` filter. The steps below run as the
administrator.

- [ ] **Set the secret to `wrong-secret` and update a post three times, then
      clear the revalidate domain and open the post in the block editor.**
      Expect the degraded notice in the block editor, standing in for the
      unconfigured notice that core hides there. This is the baseline the next
      step silences.
- [ ] **Silence the notice, then reload the post in the block editor:**
      ```sh
      npx wp-env run cli -- bash -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/njr-runbook-silence.php <<PHP
      <?php add_filter("nextjs_revalidate_show_unconfigured_notice", "__return_false");
      PHP'
      ```
      Expect **no** degraded notice in the block editor.
- [ ] **Load the Dashboard, the posts list and the Next.js revalidate settings
      screen.** Expect **no** unconfigured notice and **no** degraded notice on
      any of them.
- [ ] **Remove the filter and reload the Dashboard:**
      `npx wp-env run cli -- rm wp-content/mu-plugins/njr-runbook-silence.php`.
      Expect the unconfigured notice back. Restore the domain and the secret
      afterwards.

## L. The French translation

- [ ] **Settings → General → Site Language → Français, save.**
- [ ] **Load the Next.js revalidate settings screen.** Expect tab labels, field
      labels and help text in French.
- [ ] **Trigger the unconfigured notice, then the degraded notice.** Expect both
      in French, with the numbers correctly placed in the degraded one.
- [ ] **Check the admin bar and the row action.** Expect "Purger cette page" and
      a French row action label.
- [ ] **Restore English and restore the secret.**

## M. The Redirection integration in bulk

Precondition: Redirection active (installed from `.wp-env.json`), its setup
wizard completed once, and no redirects left from the core pass. Single
redirects are the core pass's section 7; a bulk route is the only thing that
fires those same per-redirect actions in a loop, and nothing automated reaches
Redirection's own bulk screen.

- [ ] **Tools → Redirection → add five enabled redirects**, from `/bulk-a/`,
      `/bulk-b/`, `/bulk-c/` and twice from `/bulk-dup/` — two rules sharing one
      source, which is the case the rest of this section is about.
- [ ] **Select all five → Bulk Actions → Disable → Apply.** Expect **one**
      `= Revalidating (v2): …` line in the revalidate server console, carrying
      **four** `{"subject":"redirect",…}` changes — one per distinct source —
      and `✅ Revalidated 4 changes (redirect ×4)` in the log. The two
      redirects sharing `/bulk-dup/` cost one change between them.
- [ ] **Select all five → Bulk Actions → Enable → Apply.** Expect one request
      carrying four redirect changes again: a bulk route reaches this plugin
      once per redirect whichever way the switch went.
- [ ] **Select all five → Bulk Actions → Delete → Apply.** Expect one request
      carrying four redirect changes once more: nothing is capped above a
      threshold and nothing escalates to a revalidate all. The count is
      bounded by the rules that existed.
- [ ] **Add a regex redirect, source `^/bulk-regex/(.*)`, and bulk-delete it
      alone.** Expect **no** request in the console, and a log line saying it
      was skipped because "its source is a regular expression, which names no
      single path".

## N. Uninstallation

Run this last in Part 1 — it destroys the site's plugin data.

- [ ] **Deactivate, then Delete the plugin from the Plugins screen.** Expect no
      error.
- [ ] **Expect the table gone**:
      `npx wp-env run cli wp db query "SHOW TABLES LIKE 'wp_revalidate_queue'"`.
      Expect no rows.
- [ ] **Expect every option gone.** Check `nextjs_revalidate-domain`,
      `-endpoint_path`, `-secret`,
      `-allow_revalidate_all`, `nextjs_revalidate-revalidate-on-menu-save`,
      `nextjs_revalidate-debug`, `nextjs_revalidate-db_version`,
      `nextjs_revalidate-log_suffix`, `nextjs_revalidate-failure_window`,
      `nextjs-revalidate-scheduled_purges`. Expect all "could not be found".
- [ ] **Restore the install**: `npm run stop && npm start`. Deleting the plugin
      removed its registration, not the mounted working tree.

---

# Part 2 — The network stack

Precondition: Part 1 finished and `npm run stop` run. This stack is raised by an
override file, never by editing `.wp-env.json`.

## O. Setup

- [ ] **`cp config/wp-env.multisite.json .wp-env.override.json`.**
- [ ] **`npx wp-env destroy`** and confirm. The install has to be rebuilt as a
      network; starting over the single-site database will not convert it.
- [ ] **`npm start`**, then confirm it is a network:
      `npx wp-env run cli wp core is-installed --network` exits 0.
- [ ] **Confirm the main site is configured** — `afterstart.sh` seeds it, and
      only it. Create a second site:
      `npx wp-env run cli wp site create --slug=second --title="Second"`.

## P. Network activation sets up every site

Precondition: O done, plugin **not** yet network-activated, at least two sites.

- [ ] **Network Admin → Plugins → Network Activate "Next.js revalidate".**
      Expect no error.
- [ ] **Expect a queue table for every site**:
      `npx wp-env run cli wp db query "SHOW TABLES LIKE '%revalidate_queue'"`.
      Expect `wp_revalidate_queue` and `wp_2_revalidate_queue`.
- [ ] **Expect a queue cron on every site**:
      `npx wp-env run cli wp cron event list --url=localhost:8080/second`.
- [ ] **Expect a DB version on every site**:
      `npx wp-env run cli wp option get nextjs_revalidate-db_version --url=localhost:8080/second`.
      Expect a version string, not "could not be found".

## Q. A site created after activation

Precondition: O done, plugin network-active.

- [ ] **Network Admin → Sites → Add New**, slug `third`.
- [ ] **Expect `wp_3_revalidate_queue` to exist** without anyone visiting the new
      site. Setup is eager; there is no lazy fallback that would create it on
      first use.
- [ ] **Expect its cron scheduled**, and the site to be **unconfigured** — no
      domain, no secret. Load its wp-admin and expect the unconfigured notice. A
      newly created site starting unconfigured is by design.

## R. Settings are per site

Precondition: O done, main site configured, `second` not.

- [ ] **Configure `second`** with the same domain and secret, through its own
      Settings screen at `http://localhost:8080/second/wp-admin`.
- [ ] **Change the main site's secret to something else.** Expect `second`'s
      secret unchanged.
- [ ] **Publish a post on `second`.** Expect a post change in the console whose
      `after` is `second`'s URI — `{"uri":"/second/<slug>/"}` — and
      `✅ Revalidated 1 change (post)` in `second`'s log: it travelled with
      `second`'s secret, not the main site's.
- [ ] **Expect a separate log file** for `second`, at the path its own Debug
      tab reports: beneath `wp-content/uploads/sites/2/nextjs-revalidate/`, and
      under a different filename from the main site's.
- [ ] **Break the main site's secret and fail three times.** Expect the degraded
      notice on the main site and **not** on `second` — the failure window is per
      site. Restore the main site's secret.

## S. An update migrates every site, without visiting any

Precondition: P done, plugin network-active, at least two sites. The update is
faked rather than performed: what triggers the sweep is the swept version
differing from the running one, so a Composer or git deploy that never runs
WordPress's updater reaches this the same way a real update does.

- [ ] **Rewind the network and `second` only**, leaving `second` a site whose
      code an update has reached and whose data it has not:
      ```sh
      npx wp-env run cli wp site option delete nextjs_revalidate-swept_version
      npx wp-env run cli wp option delete nextjs_revalidate-db_version --url=localhost:8080/second
      npx wp-env run cli wp option update nextjs_revalidate-allow_purge_all --format=json '["post"]' --url=localhost:8080/second
      ```
- [ ] **Load the main site's wp-admin, and nothing of `second`'s.** Expect
      `second` migrated anyway:
      `wp option get nextjs_revalidate-allow_revalidate_all --url=localhost:8080/second`
      returns `["post"]`, and `nextjs_revalidate-allow_purge_all` is gone. Nobody
      opened that site's admin, which is the whole of what this proves.
- [ ] **Expect the network stamped**:
      `npx wp-env run cli wp site option get nextjs_revalidate-swept_version`
      returns the running version — a network-wide value, not one per site.
- [ ] **Reload the main site's wp-admin three or four times.** Expect the swept
      version unchanged and nothing re-migrated: once per network per release,
      not once per admin request.
- [ ] **Clear the swept version and fake a large network**:
      ```sh
      npx wp-env run cli wp site option delete nextjs_revalidate-swept_version
      npx wp-env run cli -- bash -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/njr-large-network.php <<PHP
      <?php add_filter("wp_is_large_network", "__return_true");
      PHP'
      ```
- [ ] **Load wp-admin as a super admin.** Expect a warning notice reading
      `cannot migrate the … sites of this network in a single request`, naming
      the number of sites, and `wp site option get nextjs_revalidate-swept_version`
      still "could not be found" — a sweep reaches every site or it does not
      start, and it says which it did.
- [ ] **Log in as a plain site administrator** — any user without super admin —
      and load the same screen. Expect **no** such notice: nobody but a super
      admin can act on it.
- [ ] **Remove the filter** —
      `npx wp-env run cli -- rm wp-content/mu-plugins/njr-large-network.php` —
      and reload wp-admin as a super admin. Expect the notice gone and the swept
      version stamped.

## T. A large network declines rather than truncates

Precondition: O done. This simulates a large network with a filter; it cannot be
reached otherwise without ten thousand sites.

- [ ] **Network-deactivate the plugin**, then install the filter:
      ```sh
      npx wp-env run cli -- bash -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/njr-large-network.php <<PHP
      <?php add_filter("wp_is_large_network", "__return_true");
      PHP'
      ```
- [ ] **Attempt Network Activate.** Expect a **refusal** naming the site count
      and telling you to activate on each site individually — not a partial
      activation, and not a silent one.
- [ ] **Go back and check the Plugins screen.** Expect the plugin still inactive.
      A network the sweep could not cover is a network the plugin does not claim
      to have set up.
- [ ] **Activate on a single site instead**, from that site's own Plugins screen.
      Expect it to succeed — the refusal exists to leave that door open. Then
      `npx wp-env run cli -- rm wp-content/mu-plugins/njr-large-network.php`.

## U. Network deactivation and uninstallation

Precondition: O done, plugin network-active, all sites set up.

- [ ] **Network Deactivate.** Expect the queue cron gone on **every** site, the
      settings kept on every site, and the failure window cleared on every site.
- [ ] **Network Activate, then Delete the plugin.** Expect every site's queue
      table dropped and every site's options gone — check `second` explicitly,
      not just the main site. A site is torn down at the same depth on a network
      as it would be alone.
- [ ] **Expect the network's own record gone too**:
      `npx wp-env run cli wp site option get nextjs_revalidate-swept_version`
      returns "could not be found". Left behind, a reinstall would read it and
      sweep nothing.

## V. Teardown

- [ ] **`npm run stop`.**
- [ ] **`rm .wp-env.override.json`.** Not optional: wp-env merges it over
      `.wp-env.json`, so a leftover silently makes every later `wp-env start` a
      network — including the next core pass.
- [ ] **`npx wp-env destroy`** and confirm, so Part 3 starts from nothing.

---

# Part 3 — The upgraded stack

The only stack that can exercise the migration ledger's backfill. A fresh install
never can: it is stamped with the current DB version at setup, which is precisely
what the backfill exists to avoid needing.

## W. Raise a real 1.6.9 site

- [ ] **Confirm the release asset URL.** Open the v1.6.9 release on GitHub and
      copy the zip's download URL. Do not assume the filename.
- [ ] **Write the override**, replacing the local plugin mount with that zip:
      ```sh
      cat > .wp-env.override.json <<'JSON'
      {
        "plugins": [
          "https://github.com/superhuit-agency/nextjs-revalidate/releases/download/v1.6.9/nextjs-revalidate-v1.6.9.zip",
          "https://downloads.wordpress.org/plugin/redirection.zip"
        ]
      }
      JSON
      ```
- [ ] **`npx wp-env destroy`**, then **`npm start`**, then confirm the Plugins
      screen shows **1.6.9**. If it shows anything else the working tree is still
      mounted and nothing below tests an upgrade. Activate it.

## X. A 1.6.9 site, configured the old way

- [ ] **Set the legacy single URL option** — the shape 1.6.9 stores:
      ```sh
      npx wp-env run cli wp option update nextjs_revalidate-url http://host.docker.internal:8083/revalidate
      npx wp-env run cli wp option update nextjs_revalidate-secret my-super-secret
      npx wp-env run cli wp option update nextjs_revalidate-debug --format=json '{"enable-logs":"on"}'
      npx wp-env run cli wp rewrite structure /%postname%/ --hard
      ```
- [ ] **Confirm there is no ledger yet**:
      `npx wp-env run cli wp option get nextjs_revalidate-db_version`. Expect
      "could not be found" — this is what makes it a genuine pre-ledger site.
- [ ] **Publish a post and confirm 1.6.9 revalidates it.** Expect
      `= Revalidating: /…/` in the dev server console. Enter the upgrade from a
      *working* site, so that a broken one afterwards means something.
- [ ] **Leave an entry waiting in 1.6.9's queue**, so the upgrade has a row to
      carry rather than an empty table:
      ```sh
      npx wp-env run cli wp db query "INSERT INTO wp_revalidate_queue (permalink, priority) VALUES ('http://localhost:8080/left-waiting/', 7)"
      ```
      Expect `wp db query "SELECT permalink, priority FROM wp_revalidate_queue"`
      to list it at priority 7. A row inserted this way schedules no drain, so it
      sits until something else does. Do it last in this section, and stop the
      site next.

## Y. The upgrade

- [ ] **`npm run stop`, `rm .wp-env.override.json`, `npm start`.** The working
      tree is now mounted into the same plugin directory over the same database.
      Do **not** destroy — destroying is what makes this not an upgrade. Confirm
      the Plugins screen no longer says 1.6.9.
- [ ] **Load any wp-admin screen.** Migration runs on `admin_init`, so one admin
      page load is the whole trigger.
- [ ] **Expect the ledger stamped**:
      `wp option get nextjs_revalidate-db_version` returns the running version.
- [ ] **Expect the URL split**: `nextjs_revalidate-domain` is
      `http://host.docker.internal:8083`, `nextjs_revalidate-endpoint_path` is
      `/revalidate`, `wp option get nextjs_revalidate-url` is gone, and the
      secret is untouched.
- [ ] **Open the settings screen.** Expect the domain and path fields populated
      with the split values — the operator should not have to retype anything.
- [ ] **Expect the 1.6.9 log moved, not lost**:
      `npx wp-env run cli -- ls -a wp-content/uploads` shows no
      `nextjs-revalidate.log`, and `tail` of the path under **Enable logs** on
      the Debug tab shows the lines 1.6.9 wrote in X.
- [ ] **Expect the queue's unique key moved onto the hash column**:
      ```sh
      npx wp-env run cli wp db query "SHOW INDEX FROM wp_revalidate_queue"
      ```
      Expect one `permalink_hash` key, with `Non_unique` 0 and an empty
      `Sub_part`, and **no** key named `permalink`. The key this replaces was
      declared over a `TEXT` column, which only MariaDB accepts — on standard
      MySQL the `CREATE TABLE` was refused outright and the site has no queue
      table at all (ADR 0029). wp-env's database is MariaDB, so this
      stack can show the upgrade and never the refusal.
- [ ] **Expect the entry left waiting in X carried, and hashed**:
      ```sh
      npx wp-env run cli wp db query "SELECT permalink, permalink_hash, priority FROM wp_revalidate_queue"
      ```
      Expect the row still there at priority 7, with a 64-character
      `permalink_hash` beside it. Check this before the next step: enqueueing
      schedules a drain, which takes the row with it.
- [ ] **Enqueue the post published in U, then run the queue cron**:
      `npx wp-env run cli wp eval 'nextjs_revalidate_purge_url( get_permalink( get_posts()[0] ) );'`.
      Expect a revalidation of its path in the dev server console: the table
      carried through the upgrade takes a new entry under its new key. (Saving
      the post would not do: a post is reported as a change, never queued.)
- [ ] **Reload wp-admin several times.** Expect the ledger to stay put and
      nothing to be re-migrated: a migration decides by the ledger, never by the
      plugin version.
- [ ] **Edit a template part** (section H has how — this needs a block theme).
      Expect one `= Revalidating (v2): {"subject":"templates"}` in the console,
      sent to the `/revalidate` path the split carried over: an upgraded site
      reports its templates like a new one, with no FSE switch to turn on — v2
      removed it.

## Z. Backfill from an older shape

Precondition: Y done. This rewinds the ledger to fake a site that predates it.

- [ ] **Rewind to a pre-1.5.0 shape**:
      ```sh
      npx wp-env run cli wp option delete nextjs_revalidate-db_version
      npx wp-env run cli wp option update nextjs_revalidate-allow_purge_all --format=json '["post"]'
      ```
- [ ] **Load wp-admin.** Expect the legacy option carried to its new name —
      `wp option get nextjs_revalidate-allow_revalidate_all` returns `["post"]`,
      and `nextjs_revalidate-allow_purge_all` is gone — and the ledger stamped
      afterwards.
- [ ] **Rewind again with a 1.5.0-shaped fingerprint**:
      `wp option update nextjs-revalidate-queue --format=json '[]'`, delete the
      ledger, load wp-admin. Expect the option deleted — the queue lives in its
      own table now — and the ledger stamped.

## AA. Teardown

- [ ] **`npm run stop`**, then **`ls .wp-env.override.json`** and expect it
      absent.
- [ ] **`npx wp-env destroy`** and confirm, so Part 4 starts from nothing.

---

# Part 4 — A WordPress below the floor

The only stack that can show the **WordPress floor** refused rather than
declared. `tests/wordpress-floor-test.php` holds the number in every file that
states it; what it cannot hold is core reading that number and declining the
activation, which it does from 5.2 on (ADR 0028). Run this part when the floor
moves.

## AB. Raise a 5.5 site

- [ ] **Write the override**, pinning core to the release just below the floor,
      a PHP it runs on, and only this plugin — Redirection's current release
      needs a newer WordPress than this:
      ```sh
      cat > .wp-env.override.json <<'JSON'
      {
        "core": "WordPress/WordPress#5.5",
        "phpVersion": "7.4",
        "plugins": [ "." ]
      }
      JSON
      ```
- [ ] **`npx wp-env destroy`**, then **`npm start`**, then confirm the release:
      `npx wp-env run cli wp core version` prints `5.5`, or a `5.5.x`.

## AC. The activation is refused

- [ ] **Plugins → Activate "Next.js revalidate"**, deactivating it first if
      wp-env left it active. Expect a WordPress error page reading "Current
      WordPress version (5.5…) does not meet minimum requirements for Next.js
      revalidate. The plugin requires WordPress 5.6." — the number from the
      header, and not `5.6.0`.
- [ ] **Back to Plugins.** Expect the plugin listed as inactive, and no
      **Settings → Next.js revalidate** entry.

## AD. Teardown

- [ ] **`npm run stop`**, then **`rm .wp-env.override.json`** — a leftover pins
      every later `wp-env start` to 5.5.
- [ ] **`npx wp-env destroy`** and confirm.
- [ ] **`git status`.** Expect a clean tree, with no ticked boxes in either
      runbook.
