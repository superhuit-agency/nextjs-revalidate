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
