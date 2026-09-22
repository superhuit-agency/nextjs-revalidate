# A third testing idiom, for what only a browser can prove

Decided while building the manual test runbook, after the run of work between
v1.6.9 and the next release.

[ADR 0008](0008-two-testing-idioms.md) opens by saying this repo "will have two
ways to run tests, and that is a decision rather than an accident". That has been
one short of the truth for as long as anyone has clicked through wp-env before
tagging a release. The third way existed; it was simply unwritten, which meant it
was unrepeatable, unreviewable, and different every time.

`docs/manual-tests.md` and `docs/manual-tests-extended.md` are that third idiom,
written down. This ADR is the rule for what belongs in them, on the model of
ADR 0008's rule for the other two.

## The rule is reach, not subject

ADR 0008 splits its two idioms by whether the unit under test needs real
WordPress state. This one is split by something else, and the difference is the
whole point:

**If the answer can only be had from a browser, a console or a file on a running
site, it is a manual test.** An admin notice rendering on the screen it is meant
for. A row action appearing for a user who should see it, and not for one who
should not. A redirect saved through Redirection's own UI. A site upgraded from a
release that predates the migration ledger. Translated strings actually
appearing.

Everything else stays where ADR 0008 puts it, and the pressure runs that way on
purpose: a check that *could* be a standalone script or a PHPUnit test belongs
there, because those run on every commit and this document runs when somebody
remembers.

The criterion that trips people up is that **a manual test walking the same
ground as an automated test is not a duplicate.** `RedirectRevalidationTest`
proves the queue receives the source path when the integration's callback fires.
It does not prove that saving a redirect in Redirection's own screens reaches
that callback at all — nothing in this repository proves that, and nothing can
without a browser. The automated tests pin units and seams. The runbook pins that
the assembled thing is wired together. Same subject, different reach.

## Exceptions are allowed, and carry an issue number

A step may cover automatable ground **temporarily**, and must then carry the
number of the issue to automate it. Something breaks, a check is wanted today,
and the honest automated test is a day's work — pretending otherwise just means
the check never gets written.

A step with no issue number that could be a standalone script is a bug in the
runbook, not a decision. And the exception is time-limited by construction: when
the issue closes, the step goes. That, with the removal rule in
`docs/agents/manual-tests.md`, is what keeps the exception from quietly becoming
the norm — which is the failure mode every document of this kind dies of.

## Two documents, partitioned rather than layered

The first draft was one file of 178 steps with a short "smoke pass" at the top
that linked into it. That design was chosen to stop a summary from drifting out
of step with what it summarised, and it failed for a simpler reason: a 178-step
document does not get run before a release, so its correctness is moot.

The manual tests are therefore **partitioned across two files, not layered**:

- **`docs/manual-tests.md`** — the **core pass**. Thirty-five steps, single site.
  Run before every release, and after any change worth ten minutes.
- **`docs/manual-tests-extended.md`** — the **extended pass**. Everything else,
  including both other stacks. Run against the ground it covers when that ground
  has been touched, and in full before a structural release.

**No step appears in both files**, and that is the whole of the design. A subset
document — the same steps repeated in a shorter list — would need every reworded
log line fixed twice, and the two copies would disagree within a release or two.
A partition cannot drift, because there is nothing to drift *from*: each step has
exactly one home, and the question an author faces is "which file", never "both?".

The cost is real and is the one a layered design would have avoided: **the core
pass is not a summary, so running it is not a weaker version of running
everything.** It leaves whole features untested — the admin bar, revalidate all,
scheduled purges, the probe, uninstallation — and a green core pass says
nothing about them. The split is a decision about what is worth a person's time
before a release, and it can be wrong in either direction. Moving a step between
files is cheap and expected; that is how the line gets corrected.

What decided which side a step falls on: the core pass keeps the checks whose
failure would make the plugin *not work at all* for an ordinary site — content
saves and revalidates, the queue drains, and the two states where the plugin is
supposed to do nothing (refusal and degradation) behave. Everything else earns
its place by being touched.

## The committed file is always unchecked

The runbook is a template, never a record. The boxes are ticked in a working copy
and the ticks are never committed.

The alternative — tick as you go, commit the result, get a history of which
release was tested — was considered and declined for a reason specific to who
edits this file. An agent's obligation is to keep the *steps* correct. If the
file also carried run state, every edit would have to decide what to do with
existing ticks, and the honest answer (a step whose behaviour changed must be
re-tested, so untick it) is a rule nobody applies reliably. A template has no
such ambiguity. It also keeps a pass from dirtying the working tree, which
matters because the natural thing to do mid-pass is commit the fix you just
found.

Stated here because it is not self-evident from the file: the first reader to see
a ticked box will otherwise normalise it silently, and the first agent to see one
will assume the file records passes.

## Three stacks, each with its setup and its teardown

The runbook covers three shapes of install, in this order, so a full pass
switches stacks twice and never goes back:

**Single site.** The existing `npm start` environment, seeded by
`config/afterstart.sh`.

**Network.** `config/wp-env.multisite.json`, copied to `.wp-env.override.json` —
an ignored file wp-env is designed to read — rather than an edit to the committed
`.wp-env.json`. Teardown is `rm`. The point is that a pass never leaves a config
change behind, and never runs with a dirty tree; the multisite configuration is
then a reviewable committed artifact rather than a paragraph of instructions
followed by hand.

**Upgraded.** Not a standing environment but a procedure: install the released
1.6.9 zip, configure it, produce content, then swap in the working tree. This is
the stack that justifies the other two existing, because it is the only way to
exercise the migration ledger's backfill at all. A fresh install can never reach
that code — it is stamped with the current DB version at setup, which is exactly
what the backfill exists to avoid needing. `MigrationLedgerTest` stubs its way to
a verdict about that logic; nothing anywhere proves a real 1.6.9 site survives the
upgrade with its settings intact.

## What a green automated run means, now that this exists

Unchanged, and worth saying because adding an idiom invites the opposite reading.

[ADR 0009](0009-checks-run-on-pull-requests.md) records that a green CI run is
"syntax, types, PHP-range compatibility and the handful of behaviours the
standalone scripts pin. It is not a claim that the change works." That sentence
covered the same ground before this document existed and covers it now. The
automated suites did not get weaker by being joined; a runbook that goes unrun
subtracts nothing from them.

The claim that *is* new, and narrower than it looks: a completed release pass
means a person observed each named behaviour once, on the stacks named, on one
build. It is not coverage, it is not a regression suite, and it expires the
moment anything is merged.

## Considered Options

**Fold the manual checks into the wp-env PHPUnit suite** and have two idioms
again. This is the tidy answer and it cannot work: the things worth checking by
hand are the ones with no programmatic oracle. A PHPUnit test can assert an
option was written; it cannot assert a notice was legible on the screen a person
was looking at, or that Redirection's save button reaches our callback.

**Leave it undocumented and rely on a careful release.** The status quo, and the
reason this ADR exists. It produced a different pass every time, with no way to
tell afterwards what had been checked.

**Enumerate every user-facing behaviour, whether automated or not**, so the
runbook doubles as a human-legible spec. Declined. It grows without bound, most
steps re-prove what CI already proved, and the length is what makes people skip
the pass entirely — which costs the steps that only a person could have run.

**Enforce the obligation mechanically**, with a standalone script asserting that
every setting in `Settings::OPTIONS` is named somewhere in the runbook. Genuinely
tempting given ADR 0009's precedent of a test that stops two files from drifting,
and declined for now as more machinery than the problem has yet earned. It is
also weaker than it looks: it would catch a setting nobody *named*, not a step
that has quietly stopped being true. If the advisory rule turns out not to hold,
this is the first thing to reach for.

## Consequences

**The runbook is only as good as the last person who ran it.** Nothing enforces
that the steps are still true, and a stale step is worse than a missing one
because it manufactures confidence. The triggers and the removal rule in
`docs/agents/manual-tests.md` are the whole of the defence.

**A new step now has to be filed, not just written.** Two documents means an
author picks one, and picking wrong is how the core pass grows back toward being
unrunnable. The default is the extended pass; the core pass takes a step only
when its failure would mean the plugin does not work at all.

**A third idiom is a third decision for every new test.** ADR 0008 already
charges contributors with choosing between two; this makes it three. Reach is the
question to ask first, because it is the only one that can send a check here.

**`.wp-env.override.json` is now load-bearing and ignored by git.** A stale one
left behind silently changes what every subsequent `wp-env start` does, including
the single-site pass. The network stack's teardown step exists for that reason
and is not optional.

**Keeping a 1.6.9 zip to hand is now part of being able to test a release.** The
upgraded stack cannot be constructed without a build of the previous release, and
that is a dependency on GitHub's release assets rather than on anything in this
repository.
