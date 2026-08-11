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

## Dry run

```sh
node .sandcastle/run.mts --dry-run
node .sandcastle/run.mts --dry-run --json
```

Prints the batch it would work and exits. No branches, no pushes, no
containers. Running without `--dry-run` is currently an error — the execute
path lands with the plan phase (#42).

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

## Typecheck

```sh
npm --prefix .sandcastle run typecheck
```

## Untracked paths

`logs/`, `worktrees/`, `node_modules/` and `.env` — see `.sandcastle/.gitignore`.
Everything else here is tracked.
