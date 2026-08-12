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

# before every session — see "Pre-flight"
git fetch origin && git worktree list && git branch --list 'sandcastle/*'

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

## Pre-flight

Run this in the primary checkout before the first pass of a session. It is
three checks and it takes seconds, and each one has a specific way of going
wrong behind it.

```sh
git fetch origin
git rev-list --left-right --count main...origin/main   # want: two zeros
git worktree list                                      # want: the primary checkout only
git branch --list 'sandcastle/*'                       # want: nothing
git ls-remote --heads origin 'refs/heads/sandcastle/*' # want: nothing
```

| Check | Why it matters |
| --- | --- |
| `main` in sync with origin | Work branches are cut from local `main`, and [`ensureLocalBranch()`](#branch-freshness) will not move it past unpushed commits of your own — it warns and cuts from it as-is, so the whole batch inherits whatever is sitting there |
| no stale worktrees | The implement phase mounts a worktree per item. A worktree left over from a killed run still holds its branch checked out, and the freshness step cannot move a branch that is checked out somewhere else |
| no leftover `sandcastle/*` branches, **locally or on origin** | The dangerous one — see below. Check both: a branch left on origin is invisible in `git branch` but is still what the next pass builds on |

**The leftover-branch case is the one worth understanding.** [Freshness](#branch-freshness)
re-cuts a work branch only when it has *zero* commits over its base; a branch
with commits is brought forward instead. So a `sandcastle/issue-N` left behind
from an abandoned run does not look abandoned to the harness — it looks like
work in progress. It gets rebased onto the current base, implemented on top of,
and shipped in issue N's PR.

Usually those are issue N's own half-finished commits, and the cost is an agent
building on a false start. The expensive case is a work branch someone once
pointed somewhere else: whatever it carries over the base is what the harness
treats as issue N's work, and that is what lands in issue N's PR.

Nothing detects that for you. If a `sandcastle/*` branch is not work you intend
to continue, delete it — locally and on origin — before the run:

```sh
git branch -D sandcastle/issue-N
git push origin --delete sandcastle/issue-N   # only if it got that far
```

A branch that already has an **open PR** is a different thing and should be
left alone: the re-pick guard prunes its issue, so the run will not touch it.

### The first run has to wait for the gate

One bootstrap step, and it only bites once. **The harness cannot gate anything
until `scripts/gate.sh` is on `main`.** Work branches are cut from `main`, the
gate is read out of the branch rather than seeded into the worktree, so a
branch cut from a `main` that does not carry it has no gate to run — every item
comes back `gate-missing`, and finalize acts only on items the gate passed.
A full pass in that state is not a failed run; it is a run that correctly
declines to open a PR for work it could not verify.

That is the state until the PR carrying this directory is merged. Confirm
before the first real pass:

```sh
git cat-file -e origin/main:scripts/gate.sh && echo "gate is on main"
```

Until that prints, `--dry-run`, `--plan` and `--prepare` are all meaningful and
`--implement` will produce commits, but no PR will be opened by anything.

## Modes

Five, each one step further than the last. **Only `--finalize` puts code on
origin or opens a PR**, and even it never merges into `main`.

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
writes anything: local branches, plus — when the batch involves an epic — a
bare `sandcastle/epic-<n>` created and linked on origin (see [Where the epic
branch comes from](#where-the-epic-branch-comes-from)). No code, no PR.
`--implement` is the first that starts a container and the first that writes
code, and it still puts none of it on origin. `--finalize` is the full pass —
it implies `--implement` — and the only one that merges children, opens PRs or
closes anything. Running with no mode is an error, so an operator can never
think a real pass has happened.

`--issue N` restricts the batch to the issues you name. A number that is not
in the planned batch is fatal rather than a warning: pointing the harness at
one issue and getting a silent no-op reads as "the run happened and did
nothing".

## Logs, and what a run leaves behind

The run narrates itself on stdout — the batch, the plan, the freshness verdict
per branch, then a line per item as it finishes. That summary is the thing to
read; it names every outcome and it is the only place the whole pass is visible
at once. Keep it:

```sh
mkdir -p .sandcastle/logs
node .sandcastle/run.mts --finalize 2>&1 | tee .sandcastle/logs/run.log
```

**Per-item agent transcripts land in `.sandcastle/logs/issue-<n>.log`** — one
file per issue, holding what the implementer did inside its container: every
tool call and its own reasoning. That is where you look when an item comes back
`no-commits`, or when you want to know *why* an agent did what it did. The
directory is gitignored and nothing prunes it; files accumulate and a re-run of
the same issue overwrites its own.

**The gate's own output is not in that file.** The agent's transcript ends when
the agent stops; the harness then runs the gate itself, in the same container.
That verdict goes to the run summary on stdout instead, and a red gate prints
the tail of its output there, one `|`-prefixed line at a time — a bare exit code
would leave you with a failed item and nowhere to look.

So for a `gate-failed` item the two halves live in two places: *what the agent
did* in the per-item log, and *why the gate rejected it* in the summary. Capture
stdout, per the `tee` above, or the second half is gone.

Also left behind, all of it gitignored: `.sandcastle/worktrees/` (removed after
each use — anything still there is the wreckage of a killed run), and
`.sandcastle/node_modules/`.

**Nothing else is left behind.** Not in the primary checkout, which every
writing mode re-checks at the end of the run, and not on `main`, which nothing
in the harness can write to.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | The pass completed. Read the summary — a green exit still allows `gate-failed` items, as long as *something* was implemented |
| `1` | The batch produced nothing usable, or an item failed at merge or finalize. The run happened; some part of it did not land |
| `2` | Bad invocation — no mode, an unknown flag, or `--issue N` for an issue not in the batch |
| `3` | **A plan was refused, or a child's epic branch was missing.** The run aborted whole rather than skipping the item. This is a bug in the harness or a corrupted issue graph, not an operator error |
| `4` | The primary checkout moved or its tree changed. An invariant broke — see [recovery](#when-a-run-half-fails) |
| `5` | Container auth is not configured. Nothing was touched; fill in `.sandcastle/.env` |

## What the dry run selects

Start from open issues labelled `ready-for-agent`, then prune:

- **open blockers** — GitHub's native issue dependencies
  (`GET /repos/{owner}/{repo}/issues/{n}/dependencies/blocked_by`), falling
  back to a `Blocked by: #<n>` body line where dependencies are unavailable.
  A candidate is eligible only when every blocker is closed. An unresolvable
  blocker counts as open.
- **already worked** — the *re-pick guard*: any PR on the issue's own head, in
  any state including closed. That head is `sandcastle/epic-<n>` for an issue
  with sub-issues and `sandcastle/issue-<n>` otherwise; reading the wrong one
  would leave the guard blind to every epic. This is load-bearing; it is what
  holds when the label swap fails, so do not quietly weaken it. A **child**
  never gets a PR, so the guard cannot hold for it — the merge phase closing
  the issue is what keeps it from being re-picked.

Parenthood is read while gathering and decides *where* the work lives, never
whether it happens: native sub-issue links are canonical, a `Sub-issue of #N` /
`Part of #N` body marker is a fallback, and when the two disagree native wins
and the conflict is logged.

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

A branch counts as a **known epic branch** when some gathered candidate has
sub-issues (its own branch) or has a parent (the parent's branch). The second
half matters: `ready-for-agent` is per-issue and a parent need not carry it —
#42 and #28 are both children of parents outside the batch — and the branch is
still knowable and creatable from the parent's number alone. What the guard
still refuses is a `mergeInto` no gathered issue justifies.

Because the plan is built by deterministic code rather than by an agent, that
check is a regression net: `planBatch` derives `mergeInto` from the same parent
link `knownEpicBranches` reads, so it cannot disagree with itself. The half
that *can* fire at runtime is later — once the epic branches have really been
created, a child pointing at one that is not there aborts the run.

Items are executed in **two waves**: everything that owns its own branch
(epics, standalones) first, children second. The split is not cosmetic — a
child's branch is cut *after* the first wave has committed, so it starts from
an epic branch that already carries the epic's own work. Cutting every branch
up front would put children on a stale base, which is the thing the freshness
rules exist to prevent.

The unknown-epic abort fires between the two: any child whose epic branch was
not actually created aborts the **entire run**, per invariant 4.

## Epics and sub-issues

`ready-for-agent` always means "implement this issue". Having children or a
parent only decides where that work lives:

| Shape | Branch | Route to `main` |
| --- | --- | --- |
| standalone | `sandcastle/issue-<n>`, cut from `main` | its own PR |
| **epic** (has sub-issues) | `sandcastle/epic-<n>`, cut from `main` | its own PR, carrying its children |
| **child** (has a parent) | `sandcastle/issue-<n>`, cut from the epic branch | squash-merged into the epic branch |

An epic's *own* implementation work lands on its epic branch. Parents are not
containers here — #29 is a parent that also describes real work, and treating
it as empty would drop that work on the floor.

The one shape left **deferred** is an issue that is both a parent and a child.
A nested epic has no unambiguous route to `main`, and the harness will not
guess one.

### Where the epic branch comes from

The name is deterministic — `sandcastle/epic-<N>`, from the issue number, never
a title slug, which cannot be recomputed after a retitle. Resolution, in order:

1. a branch of that name already **linked** to the issue — reused;
2. a branch of that name already **on origin** but unlinked (an earlier run
   that got that far) — adopted;
3. otherwise `createLinkedBranch`, which creates it on origin *and* links it to
   the issue in one call.

A linked branch under a **different** name is reported and set aside, not
adopted: `pushBranch()` would refuse anything outside `sandcastle/`, and the
plan validator would abort the run over it. That is a human's branch for the
same issue, and the harness says so rather than failing three layers down.

**This is the one thing `--prepare` does that reaches origin**, because
creating and linking is a single call and there is no way to get the link
otherwise. What lands there is a bare branch at `main`'s tip — no code, no PR,
nothing merged.

### The merge phase

Between implement and finalize, and only under `--finalize` — it puts commits
on origin and closes issues, and `--implement` promises neither.

Each green child is squash-merged into its epic branch in **its own worktree**
(the primary checkout is never checked out), the epic branch goes to origin
through the same chokepoint as everything else, and then the child issue is
**closed** with a comment naming the epic branch.

That closure is the one issue the harness ever closes, and it is consistent
with "issues close when their code reaches `main`, never earlier": a child has
no route to `main` other than its epic branch, so reaching that branch is as
far as a child's code ever gets on its own.

| Outcome | Meaning |
| --- | --- |
| `merged` | one squash commit on the epic branch, branch on origin, child closed |
| `nothing-to-merge` | the epic branch already carried it. Nothing committed, issue left open |
| `conflicted` | the merge was undone; the epic branch is byte-identical and the issue stays open |
| `error` | the epic branch could not reach origin, or the issue could not be closed. Run exits non-zero |

A child is **never finalized** into a PR of its own, whatever the gate said: a
PR targets the branch its head was cut from, and the epic's PR is what carries
all of it into `main`.

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

### The epic PR body — the one agent in finalize

An epic branch arrives at its PR carrying its own work plus a squash commit per
child, and nothing deterministic can say what that adds up to. So an agent is
asked, on the host, for the narrative — and only the narrative:

- **`Closes #<epic>` is prepended by the harness**, never written by the agent.
- **Closing keywords in the narrative are stripped**, reference kept. An agent
  writing "Closes #30" about a child would close, on merge, an issue the merge
  phase already closed.
- **A failed or empty agent call is not a failed PR.** The fallback is a
  deterministic body built from the same facts, and it says that is what it is.

The harness never closes the epic issue. Like a standalone, it stays open until
a human merges the PR that carries `Closes #<epic>`.

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

## When a run half-fails

A pass is not transactional and does not pretend to be. One item failing never
takes the batch down — it becomes an outcome, the rest carry on, and the run
exits non-zero at the end. So the normal shape of a bad run is a *partial* one,
and the recovery is nearly always the same: **read the summary, fix the one
thing, re-run.**

Re-running is safe by construction, and that is what makes this the answer
rather than a risk. Nothing in the harness force-pushes, nothing merges into
`main`, and an existing PR is found rather than duplicated — in any state,
closed included. An issue that reached a PR is therefore out of the next batch
whatever else went wrong: the re-pick guard is what holds, and it holds even
when the label swap is the thing that failed.

What to do, by what the summary says:

| Symptom | What actually happened | Do |
| --- | --- | --- |
| `gate-failed` | The agent committed, the harness ran the gate itself, the gate was red | The gate's output is in the run summary, not the per-item log; read it there for *why*, and the log for what the agent was doing. The branch is intact — fix it by hand, or re-run to give the agent another attempt on top |
| `gate-missing` | The work branch has no `scripts/gate.sh`, so nothing was gated | Setup's fault, not the agent's. Land the gate on the base branch, then re-run |
| `no-commits` | The agent wrote nothing at all | Usually an under-specified issue. Read the log; sharpen the issue before re-running |
| `error` on an item | The sandbox failed, or the update to origin was refused | The message carries git's own stderr. A rejected push means the branch moved on origin — inspect it before doing anything |
| PR opened, handoff failed | The PR is real and on origin; the `ready-for-agent` → `ready-for-human` swap or the marker comment did not go through | **Swap the label by hand** — re-running will not retry it, because the re-pick guard prunes any issue with a PR on its head. That guard is also why this is cosmetic rather than dangerous: a stale `ready-for-agent` cannot cause the issue to be worked twice |
| `conflicted` child | The squash into the epic branch would not apply; it was undone | The epic branch is byte-identical to before and the issue is still open. Resolve by hand in a worktree, or re-run once the epic branch has moved on |
| `skipped` at freshness | A rebase or merge conflicted, so the branch was left exactly as found | Not implemented, deliberately — its base is stale. Bring the branch forward by hand, then re-run |
| Nothing implemented at all (exit `1`) | The whole batch came back empty | Check auth and Docker before re-running: an image or token problem fails every item identically |

Two cases are **not** "fix and re-run", because in both the harness is telling
you it does not understand the repository any more:

- **Exit `3` — a refused plan, or an orphaned child.** Both abort the *entire*
  run rather than skipping the item, but they stop at different points and the
  message says which. A plan that puts work on the base branch, or outside
  `sandcastle/`, is refused **before anything is touched** — no branch, no
  container, and re-running changes nothing until the harness or the issue graph
  is fixed. A child pointing at an epic branch that does not exist is caught
  *later*, after epic branches have been created, so that run may already have
  left a bare `sandcastle/epic-<n>` on origin. That branch is harmless and will
  be adopted by the next pass; it is not something to clean up. Read the abort
  message either way — it names every violation at once.
- **Exit `4` — the primary checkout moved.** The one thing the harness promises
  never to do appears to have happened. Do not re-run. Establish what the tree
  looks like now (`git status`, `git reflog`) and get it back to where it was
  first; if the harness really did it, that is a bug worth an issue.

### Killed mid-run

Ctrl-C or a crash leaves work branches wherever they got to, and possibly a
container and a worktree. None of it is dangerous, but a leftover worktree holds
its branch checked out and blocks the next pass's freshness step.

Work branches are **local** until finalize pushes them. The one exception is a
bare `sandcastle/epic-<n>`, which [is created on origin during
`--prepare`](#where-the-epic-branch-comes-from) — so a run killed any time after
that may have left one there. Leave it: it carries no code, and the next pass
adopts it rather than creating a second one.

```sh
docker ps --filter ancestor=nextjs-revalidate-sandbox:latest   # then: docker rm -f <id>
git worktree list && git worktree prune
```

Then run the [pre-flight](#pre-flight) again before the next pass, paying
attention to the leftover-branch check: the branches from the killed run are
exactly the case it is there to catch.

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
