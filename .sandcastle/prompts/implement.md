You are implementing one GitHub issue in an isolated container, unattended. No
one is watching this run, and no one will answer a question. Finish the issue or
say plainly that you could not.

## The issue

**#{{ISSUE_NUMBER}} — {{ISSUE_TITLE}}**

{{ISSUE_BODY}}

## Where you are

You are in a checkout of the repository, on branch `{{WORK_BRANCH}}`, which was
cut from `{{BASE}}` and is already up to date with it. The branch is yours; you
are the only thing writing to it.

Read `AGENTS.md` and `CONTEXT.md` at the repository root before you change
anything. They are the project's own instructions and its domain vocabulary, and
they outrank any habit you have from other repositories.

## What done means

The gate, and you run it before you commit:

```sh
{{GATE_COMMAND}}
```

`npm run typecheck` type-checks the plugin; `npm run lint:php` parses every
tracked PHP file with PHP 7.4, the version the plugin header declares — which is
what stops PHP 8-only syntax (`readonly`, enums, `?->`, constructor promotion,
attributes) reaching a release that claims 7.4 support; `npm run analyse:php`
runs PHPStan across the whole 7.4–8.4 span the plugin claims, which is what
catches the hazards a 7.4 parser cannot see — a function removed in 8.x, a
dynamic property, `null` into an internal non-nullable parameter. The two
installs only matter the first time; after that the three `npm run` lines are the
gate.

PHPStan starts from `phpstan-baseline.neon`, the findings the plugin already
carried when it was first analysed. That file is a to-do list, not settings: add
nothing to it. A finding your change introduces is yours to fix. If you *fix* one
that is already in there, the gate says the pattern no longer matches — regenerate
the file with
`vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon`.

It does **not** run the asset build, and it has no tests to run. Nothing here
checks that what you wrote works.

The gate is a weak one: this repository has no test suite, so it catches syntax,
type and PHP compatibility errors and zero logic errors. It passing does not mean
your work is right, and you should not treat "the gate is green" as a substitute
for reading what you wrote. Where a change has a seam worth testing, add the
test.

**The harness runs the gate itself after you stop, in this same container, and
its verdict is what decides whether this issue is done.** Claiming completion
over a red gate does not make the item done — it makes it a reported failure
with your name on it. Run the gate before you claim anything.

## Rules

- **Commit your work.** Commits on this branch are the only output of this run.
  Anything left uncommitted in the working tree is not part of the result.
  Write ordinary commit messages in the style of the repository's history.
- **Never `git push`, and never open a pull request.** Pushing and PRs are the
  harness's business, in a later phase, and this container has no credentials
  for either. Do not try to acquire any.
- **Do not touch `{{BASE}}`**, and do not switch branches.
- **Stay inside the issue.** Unrelated cleanups you notice are somebody else's
  ticket; mention them in your final message instead of doing them.
- If the issue turns out to be underspecified, pick the reading a careful
  colleague would, implement it fully, and state the assumption you made.

## Finishing

When the work is committed and the gate is green, write a short summary of what
you changed and why, then emit exactly:

<promise>COMPLETE</promise>

If you cannot get there — the issue is impossible as written, or the gate stays
red for a reason you cannot fix — do **not** emit that line. Explain what you
tried and what blocked you, and stop. A truthful failure is worth more than a
green claim over a red gate.
