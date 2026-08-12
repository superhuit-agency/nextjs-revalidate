# Nothing the AFK harness does can reach `main`, and the invariants that hold are structural rather than written down

The AFK agent harness in `.sandcastle/` runs unattended: it picks up
`ready-for-agent` issues, implements each one in a container on Opus 5, and opens
a PR. Nobody is watching while it does that. Everything below is a rule that has
to hold when nobody is watching, so none of it is enforced by an operator
remembering it — each one is a function that refuses, or a test that fails.

This is the design record. `.sandcastle/README.md` is the runbook, and it
deliberately does not repeat any of this: someone starting a run at 2am needs the
quick start, not the reasoning.

## Nothing reaches `main` except a human merging a PR

No phase merges into the base branch. `mergeInto` is the branch the *harness*
merges work into, so it is never `main` — a child merges into its epic branch, an
epic and a standalone merge nowhere at all. A test asserts that no file in the
harness shells out to `gh pr merge`; `pr create` is the harness's entire PR
vocabulary.

There is **no branch protection** on this repo and there will not be. The harness
holds an ADMIN token, so protection-with-admin-bypass would not constrain it, and
no-bypass protection would block the existing direct-push release flow.
Enforcement is structural instead, which is the theme of everything that follows.

## One push chokepoint

Every push goes through `pushBranch()` in `lib/git.mts`, which throws on any
branch outside `sandcastle/` and never passes `--force`. A test asserts that no
other file in the harness shells out to `git push`. Agents never invoke
`git push` or `gh pr` at all, and the sandbox image carries no `gh` and no GitHub
credential — an agent that cannot reach GitHub cannot push or open a PR by
accident.

`createLinkedBranch` is the one ref that reaches origin without git: GitHub
creates a bare epic branch server-side, because creating it and linking it to the
issue is a single call and there is no other way to get the link. That call
carries the same prefix rule, by calling the same `assertWritableBranch()`.

## The primary checkout is never checked out

The harness never runs `git checkout` in it. Pushing a branch and opening a PR
both work without a working tree; anything that genuinely needs one — a squash
merge, a body written from a branch — gets a throwaway worktree under
`.sandcastle/worktrees/`. The run snapshots the branch and `git status
--porcelain` on the way in and compares both on the way out, exiting 4 if either
moved.

The snapshot is not an assertion that the tree is clean. The invariant is that
the harness leaves the checkout exactly as it found it, and that has to hold
whether or not the operator has edits of their own in progress.

## Branch freshness is the host's job

sandcastle 0.12.0 **ignores `baseBranch` when the branch already exists**. Work
branches are therefore never re-cut from an updated parent by sandcastle itself,
and the harness brings each one to a correct starting point before every pass:

| Branch state | Action |
| --- | --- |
| Zero commits over its base | delete and re-cut |
| Has commits, **not** on origin | rebase onto the base; on conflict, abort the rebase and skip the item this pass |
| Has commits **and** on origin | merge the base in. **Never** rebase |

The last row is the one with teeth: rebasing a published branch needs a
force-push to land, and that destroys an open PR's review comments. An item whose
freshness step was skipped is not implemented — its branch was left exactly as
found, so it is not at a correct starting point.

`ensureLocalBranch()` fast-forwards **only**, and refuses to move a branch
carrying unpushed commits: a blind `git branch -f <b> origin/<b>` silently
discards squash-merges from earlier cycles.

## A bad plan aborts the whole run, not the item

Three conditions abort the entire run and report every violation at once: a
`workBranch` or `mergeInto` equal to the base branch, a `workBranch` outside
`sandcastle/`, and a child whose `mergeInto` is not a known epic branch. A
planner confused enough to emit one of these is not to be partially obeyed.

Because the plan is built by deterministic code rather than by an agent, the
third check is a regression net rather than a runtime possibility — `planBatch`
derives `mergeInto` from the same parent link the validator reads. The half that
*can* fire at runtime is later: once epic branches have really been created, a
child pointing at one that is not there aborts the run.

## Issues close when their code reaches `main`, never earlier

The harness closes exactly one kind of issue: a child, on squash-merge into its
epic branch. That is consistent rather than an exception — a child has no route
to `main` other than its epic branch, so reaching that branch is as far as its
code ever gets on its own. Standalones and epics carry `Closes #<n>` in their PR
body and wait for a human to merge.

## The gate is the arbiter, and the harness runs it

The implement prompt tells the agent to run `npm run typecheck` and
`npm run lint:php` before committing, but that is the agent's self-check. What
decides an item is done is the harness running the same two scripts itself,
inside the same container, after the agent has stopped. An agent that signals
completion over a red gate produces a `gate-failed` item.

**The gate is the plugin's own npm scripts, not a harness-owned script.** An
earlier design had `scripts/gate.sh`, tracked in the repo and read out of the
work branch — which meant a branch cut from a `main` that did not yet carry it
could not be gated at all, and every item came back with no verdict until the
gate itself was merged. Defining the gate as npm scripts removes that bootstrap
state, and removes the second definition of "shippable" that a developer running
`npm run typecheck` by hand would have drifted from.

`lint:php` refuses to run on anything but PHP 7.4 unless
`ALLOW_PHP_VERSION_MISMATCH=1` says otherwise. That refusal is the point of the
step: on a PHP 8 parser every PHP 8-only construct lints clean, so a green lint
there is worse than no lint at all. The sandbox image pins `PHP_BIN` to
`/usr/bin/php7.4`, so a harness run is always authoritative; a developer on a
PHP 8 host is told, not quietly reassured.

The gate is weak on purpose, and knowing how weak matters more than the gate
does: this repo has no test suite and nothing runs on PRs, so it catches syntax
and type errors and **zero logic errors**. It is now weaker than the shell gate
it replaces in one specific way — that one also ran `npm run build`, so a
webpack failure used to fail an item and no longer does. That was accepted to
keep the gate to scripts a developer already runs; `tsc` covers the TypeScript
either way, and #34 files the real PHP compatibility gate.

That weakness is why implementers run on Opus 5 — implementer judgement is the
only real quality control in this pipeline, and downgrading the model does not
make the gate any stricter.

## Considered Options

**A separate `.sandcastle` package.** The harness had its own `package.json`,
lockfile, `node_modules` and `tsconfig.json`, so the plugin's dependency tree and
release build stayed untouched. Rejected once the release zip was built from an
allowlist (d12e9c2): devDependencies cannot leak into a release that names its
payload file by file, which removed the entire reason for the split — and the
split cost a second install step, a second lockfile and a `--prefix` in front of
every command. `@ai-hero/sandcastle` and `tsx` are ordinary root
devDependencies; `npm install` gets you a working harness.

One file from that package survives the deletion: its `tsconfig.json`. The root
config targets `es5` and excludes `.sandcastle`, and `tsx` strips types without
checking them — so with it gone, nothing would typecheck three thousand lines of
TypeScript. It is a quality gate rather than packaging, it costs the quick start
nothing, and `npm run sandcastle:typecheck` runs it.

**A mode-per-phase CLI.** `--dry-run`, `--plan`, `--prepare`, `--implement` and
`--finalize`, each one step further than the last, with `--issue N` to narrow the
batch. Every mode was individually defensible and the set was not: five ways to
start meant a decision before every run, a 658-line runbook explaining the
decision, and a harness nobody reached for. `npm run sandcastle` runs a full
pass; `--dry-run` is the single escape hatch, because seeing the batch before
committing to it is a real thing operators want. A harness nobody reaches for
produces no PRs, which is worse than any composition it made possible.

**A pre-flight in the runbook.** Five `git` commands the operator ran before a
session — fetch, check `main` against origin, list worktrees, list leftover
`sandcastle/*` branches locally and on origin. A ritual an operator has to
remember is a bug, not documentation: it gets skipped exactly on the night it
would have mattered. The checks that can be code are code, run on every pass —
fetch, heal stale worktrees, refuse to run at all when the checkout is parked on
a `sandcastle/*` branch.

The leftover-branch check is the one that could not become code, and it is worth
knowing why. Freshness re-cuts a work branch only when it carries *zero* commits
over its base; a branch with commits is brought forward instead. So a
`sandcastle/issue-N` abandoned by an earlier run does not look abandoned — it
looks like work in progress, and it gets implemented on top of and shipped in
issue N's PR. Nothing can distinguish that from a legitimate resumption. A branch
with an **open PR** is a different case and is safe: the re-pick guard prunes its
issue.

**A build script for the sandbox image.** `sandbox/build.sh` read the Node
version out of `.nvmrc`, baked in the host UID/GID and tagged the result — 66
lines duplicating `sandcastle docker build-image`, which does the UID/GID part
itself and reads `.sandcastle/Dockerfile`. The harness now shells to that when
`docker image inspect` misses, so a first run needs no build step the operator
has to know about. The cost is that the Node version is pinned in the Dockerfile
by hand rather than tracked from `.nvmrc`; that is one line, checked at build
time, against a build script that had to exist and be remembered.
