# `.sandcastle` — AFK agent harness

The harness that picks up `ready-for-agent` issues, implements them in an
isolated container and opens a PR into `main`. It **never** merges into
`main` itself.

Design and invariants live in issue #33.

## Quick start

```sh
npm --prefix .sandcastle install         # once
cp .sandcastle/.env.example .sandcastle/.env   # then fill in one key — see "Container auth"
.sandcastle/sandbox/build.sh             # once, and after a .nvmrc change

node .sandcastle/run.mts --dry-run                  # what would be worked
node .sandcastle/run.mts --implement --issue 35     # work one issue, stop before origin
node .sandcastle/run.mts --finalize --issue 35      # the full pass, ending in a PR
```

`--implement` leaves commits on `sandcastle/issue-35` and nothing else: nothing
on origin, no PR, and your own checkout untouched on whatever branch it was on.
`--finalize` goes one step further and ends with a PR into `main` waiting for a
human. It never merges.

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

## Container auth to Claude

**An environment variable out of the gitignored `.sandcastle/.env`. Host
credentials are not mounted.** This was the spec's one open item; what
sandcastle 0.12.0 supports settles it.

Its env resolver reads `.sandcastle/.env` and, for every key *named there*,
takes the file's value or falls back to `process.env`. So:

- a key the file does not name never reaches the container, however loudly
  your shell exports it — the harness checks this on the host and refuses the
  run rather than letting it fail three layers down;
- a key named with an empty value means "take it from the shell running the
  harness", which is the way to keep the secret out of any file at all.

There is no credential-mount path. `docker({ mounts })` would take an
arbitrary host directory, but on macOS Claude Code keeps subscription
credentials in the login Keychain — there is no file to mount.
`claude setup-token` turns the same subscription into a long-lived token,
which *is* just a string, and that is what the container gets.

```sh
cp .sandcastle/.env.example .sandcastle/.env
claude setup-token          # paste the result into CLAUDE_CODE_OAUTH_TOKEN
```

Either key works, `CLAUDE_CODE_OAUTH_TOKEN` first:

| Key | Bills |
| --- | --- |
| `CLAUDE_CODE_OAUTH_TOKEN` | the operator's Claude subscription |
| `ANTHROPIC_API_KEY` | the API account |

Nothing secret is baked into the image; the value arrives as a
`docker run -e` when the container starts. `.sandcastle/.env` is gitignored
and is the only place a secret lives — `.env.example` is the tracked template.

## Sandbox image

The image implementers run inside. It carries three things: Node — whichever
version `.nvmrc` pins — PHP 7.4, which is what `scripts/gate.sh` lints
against, and the Claude Code CLI, which is what sandcastle invokes.

What it deliberately does **not** carry is `gh`, or any credential for it. The
implement phase never pushes and never opens a PR, and an agent that cannot
reach GitHub cannot do either by accident.

```sh
.sandcastle/sandbox/build.sh
```

Tags `nextjs-revalidate-sandbox:node<version>` and
`nextjs-revalidate-sandbox:latest`. Extra arguments pass through to
`docker build` (`--no-cache`, …); `IMAGE_NAME` overrides the name.

**The image is built for one host user.** sandcastle runs the container as
your UID/GID and refuses an image built with a different one, so `build.sh`
bakes in `id -u` / `id -g` (overridable as `AGENT_UID` / `AGENT_GID`). That is
also what makes the bind-mounted worktree writable with no runtime `chown`.
An image is not shareable between operators with different UIDs — rebuild.

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
docker run --rm -v "$PWD":/home/agent/workspace -w /home/agent/workspace \
  --entrypoint ./scripts/gate.sh nextjs-revalidate-sandbox:latest
```

`--entrypoint` is not optional: the image's entrypoint is `sleep infinity`, so
that the container stays alive for sandcastle to `docker exec` into. A command
appended the usual way would become an argument to `sleep` instead of running.

Note this installs Linux `node_modules` over your host tree; run it on a
throwaway clone if that matters.

## Modes

Five, each one step further than the last. **Only `--finalize` reaches origin
or opens a PR**, and even it never merges.

```sh
node .sandcastle/run.mts --dry-run     # the batch it would work
node .sandcastle/run.mts --plan        # + the plan, validated
node .sandcastle/run.mts --prepare     # + each work branch made current
node .sandcastle/run.mts --implement   # + implementers in containers, gated
node .sandcastle/run.mts --finalize    # + branches to origin, PRs into main
node .sandcastle/run.mts --plan --json
node .sandcastle/run.mts --finalize --issue 35     # one issue only; repeatable
```

`--dry-run` and `--plan` are read-only. `--prepare` is the first mode that
writes anything, and what it writes is *local branches only*. `--implement` is
the first that starts a container and the first that writes code. `--finalize`
is the full pass — it implies `--implement` — and the only one anything outside
this machine sees. Running with no mode is an error, so an operator can never
think a real pass has happened.

`--issue N` restricts the batch to the issues you name. A number that is not
in the planned batch is fatal rather than a warning: pointing the harness at
one issue and getting a silent no-op reads as "the run happened and did
nothing".

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

## The implement phase

One item, one sandbox. sandcastle cuts a worktree on the item's work branch,
bind-mounts it into the image and runs an implementer against the issue.
Commits land on the local work branch and stop there.

An item whose freshness step was **skipped** — a rebase or merge that
conflicted — is not implemented. Its branch was left exactly as found, so it
is not at a correct starting point, and building on it would build on a stale
base.

Budget, all of it in `lib/config.mts`:

| Setting | Value | Why |
| --- | --- | --- |
| concurrency | 3 | ~1 GB per container against 11.67 GiB; a realistic batch is 2–4 items |
| `maxIterations` | 30 | agent invocations before the harness gives up on an item |
| `idleTimeoutSeconds` | 600 | silence from the agent before its iteration fails |
| model | Opus 5 | see below |

With a syntax-only gate and no PR CI, implementer judgement is the only real
quality control this pipeline has, and the backlog is small enough that the
cost of the strongest model is bounded. Downgrading it does not make the gate
any stricter.

### The gate is the arbiter, and the harness runs it

The agent is told to run `scripts/gate.sh` before signalling completion, but
that is the agent's self-check. What decides an item is done is
`sandbox.exec()` — the harness running the same gate itself, in the same
container, after the agent has stopped. An agent that signals completion over
a red gate produces a `gate-failed` item, and the outcome says so in as many
words.

Because the gate is tracked in the repo rather than in a gitignored directory,
the freshness rule keeps every work branch's copy current and nothing needs
seeding into the worktree. **That premise has a precondition: the gate has to
be on the base branch.** A work branch cut from a `main` that does not carry
`scripts/gate.sh` cannot be gated at all, so the harness probes for it before
running it and reports `gate-missing` — no verdict exists, and it is the
setup's fault rather than the implementer's. Calling that a failed gate would
blame the agent for it.

Five outcomes per item:

| Outcome | Meaning |
| --- | --- |
| `implemented` | commits on the branch, gate green |
| `gate-failed` | commits on the branch, gate red — left for the next cycle or a human |
| `gate-missing` | the gate is not on this branch; nothing was gated. Land it on the base first |
| `no-commits` | the agent wrote nothing; a green gate on an untouched tree proves nothing |
| `error` | the sandbox or the run failed; the batch carried on without it |

A batch where nothing reached `implemented` exits non-zero. One bad item never
takes the batch down: a failure becomes an outcome, and the sandbox is torn
down on every path — a leaked container would hold the work branch checked out
and block the next pass's freshness step.

Per-item logs land in `.sandcastle/logs/issue-<n>.log` (gitignored).

## The finalize phase

Deterministic code, not an agent. For each item the gate left **green**, in
order: the branch goes to origin through the chokepoint, any PR on that head is
looked up, one is opened if there is none, and the issue is handed back to a
human. Items that were `gate-failed`, `no-commits`, `gate-missing` or `error`
are not finalized at all — a PR into `main` is the harness asking a human to
merge, and it has no business asking that of work it could not verify. Those
keep their branch, their label and their issue for the next cycle.

Three details, each expensive to get wrong:

- **PR existence is checked across every state** — the same `findExistingPr()`
  the re-pick guard uses. A *closed* PR on a work branch never triggers a
  re-create attempt; a human decided against that branch, and re-creating would
  be the harness arguing with them.
- **A rejected update to origin fails loudly.** It becomes an `error` outcome
  carrying git's own stderr and takes the run's exit code with it. Nothing
  retries, and nothing anywhere passes `--force`.
- **The PR is not a draft.** Nothing in this repo filters on draft state — no
  PR CI, no preview environments — so drafting would only add a click between
  the harness and the human it is handing to.

### The handoff, and why the issue stays open

On an open PR, finalize swaps `ready-for-agent` → `ready-for-human` in one
`gh issue edit` call and leaves a comment linking the PR. **The issue is not
closed** — the harness never closes a standalone issue. The PR body carries
`Closes #<n>`, so GitHub closes it when a human merges, and that merge is the
only thing that ever reaches `main`.

Re-running the harness immediately picks up nothing, and *two independent
things* each guarantee that: the ready label is gone, and the open PR trips the
re-pick guard. Either alone is enough, which is why a failed label swap is
reported and fails the run but is not a correctness problem.

| Outcome | Meaning |
| --- | --- |
| `pr-opened` | branch on origin, non-draft PR into the base, issue handed off |
| `pr-exists` | a PR was already on this head. Open → hand off anyway (a handoff that failed last pass); closed or merged → leave it alone |
| `error` | the update to origin was refused, or `gh` failed. The batch carried on; the run exits non-zero |

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
use) — including the worktrees sandcastle itself mounts into the containers.
Every writing mode verifies this at the end of the run and exits non-zero if
the checkout moved or its working tree changed.

## Test and typecheck

```sh
npm --prefix .sandcastle test
npm --prefix .sandcastle run typecheck
```

The tests build real scratch repositories with a real `origin` in `$TMPDIR` —
the freshness rules are entirely about what git does to real refs, and a mocked
git would prove nothing. They touch neither this repo nor the network.

The implement phase takes its sandbox from an injected dependency, so its tests
put a fake one in front of it and assert the thing that matters — that the
gate's verdict decides, not the agent's claim — with no container, no network
and no API key.

## Untracked paths

`logs/`, `worktrees/`, `node_modules/` and `.env` — see `.sandcastle/.gitignore`.
Everything else here is tracked, `.env.example` included.
