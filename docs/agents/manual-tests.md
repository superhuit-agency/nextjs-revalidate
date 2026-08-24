# Manual Tests

How to keep the **runbook** true — the checks only a person at a browser can
perform. The reasoning behind it is
[ADR 0012](../adr/0012-a-third-testing-idiom.md); this file is the rule you apply
while changing code.

## Two files, and no step in both

- **[`../manual-tests.md`](../manual-tests.md)** — the **core pass**. Thirty-five
  steps, single site, run before every release.
- **[`../manual-tests-extended.md`](../manual-tests-extended.md)** — the
  **extended pass**. Everything else, including the network and upgraded stacks.

They partition the manual tests; they do not summarise each other. **Never copy a
step from one into the other** — a duplicated step is one that will be fixed in
one place and left stale in the other, which is the failure this split exists to
prevent.

**A new step goes in the extended pass by default.** It belongs in the core pass
only if its failure would mean the plugin does not work at all for an ordinary
single site. If adding to the core pass takes it much past thirty-five steps, ask
whether something else there has stopped earning its place.

## The runbook is a template

**It is committed unchecked, always.** Ticks belong in a working copy and are
never committed. If you find a ticked box on `main`, that is a mistake to fix,
not a result to preserve.

## Update it when you change any of these

The list is closed on purpose. "Did I change user-facing behaviour?" is the
judgment call everyone resolves in their own favour, usually by doing nothing —
so match against these instead:

- **A setting** added, removed or renamed in `Settings::OPTIONS`.
- **An admin surface** added or changed: a notice, a screen, a settings field, an
  admin bar item, a row action, a bulk action.
- **An observable** changed: the wording of a log line, the text of a notice, a
  query arg, a sendback URL. The runbook's `Expect` clauses quote these, so a
  reworded log line silently invalidates every step that watches for it.
- **An integration hook** added or changed under `include/Integrations/`.
- **What a stack requires**: anything touching activation, setup, teardown,
  migration, or the wp-env configuration.
- **A bug fixed that no automated test can reach.** Add the step in the same
  change as the fix.

If your change matches none of these, the runbook needs nothing. Say so and move
on; do not add a step for tidiness.

**Search both files** when an observable changes. The `Expect` clauses quote log
lines and notice text in either one, and grepping only the core pass is how a
reworded string leaves the extended pass quietly wrong.

## Also delete steps

The runbook only stays runnable if it can shrink. Two rules do that work:

**Automated coverage retires a step — but only when it covers the same reach.**
Reach is the criterion for a step belonging and for it leaving, symmetrically.
A PHPUnit test that proves the queue receives the right permalink does *not*
retire the manual step that saves a redirect through Redirection's own UI: the
test never touches that screen. Delete the step only when the new test observes
what the step observes, and cite the test in the commit message.

**A step carrying an issue number dies when the issue closes.** Those steps are
the temporary exception ADR 0012 allows — automatable ground held by hand until
someone automates it. Closing the issue without removing the step leaves the
runbook longer than it needs to be, permanently.

## Writing a step

One action, one oracle:

```markdown
- [ ] **Save a published post.** Expect `✅ Revalidated` for its permalink in
      `wp-content/uploads/nextjs-revalidate.log` within one minute.
```

Bold the action. Name what you expect **and where it appears** — the revalidate
server console, the log file, the queue tab, the screen itself. A step without a
stated oracle is one two people will disagree about, and one you cannot tell has
been invalidated by your change.

Preconditions are not repeated per step. They are stated once, in the header of
the section that owns a run of steps.

## Do not add a step that could be a test

The pressure runs toward automation. A check that could be a standalone script
(`npm run test:php`) or a wp-env PHPUnit test belongs there — those run on every
commit, and the runbook runs when somebody remembers.
[ADR 0008](../adr/0008-two-testing-idioms.md) is the split between those two.

If you add a manual step for automatable ground anyway, it must carry the number
of the issue to automate it. No number is a bug in the runbook.
