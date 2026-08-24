# Manual tests — the core pass

Thirty-five checks on a single site. This is the pass to run before a release,
and after any change worth the ten minutes.

It is not everything. The network stack, the upgrade-from-1.6.9 stack, and the
groups this one leaves out — endpoint composition, revalidatable-post edges, row
and bulk actions, the admin bar, revalidate all, menu save, scheduled purges, the
log file, the REST API, the French translation, uninstallation — live in
[`manual-tests-extended.md`](manual-tests-extended.md). **No step appears in both
files.** Run the extended pass when you have touched its ground, and before a
release that changes anything structural.

Why this document exists and what belongs in it:
[ADR 0012](adr/0012-a-third-testing-idiom.md). Agents changing code:
[`agents/manual-tests.md`](agents/manual-tests.md).

## How to read this

**The boxes are never ticked in the repository.** Tick them in your working copy
during a pass; do not commit the ticks. A ticked box on `main` is a mistake.

**Steps run in order.** Each section states its precondition in full at the top.
The **spine** establishes the state every group after it assumes.

**Every step names its oracle** — the thing you look at to decide pass or fail:

| Oracle | Where |
| --- | --- |
| Revalidate server console | the terminal running `npm start` |
| Log file | `npx wp-env run cli -- tail -n 30 wp-content/uploads/nextjs-revalidate.log` |
| Queue table | `npx wp-env run cli wp db query "SELECT * FROM wp_revalidate_queue"` |
| The screen | wp-admin at http://localhost:8080/wp-admin |

**Cron does not run on a quiet site.** WordPress fires cron on page loads, so
after an action that enqueues, either load a page or force it:

```sh
npx wp-env run cli wp cron event run nextjs_revalidate-queue
```

---

## 1. Setup

Precondition: nothing running.

- [ ] **`ls .wp-env.override.json`.** Expect "No such file or directory". If it
      exists it is a leftover from an extended pass, and it silently changes what
      starts — `rm` it before going further.
- [ ] **`npm run build`.** Expect a `dist/` holding `editor.js`, `settings.js`
      and the settings CSS. The block editor notices and the settings tabs are
      built assets; a stale `dist/` makes later steps fail for the wrong reason.
- [ ] **`npm start`.** Expect wp-env to finish, then
      `Revalidate dev server is running on port 8083` in the same terminal.
      Leave it running — it is the oracle for most of what follows.

## 2. Spine — activate, configure, publish

Precondition: section 1 done.

- [ ] **Plugins → deactivate, then activate "Next.js revalidate".** Expect no
      error and no white screen.
- [ ] **Confirm the queue table exists**:
      `npx wp-env run cli wp db query "SHOW TABLES LIKE 'wp_revalidate_queue'"`.
      Expect one row.
- [ ] **Settings → Next.js revalidate.** Expect five tabs — **Next.js API**,
      **Allow purge all**, **On menu update**, **Debug**, **Queue** — with a
      count badge on Queue. Click each: expect one panel visible at a time. (All
      five stacked means a broken `settings.js`.)
- [ ] **On Next.js API, confirm the seeded values**: domain
      `http://host.docker.internal:8083`, revalidate path `/revalidate`, FSE
      revalidate path empty but showing a `/api/revalidate-fse` placeholder,
      secret `my-super-secret`. On Debug, confirm "enable logs" is on.
- [ ] **On Allow purge all, tick `post` and `page`, save.** Expect the
      settings-saved notice and both still ticked after the reload.
- [ ] **Publish a post "Runbook post" and a page "Runbook page".** Expect
      permalinks of the shape `http://localhost:8080/runbook-post/`. A `?p=123`
      permalink means the rewrite structure did not take and later steps will
      mislead.

> **State after the spine**, assumed by every section below: a configured site,
> logs on, purge-all allowed for `post` and `page`, one published post and one
> published page.

## 3. Saving a post

Precondition: spine state.

- [ ] **Edit "Runbook post", change a word, Update.** Expect
      `= Revalidating: /runbook-post/` in the revalidate server console within a
      minute, or immediately after forcing the cron.
- [ ] **Check the log.** Expect a line of the shape
      `#12: ✅ Revalidated in 0.04s http://localhost:8080/runbook-post/ (priority: 10)`
      — the queue holds the permalink, and the front-end is asked for the path.
- [ ] **Move it to Draft.** Expect a revalidation of `/runbook-post/` — the path
      it held while published is the one the front-end still has cached.
- [ ] **Move it to Trash, then restore and republish.** Expect a revalidation
      each time.

## 4. The queue

Precondition: spine state.

- [ ] **Admin bar → Next.js revalidate → All**, then open Settings → Next.js
      revalidate → Queue. Expect the badge to show a non-zero count matching the
      table, and a notice "Purging caches. Please wait… " with a "View purge
      caches queue" link.
- [ ] **Load admin pages until it drains.** Expect the badge to fall to zero, the
      table to empty, and one console line per path with no duplicates.
- [ ] **Update the same post twice before the cron runs.** Expect **one** queue
      row for that permalink, not two.
- [ ] **Queue a batch, then use the reset control on the Queue tab.** Expect the
      notice "Queue correctly resetted." and an empty table.

## 5. The unconfigured site refuses

Precondition: spine state. This section clears settings and restores them at the
end — do not stop halfway.

- [ ] **Clear the secret, save.** Expect a warning notice at the top of every
      admin screen: "Next.js revalidate is not configured for this site — its
      secret is missing. Content is still saved, but every revalidation is
      refused…", with a "Configure Next.js revalidate" link, and no link while
      you are on the settings screen itself.
- [ ] **Clear the revalidate domain too, save.** Expect the notice to now read
      "its revalidate domain and secret are missing".
- [ ] **Update "Runbook post".** Expect **nothing** in the console and **no new
      queue row** — the revalidation was refused at enqueue, not queued and
      dropped later. Then admin bar → Next.js revalidate → All: expect an error
      notice "Revalidate all: nothing was queued, this site is not configured."
- [ ] **Restore the domain and secret, save.** Expect the notice gone from every
      screen and a post save to revalidate again.

## 6. Degraded revalidation

Precondition: spine state. This section deliberately breaks the secret and
repairs it at the end.

- [ ] **Set the secret to `wrong-secret`, save, then update a post three times,
      forcing the cron after each.** The site is still *configured*, so these are
      attempted and rejected — failures, not refusals. Expect three
      `❌ Failed to revalidate … http_401: The front-end answered 401.` lines in
      the log.
- [ ] **Load any classic admin screen.** Expect an error notice: "Next.js
      revalidate is not keeping this site up to date — 3 of the last 10
      revalidations failed…", naming the most recent error as "the front-end
      rejected the secret", with a "Check the Next.js revalidate settings" link.
- [ ] **Open the block editor.** Expect the same warning as a block editor
      notice, and expect it **not** to be dismissible — it is a condition, not an
      acknowledgement.
- [ ] **Deactivate and reactivate the plugin while still degraded.** Expect the
      notice **gone**. The failure window is the one piece of state deactivation
      clears: a gap in which nothing was attempted leaves the front-end's health
      unknown rather than bad.
- [ ] **Restore the correct secret and revalidate successfully several times.**
      Expect the notice to stay gone once fewer than three of the last ten
      outcomes are failures — recovery is a live property, not a flag anyone
      clears.

## 7. The Redirection integration

Precondition: spine state, Redirection active (installed from `.wp-env.json`).
Complete its setup wizard once if prompted.

- [ ] **Tools → Redirection → add a redirect** from `/old-path/` to
      `/runbook-post/`, enabled. Expect a revalidation of `/old-path/` — the
      **source**, not the target. The front-end's cached 404 for that path is
      what is now wrong.
- [ ] **Edit it, changing the source to `/older-path/`.** Expect **two**
      revalidations, `/old-path/` and `/older-path/`: one stopped redirecting,
      one started.
- [ ] **Disable it, enable it, then delete it.** Expect a revalidation of its
      source each time.
- [ ] **Add a regex redirect** (tick "Regex", source `^/blog/(.*)`). Expect **no**
      revalidation, and a log line saying it was skipped because "its source is a
      regular expression, which names no single path".

## 8. Deactivation

Precondition: spine state, with items queued.

- [ ] **Deactivate the plugin, then check the crons**:
      `npx wp-env run cli wp cron event list`. Expect no
      `nextjs_revalidate-queue` and no `nextjs-revalidate-scheduled_purges`.
- [ ] **Expect the settings and the queue table kept**:
      `wp option get nextjs_revalidate-domain` still returns its value, and
      `SHOW TABLES LIKE 'wp_revalidate_queue'` still returns a row. Deactivation
      is not uninstallation.
- [ ] **Expect the failure window cleared**:
      `wp option get nextjs_revalidate-failure_window`. Expect "could not be
      found" — the one exception, for the reason in section 6. Then reactivate
      and expect the crons rescheduled and the settings intact.

## 9. Teardown

- [ ] **`npm run stop`**, and Ctrl-C the revalidate dev server.
- [ ] **`git status`.** Expect a clean tree: no `.wp-env.override.json`, no edits
      to `.wp-env.json`, no ticked boxes staged in this file.
