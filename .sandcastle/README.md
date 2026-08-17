# `.sandcastle` — AFK agent harness

Picks up open issues labelled `ready-for-agent`, implements each one in an
isolated container, and opens a PR into `main` for a human to merge. It never
merges into `main` itself.

## Quick start

```sh
npm install
cp .sandcastle/.env.example .sandcastle/.env   # then fill in one key, below
npm run sandcastle
```

Needs Node 24 (`.nvmrc`), Docker running, and an authenticated `gh` CLI. Run
`nvm use` first: anything below Node 20.12 is refused up front, because
sandcastle's own dependencies need `util.styleText` and fail deep inside
`node_modules` without it. The first pass builds the sandbox image, which takes
a few minutes; later passes reuse it.

To see the batch it would work without touching anything:

```sh
npm run sandcastle -- --dry-run
```

That is the whole command surface. A pass is all-or-nothing by design — there is
no way to run half of one.

## The one thing to configure

Container auth to Claude, in `.sandcastle/.env` (gitignored; `.env.example` is
the tracked template). Fill in **one** key:

| Key | Bills |
| --- | --- |
| `CLAUDE_CODE_OAUTH_TOKEN` | your Claude subscription. `claude setup-token` produces it |
| `ANTHROPIC_API_KEY` | the API account |

sandcastle forwards only the keys *named in that file*, so leave both lines
present even when you fill in one — an empty value means "take it from the shell
running the harness". The harness checks this on the host and refuses the run
rather than failing inside a container.

Nothing secret is baked into the image; the value arrives as a `docker run -e`
when the container starts.

## What a pass does

Four phases, over every eligible issue:

1. **Plan.** Open issues labelled `ready-for-agent`, minus any with open
   blockers, minus any that already have a PR on their work branch. Each gets a
   branch: `sandcastle/issue-<n>`, or `sandcastle/epic-<n>` when the issue has
   sub-issues. A sub-issue is cut from its epic's branch instead of `main`.
2. **Prepare.** Every work branch is brought to a correct starting point — re-cut
   when it carries nothing, rebased when its commits are local-only, merged
   forward when it is already on origin.
3. **Implement.** One container per item, three at a time, on Opus 5. The agent
   commits to the work branch and nothing else — it has no `gh` and no GitHub
   credential. When it stops, **the harness runs the gate itself**, in the same
   container: `npm run typecheck`, `npm run lint:php`, `npm run test:php` and
   `npm run analyse:php` — the first three of which `.github/workflows/ci.yml`
   also runs on a pull request. That verdict decides the item, not the agent's
   claim. (`lint:php` parses with
   `$PHP_BIN`, which the image pins to PHP 7.4 — the version the plugin declares.
   On your own machine it refuses to run on any other version: point `PHP_BIN` at
   a 7.4 binary, or set `ALLOW_PHP_VERSION_MISMATCH=1` and know the lint proves
   nothing. `analyse:php` is PHPStan over the whole 7.4–8.4 span the plugin
   claims, and needs `composer install` first — the gate does that itself.)
4. **Finalize.** Each green sub-issue is squash-merged into its epic branch and
   its issue closed. Each green epic and standalone gets its branch pushed and a
   PR opened into `main`, its label swapped `ready-for-agent` →
   `ready-for-human`, and its issue left open for the merge to close.

Your own checkout is never touched — no phase runs `git checkout` in it, and the
run fails loudly if it moved. The design record for all of this, and the rules
that hold when nobody is watching, is
[ADR 0006](../docs/adr/0006-afk-harness-invariants.md).

## Reading a run

The run narrates itself on stdout: the batch, the plan, a freshness line per
branch, then an outcome per item. **That summary is the only place the whole pass
is visible at once, and the only place a failing gate says why** — keep it:

```sh
npm run sandcastle 2>&1 | tee .sandcastle/logs/run.log
```

Per-item agent transcripts land in `.sandcastle/logs/issue-<n>.log` — what the
implementer did inside its container. The gate's output is *not* there; the
transcript ends when the agent stops.

Four outcomes per item:

| Outcome | Meaning |
| --- | --- |
| `implemented` | commits on the branch, gate green — this is what gets a PR |
| `gate-failed` | commits on the branch, gate red. Branch kept, nothing pushed |
| `no-commits` | the agent wrote nothing. Usually an under-specified issue |
| `error` | the sandbox or a git/gh call failed; the batch carried on without it |

Exit codes: `0` the pass completed (read the summary — some items may still be
red), `1` nothing landed or an item failed at merge or finalize, `2` bad
invocation or a refused pre-flight, `3` a plan was refused, `4` the checkout
moved (an invariant broke — do not re-run, see below), `5` container auth is not
configured.

## When it breaks

One item failing never takes the batch down, so the normal shape of a bad run is
a partial one. **Read the summary, fix the one thing, re-run.** Re-running is
safe: nothing force-pushes, nothing merges, and an existing PR is found rather
than duplicated — so an issue that reached a PR is out of the next batch whatever
else went wrong.

| Symptom | Do |
| --- | --- |
| `gate-failed` | The gate's output is in the run summary; the agent's own log says what it was doing. Fix by hand, or re-run to give an agent another attempt on top |
| `no-commits` | Read the log, sharpen the issue, re-run |
| Everything failed identically | Check Docker is running and the token in `.sandcastle/.env` is still valid |
| PR opened but the label did not swap | Swap it by hand. Re-running will not retry it, and does not need to — the PR itself keeps the issue out of the next batch |
| `conflicted` sub-issue | The squash into the epic branch would not apply and was undone. Resolve by hand in a worktree, or re-run once the epic branch has moved on |
| `skipped` at freshness | A rebase or merge conflicted, so the branch was left as found and deliberately not implemented. Bring it forward by hand, then re-run |
| "the checkout is on `sandcastle/…`" | The pre-flight refusing to run. Switch back to your own branch |
| Exit `4` | The harness thinks it moved your checkout. Do not re-run: check `git status` and `git reflog`, get the tree back, and file an issue |

**A leftover `sandcastle/*` branch is the one thing to look at by hand.** An
abandoned work branch does not look abandoned to the harness — it looks like work
in progress, and gets built on and shipped in that issue's PR. If a
`sandcastle/*` branch is not work you mean to continue, delete it (locally and on
origin) before the run. A branch with an **open PR** is safe and should be left
alone; its issue is pruned from the batch.

Killed mid-run: work branches are local until finalize pushes them, so nothing
is at risk. A bare `sandcastle/epic-<n>` may have been created on origin — leave
it, the next pass adopts it. Leftover containers and worktrees are cleared for
you at the start of the next pass:

```sh
docker ps --filter ancestor=nextjs-revalidate-sandbox:latest   # then: docker rm -f <id>
```

## Rebuilding the image

Built automatically when it is missing. Rebuild by hand after changing
`.sandcastle/Dockerfile` — or after bumping `.nvmrc`, which the Dockerfile pins
in step by hand:

```sh
npx sandcastle docker build-image --image-name nextjs-revalidate-sandbox:latest
```

The image carries Node, PHP 7.4 (from [Sury](https://packages.sury.org/php/);
`PHP_BIN` is pinned to the versioned binary so the lint cannot be redirected to a
newer parser), Composer with `unzip` for the analysis step's dev dependencies,
and the Claude Code CLI. It deliberately carries no `gh`. It is
built for *your* UID/GID and is not shareable with another operator — rebuild
instead. Nothing is copied into it, so ordinary code changes need no rebuild.

## Tests

```sh
npm run sandcastle:test
npm run sandcastle:typecheck   # tsx strips types; it does not check them
```

They build real scratch repositories with a real `origin` in `$TMPDIR` — the
freshness rules are entirely about what git does to real refs, and a mocked git
would prove nothing. The implement phase takes its sandbox from an injected
dependency, so its tests need no container, no network and no API key. Neither
this repo nor the network is touched.

Untracked here: `logs/`, `worktrees/` and `.env`. Everything else is tracked,
`.env.example` included.
