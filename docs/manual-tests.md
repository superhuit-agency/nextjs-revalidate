# Manual tests — the core pass

Thirty-one checks on a single site. This is the pass to run before a release,
and after any change worth the ten minutes.

It is not everything. The network stack, the upgraded stacks (from 1.6.9 and
from 1.7), and the
groups this one leaves out — endpoint composition, the probe,
revalidatable-post edges, row and bulk actions, the admin bar, revalidate all,
menu save, FSE update, scheduled purges, the log file, the French translation,
uninstallation — live in
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
| Log file | `npx wp-env run cli -- tail -n 30 <path>`, where `<path>` is the one printed under **Enable logs** on the Debug tab. It differs per site, so read it there rather than typing it from memory |
| The screen | wp-admin at http://localhost:8080/wp-admin |

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

- [ ] **Plugins → deactivate, then activate "Next.js Revalidate".** Expect no
      error and no white screen.
- [ ] **Settings → Next.js revalidate.** Expect four tabs — **Next.js API**,
      **Allow revalidate all**, **Debug**, **Probe** — and no **Queue**, **On
      FSE update** or **On menu update** tab: v2 removed all three. Click each:
      expect one panel visible at a time, Probe included, though it is a form of
      its own. (All four stacked means a broken `settings.js`.)
- [ ] **On Next.js API, confirm the seeded values**: domain
      `http://host.docker.internal:8083`, revalidate path `/revalidate`, secret
      `my-super-secret` — and no FSE revalidate path field: there is one
      endpoint from v2. On Debug, confirm "enable logs" is on, and note
      the log path printed beneath it — the **Log file** oracle reads it.
- [ ] **On Allow revalidate all, tick `post` and `page`, save.** Expect the
      settings-saved notice and both still ticked after the reload.
- [ ] **Publish a post "Runbook post" and a page "Runbook page".** Expect
      permalinks of the shape `http://localhost:8080/runbook-post/`. A `?p=123`
      permalink means the rewrite structure did not take and later steps will
      mislead.

> **State after the spine**, assumed by every section below: a configured site,
> logs on, revalidate all allowed for `post` and `page`, one published post and one
> published page.

## 3. Saving a post

Precondition: spine state.

A post save is a **post change**, delivered when the save's request ends —
there is nothing to wait for. The console prints each change as it was sent;
`N` below is the post's ID.

- [ ] **Edit "Runbook post", change a word, Update.** Expect
      `= Revalidating (v2): {"subject":"post","id":N,"type":"post","before":{"uri":"/runbook-post/"},"after":{"uri":"/runbook-post/"}}`
      in the revalidate server console straight away — one line, two equal
      sides.
- [ ] **Check the log.** Expect a line ending `✅ Revalidated 1 change (post)`.
- [ ] **Move it to Draft.** Expect `"before":{"uri":"/runbook-post/"},"after":null`
      — the path it held while published is the one the front-end still has
      cached, and it has no page now.
- [ ] **Publish it again.** Expect `"before":null,"after":{"uri":"/runbook-post/"}`.
- [ ] **Move it to Trash.** Expect `"before":{"uri":"/runbook-post/"},"after":null`
      — never the `__trashed` name the post takes on the way in. **Restore it,
      then publish it.** Expect nothing on the restore — a restored post comes
      back a draft — and `"before":null` on the publish.

## 4. The unconfigured site refuses

Precondition: spine state. This section clears settings and restores them at the
end — do not stop halfway.

- [ ] **Clear the secret, save.** Expect a warning notice at the top of every
      admin screen: "Next.js revalidate is not configured for this site — its
      secret is missing. Content is still saved, but every revalidation is
      refused…", with a "Configure Next.js revalidate" link, and no link while
      you are on the settings screen itself.
- [ ] **Clear the revalidate domain too, save.** Expect the notice to now read
      "its revalidate domain and secret are missing".
- [ ] **Update "Runbook post".** Expect **nothing** in the console, and
      `⛔ Refused a post change — site not configured (missing: domain, secret)`
      in the log — the change was refused when it was produced, not held and
      dropped later. Then admin bar → Revalidate → All: expect an error notice
      "Revalidate all: nothing was sent, this site is not configured."
- [ ] **Restore the domain and secret, save.** Expect the notice gone from every
      screen and a post save to revalidate again.

## 5. Degraded revalidation

Precondition: spine state. This section deliberately breaks the secret and
repairs it at the end.

- [ ] **Set the secret to `wrong-secret`, save, then update a post three times.**
      The site is still *configured*, so these are attempted and rejected —
      failures, not refusals. Expect three
      `❌ Failed to revalidate 1 change (post) — http_401: The front-end answered 401.`
      lines in the log, one per save.
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

## 6. The Redirection integration

Precondition: spine state, Redirection active (installed from `.wp-env.json`).
Complete its setup wizard once if prompted.

The oracle is the **revalidate server console**: a redirect is reported as a
**redirect change**, delivered in one v2 request once the save has answered, so
each revalidation below is a line like
`= Revalidating (v2): {"subject":"redirect","uri":"/old-path/"}`.

- [ ] **Tools → Redirection → add a redirect** from `/old-path/` to
      `/runbook-post/`, enabled. Expect
      `{"subject":"redirect","uri":"/old-path/"}` in the console — the
      **source**, not the target, and a `redirect` change rather than a `path`
      one. The front-end's cached 404 for that path is what is now wrong.
- [ ] **Edit it, changing the source to `/older-path/`.** Expect **one**
      redirect change, for `/older-path/`, and none for `/old-path/`. Redirection
      5.9.0 and later hand over only the redirect's id on an edit, so nothing
      carries the source it had — `/old-path/` keeps redirecting on the
      front-end until its cache entry expires. That is the recorded limit in
      `docs/adr/0014-redirect-changes-revalidate-the-source-path.md`, not a
      regression; a second redirect change here means upstream started
      passing the previous state again.
- [ ] **Disable it, enable it, then delete it.** Expect a redirect change for
      its source each time, one v2 request per action.
- [ ] **Add a regex redirect** (tick "Regex", source `^/blog/(.*)`). Expect **no**
      request in the console, and a log line saying it was skipped because
      "its source is a regular expression, which names no single path".

## 7. Deactivation

Precondition: spine state.

- [ ] **Deactivate the plugin, then check the crons**:
      `npx wp-env run cli wp cron event list`. Expect no
      `nextjs-revalidate-scheduled_purges`.
- [ ] **Expect the settings kept**:
      `wp option get nextjs_revalidate-domain` still returns its value.
      Deactivation is not uninstallation.
- [ ] **Expect the failure window cleared**:
      `wp option get nextjs_revalidate-failure_window`. Expect "could not be
      found" — the one exception, for the reason in section 5. Then reactivate
      and expect the crons rescheduled and the settings intact.

## 8. Teardown

- [ ] **`npm run stop`**, and Ctrl-C the revalidate dev server.
- [ ] **`git status`.** Expect a clean tree: no `.wp-env.override.json`, no edits
      to `.wp-env.json`, no ticked boxes staged in this file.
