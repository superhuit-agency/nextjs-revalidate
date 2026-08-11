# `.sandcastle` — AFK agent harness

The harness that picks up `ready-for-agent` issues, implements them in an
isolated container and opens a PR into `main`. It **never** merges into
`main` itself.

Design and invariants live in issue #33.

## Why this is a separate package

`@ai-hero/sandcastle` is **not** a root dependency. It gets its own
`package.json` and `node_modules` here so the plugin's dependency tree,
lockfile, release build and gate stay untouched — the plugin and the harness
have independent Node and TypeScript requirements, and the harness runs only
on the host, never inside the sandbox.

Consequences that are easy to undo by accident:

- the root `tsconfig.json` excludes `.sandcastle`, so the gate's
  `tsc --noEmit` never type-checks harness code against `target: es5`
- the harness carries its own `typescript` and enables `erasableSyntaxOnly`,
  which keeps every `.mts` file runnable under Node's native type-stripping
  (no `tsx`, no build step)

## Install

Requires Node 24+ and an authenticated `gh` CLI.

```sh
npm --prefix .sandcastle install
```

This does not touch the root `package-lock.json`.

## Sandbox image

The image implementers run inside. It carries two runtimes: Node — whichever
version `.nvmrc` pins — and PHP 7.4, which is what `scripts/gate.sh` lints
against.

```sh
.sandcastle/sandbox/build.sh
```

Tags `nextjs-revalidate-sandbox:node<version>` and
`nextjs-revalidate-sandbox:latest`. Extra arguments pass through to
`docker build` (`--no-cache`, …); `IMAGE_NAME` overrides the name.

**The Node version is not in the Dockerfile.** `build.sh` reads it from
`.nvmrc` and passes it as `NODE_VERSION`. So a Node bump lands as an ordinary
PR and the harness follows after one rebuild — no edit to the image
definition. The same holds for the package manager, which the gate detects
from the lockfile. `.nvmrc` must hold a version number; an alias such as
`lts/*` has no matching `node:<version>` image tag and `build.sh` refuses it.

PHP 7.4 comes from [Sury](https://packages.sury.org/php/) — it is past
upstream EOL and not in Debian bookworm. `PHP_BIN` is baked into the image as
`/usr/bin/php7.4`, the versioned binary rather than the `php` alternative, so
the gate cannot be silently redirected to a newer parser. The build fails if
that binary is not 7.4.

Rebuild after: a change to `.nvmrc`, or a change to what the gate needs
installed. Nothing is copied into the image — the checkout is mounted at run
time — so ordinary code changes need no rebuild.

To run the gate in it by hand, exactly as the harness will:

```sh
docker run --rm -v "$PWD":/workspace -w /workspace \
  nextjs-revalidate-sandbox:latest ./scripts/gate.sh
```

Note this installs Linux `node_modules` over your host tree; run it on a
throwaway clone if that matters.

## Modes

Three, each one step further than the last. None of them starts a container,
and none of them pushes.

```sh
node .sandcastle/run.mts --dry-run    # the batch it would work
node .sandcastle/run.mts --plan       # + the plan, validated
node .sandcastle/run.mts --prepare    # + each work branch made current
node .sandcastle/run.mts --plan --json
```

`--dry-run` and `--plan` are read-only. `--prepare` is the first mode that
writes anything, and what it writes is *local branches only*. Running with no
mode is an error — the execute path lands with the implement phase (#43) — so
an operator can never think a real pass has happened.

## What the dry run selects

Start from open issues labelled `ready-for-agent`, then prune:

- **open blockers** — GitHub's native issue dependencies
  (`GET /repos/{owner}/{repo}/issues/{n}/dependencies/blocked_by`), falling
  back to a `Blocked by: #<n>` body line where dependencies are unavailable.
  A candidate is eligible only when every blocker is closed. An unresolvable
  blocker counts as open.
- **already worked** — the *re-pick guard*: any PR on `sandcastle/issue-<n>`,
  in any state including closed. This is load-bearing; it is what holds when
  the label swap fails, so do not quietly weaken it.

Epic and sub-issue *machinery* is out of scope here (#45). Gathering still
reports each candidate's parent and children so later phases have it: native
sub-issue links are canonical, a `Sub-issue of #N` / `Part of #N` body marker
is a fallback, and when the two disagree native wins and the conflict is
logged.

## The plan, and what it refuses

A plan says which issue goes on which `workBranch` and what that branch merges
into. It is then validated **in code**, not trusted. Three conditions abort the
**entire run**, not just the offending item — a planner confused enough to emit
one of these is not to be partially obeyed:

- `workBranch` or `mergeInto` equal to the base branch
- `workBranch` not matching `^sandcastle/`
- a `child` whose `mergeInto` is not a known epic branch from gathered context

`mergeInto` is the branch the *harness* merges the work into, so it is never
`main`: nothing reaches `main` except a human merging a PR. Standalones and
epics carry `mergeInto: null`.

Today the plan contains **standalone issues only**. Epic and child machinery is
#45, and a child planned before it exists would be cut from an epic branch
nothing creates — so those candidates are *deferred*: reported by name, and
left for the run that can work them. Deferring is a per-item outcome, not an
abort; #42 and #28 are both children of parents that are not themselves
`ready-for-agent`, and aborting on them would refuse every run this repo can
currently produce.

## Branch freshness

sandcastle 0.12.0 **ignores `baseBranch` when the branch already exists** —
confirmed in its types. Branches are never re-cut from an updated parent, so
the host brings each work branch to a correct starting point itself, before
every execute:

| Branch state | Action |
| --- | --- |
| Zero commits over its base | delete and re-cut |
| Has commits, **not** on origin | rebase onto the base; on conflict abort the rebase and skip the item this iteration |
| Has commits **and** on origin | merge the base in. **Never** rebase |

The last row is the one with teeth: rebasing a published branch would need a
force-push to land, and that destroys an open PR's review comments.

`ensureLocalBranch()` fast-forwards **only**, and refuses to move a branch
carrying unpushed commits — a blind `git branch -f <b> origin/<b>` silently
discards squash-merges from earlier cycles.

## The push chokepoint

There is no branch protection on this repo and there will not be: the harness
holds an ADMIN token, so protection-with-admin-bypass would not constrain it,
and no-bypass protection would block the existing direct-push release flow.

Enforcement is structural instead — a single `pushBranch()` in `lib/git.mts`
that every push goes through, which throws on any branch outside `sandcastle/`
and never passes `--force`. A test asserts no other file in the harness shells
out to `git push`. Agents never invoke `git push` or `gh pr` at all.

## The primary checkout is untouchable

The harness never runs `git checkout` in it. Pushing a branch and opening a PR
both work without checking out; anything that genuinely needs a working tree
gets its own worktree under `.sandcastle/worktrees/` (gitignored, removed after
use). `--prepare` verifies this at the end of the run and exits non-zero if the
checkout moved or its working tree changed.

## Test and typecheck

```sh
npm --prefix .sandcastle test
npm --prefix .sandcastle run typecheck
```

The tests build real scratch repositories with a real `origin` in `$TMPDIR` —
the freshness rules are entirely about what git does to real refs, and a mocked
git would prove nothing. They touch neither this repo nor the network.

## Untracked paths

`logs/`, `worktrees/`, `node_modules/` and `.env` — see `.sandcastle/.gitignore`.
Everything else here is tracked.
