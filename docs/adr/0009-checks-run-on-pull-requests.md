# The checks run on pull requests, and CI defines nothing the npm scripts do not

Decided while implementing #75.

Until now `.github/workflows/release-plugin.yml` was the only workflow in the
repo and it triggers on a `v*.*.*` tag, so **nothing ran on a pull request**.
Every check the repo owns — `npm run typecheck`, `npm run lint:php`,
`npm run test:php`, `npm run analyse:php` — was run by hand, or not at all. The
one place any of them were automated was `GATE_COMMAND` in
`.sandcastle/lib/config.mts`, which runs inside the AFK sandbox before a PR
exists: that covers exactly one author, and it covered no Dependabot PR (#62), no
human PR and no external contribution.

`.github/workflows/ci.yml` triggers on `pull_request` against `main` and on
`push` to `main`.

## CI is a trigger, not a second definition of the gate

Every step in the workflow is an existing npm script, run as written. Nothing is
inlined, no check is invented there, and no `phpstan analyse` or `php -l`
invocation is spelled out in YAML. The reason is the one in
[ADR 0006](0006-afk-harness-invariants.md): there is one definition of
"shippable", it lives in `package.json`, and a developer, the harness and CI all
read the same one. A workflow that spelled its own checks out would be a fourth
opinion, drifting quietly, discoverable only when CI and a local run disagree.

The corollary matters as much: **a green CI run means exactly what a green local
run means, and no more.** It is syntax, types, PHP-range compatibility and the
handful of behaviours the standalone scripts pin. It is not a claim that the
change works.

## Two jobs, and PHP 7.4 with no escape hatch

**Typecheck** takes Node (24 — `.nvmrc` and the release workflow; `engines.node`
is a floor, not the version the project develops on) and needs no PHP.
**PHP 7.4** takes `shivammathur/setup-php` and needs no `node_modules` — the two
PHP checks are shell and PHP only, and `npm` is merely how this repo names them.
Splitting them gives two independent status checks and runs them concurrently;
one job would install both toolchains to serialise the same work.

The PHP job is on **7.4**, the version the plugin header declares, and
`ALLOW_PHP_VERSION_MISMATCH` is set nowhere in the file. `lint:php` refuses to
run on any other version, and that refusal is the point — a newer parser accepts
`readonly`, enums, `?->` and constructor promotion, so a lint on 8.x would report
success for a release claiming 7.4 support. A CI job that quieted the refusal to
"get PHP linting working" would be reporting a check it did not perform.

Step order inside the PHP job is the gate's order, for the gate's reason: the
parse and the standalone tests need nothing installed and take seconds, so a PHP
8-only construct fails before anything pays for a Composer install
([ADR 0007](0007-php-compatibility-gate.md)) — which is also where the PHPStan
step goes when it can be switched on, below.

This retires the "nothing runs on pull requests" clause in ADR 0007 and in
`phpstan.neon` for the parse, which now runs on every PR. PHPStan is the
exception, and the next section is why.

## PHPStan is not wired up yet, and the reason is the reason this workflow exists

#75 scoped PHPStan out as #34's work, and said #34 "should land its script into
the same workflow". #34 landed first, before there was a workflow to land into,
so the intent was to wire `npm run analyse:php` up here — a `composer install`
after the two cheap checks, then the analysis.

It is not wired up, because **`npm run analyse:php` is red on `main` as it
stands**: 13 findings that `phpstan-baseline.neon` does not cover, in
`include/Assets.php`, `Revalidate.php`, `RevalidateAll.php` and
`RevalidateQueue.php` — some unbaselined outright, some baseline entries whose
`count` no longer matches, one ignore pattern that no longer matches at all.
Running the committed baseline against each code state on `main` puts the break
at #70 (`fb71c83`): 6 findings before it, 13 from it onward. #69's branch, which
generated the baseline, was cut before #70 and was never re-generated against the
`main` it merged into. The same 13 are reported on PHP 7.4 and on 8.3, so this is
the analysis, not a runtime.

Adding the step would therefore have made this workflow fail on its first run,
for something no pull request did — and #75 asks, in as many words, that the
workflow pass on `main` as it stands. Regenerating the baseline is not this
ticket's to do either: the baseline is a to-do list, "add nothing to it" is the
rule an implementer works under, and 7 of those findings are #70's code rather
than an ignore pattern that went stale.

So the step is missing, the workflow file says where it goes, and the finding is
this change's most useful by-product: a check that nobody runs is a check that
quietly goes red, which is the argument for the whole file.

## What is not here

**The wp-env integration suite** (#56, `npm run test:integration`). It needs
Docker and a MySQL container, and it is minutes rather than seconds. That is its
own piece of work, and #56 deferred CI wiring to a follow-up on purpose. The
standalone scripts are in because they need nothing —
[ADR 0008](0008-two-testing-idioms.md) is the split, and CI turns out to reward it
the same way the sandbox does.

**Building assets.** `npm run build` belongs to the release workflow.

**Path filters.** A check that skips itself on some diffs cannot be a required
status check: GitHub waits for a run that never starts, and the PR sits pending
forever. Every job here is minutes at most, so all of them run on every PR.

**Retiring `GATE_COMMAND`.** The harness gates inside its sandbox, before a PR
exists, and a red gate there means no PR is opened at all. That is upstream of
CI, not a duplicate of it. `npm run test:php` was added to it in the same change,
for the same reason it is in the workflow: the scripts were being run by nobody.

## Consequences

**Requiring a green run to merge is a repo setting.** Branch protection on
`main`, with **Typecheck** and **PHP 7.4** as required status checks, has to be
switched on by hand in the repository settings; a workflow file cannot ask for
it. Until someone does, CI reports and a red run can still be merged over.

**Job names are now an interface.** Renaming a job breaks the branch protection
rule that names it, silently — the rule keeps waiting for a check that no longer
reports. Rename one and update the setting in the same breath.

**A fork's PR runs with `contents: read` and no secrets.** Nothing here
comments, labels or publishes, so nothing needs more, and the release workflow's
deploy credentials stay out of reach of a pull request.

**The release zip is untouched.** `release-plugin.yml` assembles its payload from
an rsync allowlist, which never included `.github/` or `tests/`.
