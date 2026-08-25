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
- [ ] **Update a post and run the queue cron.** Expect a failure in the log
      naming `http_404` — the dev server serves the single-path revalidation at
      `/revalidate` only, so the default path composing to `/api/revalidate` is
      *expected* to 404 here. That the request went to `/api/revalidate` at all is
      what this proves.
- [ ] **Type the path without its leading slash** — `revalidate` — save, update a
      post, run cron. Expect a success: exactly one slash is inserted between the
      domain and the path.
- [ ] **Put a trailing slash on the domain** — `http://host.docker.internal:8083/`
      — save, update, run cron. Expect a success, and no `//` in the logged
      permalink's endpoint.
- [ ] **Set the FSE revalidate path to `/fse`, save, and save a template part**
      (section H has how). Expect a failure in the log naming `http_404`: the
      dev server serves the FSE endpoint at the default `/api/revalidate-fse`, so
      a path the operator supplied is *expected* to 404 here. That the request
      went to `/fse` at all is what this proves.
- [ ] **Clear the FSE revalidate path, leaving it empty, save.** Expect the field
      to show its placeholder `/api/revalidate-fse`.
- [ ] **Restore the path to `/revalidate` and the domain to its seeded value.**

## B. The probe

Precondition: spine state, with the seeded API settings — section A restores
them at its end, so run this after it or check the Next.js API tab first.

- [ ] **Settings → Next.js revalidate → Probe.** Expect a sixth tab holding a
      path field showing `/`, a "Send probe" button, and the note that a probe
      uses the *saved* settings.
- [ ] **Type `/runbook-post/` and press Send probe.** Expect
      `= Revalidating: /runbook-post/` in the revalidate server console — a
      probe is a real rebuild, not a dry run — and a success notice on the
      settings screen: "The front-end rebuilt
      http://localhost:8080/runbook-post/."
- [ ] **Check the log.** Expect one line
      `🔎 Probe: ✅ Revalidated in 0.04s http://localhost:8080/runbook-post/`,
      carrying neither a queue id nor a priority — a probe has neither.
- [ ] **Check the queue table.** Expect **no** row for that permalink: a probe
      is delivered in the request that asked for it and never enqueued.
- [ ] **Reload the settings screen.** Expect the notice gone and **no** second
      line in the console: the answer comes back through a redirect, so a
      refresh does not quietly probe again.
- [ ] **Empty the field and probe.** Expect the home page — `/` in the console,
      `http://localhost:8080/` in the notice.
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

- [ ] **Publish a post with visibility Private.** Expect a revalidation — private
      posts are revalidatable.
- [ ] **Publish a post with a password.** Expect a revalidation.
- [ ] **Save a draft that has never been published.** Expect **no** revalidation
      and no queue row. It was never a candidate, so nothing is logged as refused
      either — absence here is correct, not a swallowed error.
- [ ] **Register a non-viewable post type and publish one:**
      ```sh
      npx wp-env run cli -- bash -c 'mkdir -p wp-content/mu-plugins && cat > wp-content/mu-plugins/njr-runbook-cpt.php <<PHP
      <?php register_post_type("njr_hidden", ["public"=>false,"show_ui"=>true,"label"=>"Hidden"]);
      PHP'
      ```
      Publish one from the new **Hidden** menu. Expect **no** revalidation.
- [ ] **Admit it with the filter.** Append to that mu-plugin
      `add_filter("nextjs_revalidate_purge_should_revalidate_post_on_save", "__return_true");`
      and publish another Hidden post. Expect a revalidation — the site has the
      last word over the viewability gate. Then
      `npx wp-env run cli -- rm wp-content/mu-plugins/njr-runbook-cpt.php`.
- [ ] **Change a published post's slug and Update.** Expect a revalidation, and
      record **which** path arrives: WordPress reports the new permalink, so the
      old path is not revalidated and the front-end may keep a stale page at the
      old slug. Behaviour to know rather than a step that fails.

## D. Row action and bulk action

- [ ] **Posts list → hover a published post.** Expect a **Purge cache** row
      action beside Edit and Trash.
- [ ] **Click it.** Expect to land back on the posts list with "“Runbook post”
      cache will be purged shortly.", a revalidation to follow, and the purge
      query arg gone from the URL once the notice has been shown.
- [ ] **Hover a draft.** Expect **no** Purge cache action.
- [ ] **Select both published posts → Bulk actions → Purge caches → Apply.**
      Expect "2 caches will be purged shortly." and two revalidations.
- [ ] **Repeat the bulk action on the Pages list.** Expect the same, with the
      page's path.
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
change is a **snapshot invalidation**, and nothing about it reaches the queue.
Expect `= Invalidating: the FSE snapshot` and no queue rows at all.

- [ ] **Settings → Next.js revalidate → On FSE update.** Expect one switch,
      **Revalidate on FSE update**, and expect it **on**. It is the only setting
      in this plugin that starts on rather than off.
- [ ] **Appearance → Editor → Patterns → a template part (Footer) → move a block
      → Save.** Expect **exactly one** `= Invalidating: the FSE snapshot` in the
      console, and `✅ Invalidated the FSE snapshot` in the log. Not two — the
      site editor's save reaches more than one hook and they are coalesced.
- [ ] **Confirm the queue stayed empty**:
      `npx wp-env run cli wp db query "SELECT COUNT(*) FROM wp_revalidate_queue"`.
      Expect 0. No page was named, and none needed to be.
- [ ] **Edit a template (Editor → Templates → Single) and Save.** Expect one
      invalidation.
- [ ] **Reset that template to its theme default** — Editor → Templates → the
      template's ⋮ → **Reset**. Expect one invalidation: the reset *deletes* the
      database post, and there is no save to hook.
- [ ] **Switch themes**: activate another installed theme, then switch back.
      Expect one invalidation per switch — every template changed at once.
- [ ] **Save an ordinary post.** Expect a queue row and **no** invalidation: the
      two paths are independent, and a post has not touched the snapshot.
- [ ] **Save a navigation menu** (Appearance → Menus, with the On menu update
      setting off). Expect **no** invalidation. Menu items are fetched at request
      time by the front-end and are deliberately not in the snapshot.
- [ ] **Switch the On FSE update setting off, save, and edit a template part
      again.** Expect **nothing** in the console and nothing in the log — the
      escape hatch for a front-end that does not serve the endpoint yet.
- [ ] **Confirm the off is stored**:
      `npx wp-env run cli wp option get nextjs_revalidate-revalidate-on-fse-save`.
      Expect `off` rather than an empty value — an empty one would read as "never
      touched", and this setting defaults to on.
- [ ] **Switch it back on, save, and confirm an edit invalidates again.**
- [ ] **Clear the secret, save, and edit a template part.** Expect
      `⛔ Refused the FSE snapshot invalidation — site not configured (missing:
      secret)` in the log and **no** request in the console. Restore the secret.

## I. Scheduled purge

- [ ] **Schedule a post for two minutes from now and publish.** Expect **no**
      immediate revalidation of its path — it is not published yet.
- [ ] **Confirm the schedule is recorded**:
      `npx wp-env run cli wp option get nextjs-revalidate-scheduled_purges`.
      Expect an entry carrying the post's future timestamp.
- [ ] **Confirm the cron is set**:
      `npx wp-env run cli wp cron event list | grep scheduled_purges`.
- [ ] **Wait for the time to pass and load an admin page.** Expect a revalidation
      of the now-published path, and the option entry gone.

## J. The log file

- [ ] **Confirm its location**:
      `npx wp-env run cli -- ls -l wp-content/uploads/nextjs-revalidate.log`.
      Expect it in this site's own uploads directory.
- [ ] **Turn logging off** on the Debug tab, save, update a post, run cron.
      Expect the revalidation to still happen (console) and **no new lines** in
      the file. Every line the plugin can write passes through that one setting.
- [ ] **Turn logging back on** and confirm new lines appear.
- [ ] **Read a success line.** Expect
      `[timestamp]\t[INFO]\t[RevalidateQueue.php]  #id: ✅ Revalidated in Ns <permalink> (priority: N)`.
- [ ] **Break the secret, fail once, read the failure line.** Expect `[ERROR]`
      and `❌ Failed to revalidate after Ns <permalink> (priority: N) —
      http_401: The front-end answered 401.` — the code and message the front-end
      actually produced, not a generic failure. Restore the secret.
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

## L. The REST API (#100)

> **This section is a temporary exception under
> [ADR 0012](adr/0012-a-third-testing-idiom.md).** These routes need real
> WordPress state but no browser, so they belong in the wp-env PHPUnit suite.
> **Delete this section when #100 closes.**

- [ ] **Call the single route with the right secret:**
      ```sh
      curl -s -X POST http://localhost:8080/wp-json/nextjs-revalidate/v1/revalidate \
        -d 'secret=my-super-secret' -d 'path=/runbook-post/'
      ```
      Expect a success body and a queue row — the route reports the enqueue, not
      the delivery.
- [ ] **Call it with a wrong secret.** Expect a permission error and no queue row.
- [ ] **Call the batch route** at `/wp-json/nextjs-revalidate/v1/revalidate/batch`
      with an `items` array of two paths. Expect two queue rows.
- [ ] **Clear the secret and call either route.** Expect a `missing_secret` error
      with status 500, not a silent acceptance. Restore the secret.

## M. The French translation

- [ ] **Settings → General → Site Language → Français, save.**
- [ ] **Load the Next.js revalidate settings screen.** Expect tab labels, field
      labels and help text in French.
- [ ] **Trigger the unconfigured notice, then the degraded notice.** Expect both
      in French, with the numbers correctly placed in the degraded one.
- [ ] **Check the admin bar and the row action.** Expect "Purger cette page" and
      a French row action label.
- [ ] **Restore English and restore the secret.**

## N. Uninstallation

Run this last in Part 1 — it destroys the site's plugin data.

- [ ] **Deactivate, then Delete the plugin from the Plugins screen.** Expect no
      error.
- [ ] **Expect the table gone**:
      `npx wp-env run cli wp db query "SHOW TABLES LIKE 'wp_revalidate_queue'"`.
      Expect no rows.
- [ ] **Expect every option gone.** Check `nextjs_revalidate-domain`,
      `-endpoint_path`, `-fse_endpoint_path`, `-secret`,
      `-allow_revalidate_all`, `nextjs_revalidate-revalidate-on-menu-save`,
      `nextjs_revalidate-revalidate-on-fse-save`,
      `nextjs_revalidate-debug`, `nextjs_revalidate-db_version`,
      `nextjs_revalidate-failure_window`, `nextjs-revalidate-scheduled_purges`.
      Expect all "could not be found".
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

Precondition: N done, plugin **not** yet network-activated, at least two sites.

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
- [ ] **Publish a post on `second`.** Expect a revalidation carrying `second`'s
      permalink, landing in `second`'s queue table — not the main site's.
- [ ] **Expect a separate log file** for `second`, at
      `wp-content/uploads/sites/2/nextjs-revalidate.log`.
- [ ] **Break the main site's secret and fail three times.** Expect the degraded
      notice on the main site and **not** on `second` — the failure window is per
      site. Restore the main site's secret.

## S. A large network declines rather than truncates

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

## T. Network deactivation and uninstallation

Precondition: O done, plugin network-active, all sites set up.

- [ ] **Network Deactivate.** Expect the queue cron gone on **every** site, the
      settings kept on every site, and the failure window cleared on every site.
- [ ] **Network Activate, then Delete the plugin.** Expect every site's queue
      table dropped and every site's options gone — check `second` explicitly,
      not just the main site. A site is torn down at the same depth on a network
      as it would be alone.

## U. Teardown

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

## V. Raise a real 1.6.9 site

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

## W. A 1.6.9 site, configured the old way

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

## X. The upgrade

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
- [ ] **Update the post published in U.** Expect a revalidation of its path, and
      a queue table that now exists, created by the upgrade rather than by a
      fresh activation.
- [ ] **Reload wp-admin several times.** Expect the ledger to stay put and
      nothing to be re-migrated: a migration decides by the ledger, never by the
      plugin version.

## Y. Backfill from an older shape

Precondition: W done. This rewinds the ledger to fake a site that predates it.

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

## Z. Teardown

- [ ] **`npm run stop`**, then **`ls .wp-env.override.json`** and expect it
      absent.
- [ ] **`npx wp-env destroy`** and confirm.
- [ ] **`git status`.** Expect a clean tree, with no ticked boxes in either
      runbook.
