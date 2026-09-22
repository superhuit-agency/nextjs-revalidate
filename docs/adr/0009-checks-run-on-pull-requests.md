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

Saying so is not enough to keep it true, so `.sandcastle/test/implement.test.mts`
reads both `GATE_COMMAND` and this workflow and fails if either runs a script the
other does not. Adding a check to one and forgetting the other is the drift this
ADR is against, and it is now a red test rather than a discovery.

The corollary matters as much: **a green CI run means exactly what a green local
run means, and no more.** It is syntax, types, PHP-range compatibility and the
handful of behaviours the standalone scripts pin. It is not a claim that the
change works.

## Two jobs, and PHP 7.4 with no escape hatch

**Typecheck** takes Node (24 — `.nvmrc` and the release workflow; `engines.node`
is a floor, not the version the project develops on) and needs no PHP.
**PHP 7.4** takes `shivammathur/setup-php` and needs no `node_modules` — the
three PHP checks are PHP, shell and Composer only, and `npm` is merely how this
repo names them. Splitting them gives two independent status checks and runs them
concurrently; one job would install both toolchains to serialise the same work.

The PHP job is on **7.4**, the version the plugin header declares, and
`ALLOW_PHP_VERSION_MISMATCH` is set nowhere in the file. `lint:php` refuses to
run on any other version, and that refusal is the point — a newer parser accepts
`readonly`, enums, `?->` and constructor promotion, so a lint on 8.x would report
success for a release claiming 7.4 support. A CI job that quieted the refusal to
"get PHP linting working" would be reporting a check it did not perform.

Step order inside the PHP job is the gate's order, for the gate's reason: the
parse and the standalone tests need nothing installed and take seconds, so a PHP
8-only construct fails before the job pays for a Composer install
([ADR 0016](0016-php-compatibility-gate.md)), which only PHPStan needs.

This retires the "nothing runs on pull requests" clause in ADR 0016 and in
`phpstan.neon`. All four checks now run on every PR.

## PHPStan was red on `main` before this workflow existed, and that is the argument for it

#75 scoped PHPStan out as #34's work and said #34 "should land its script into
the same workflow". #34 landed first, before there was a workflow to land into,
so `npm run analyse:php` is wired up here — which meant first finding out that
**it had been red on `main` since the day it landed**.

`phpstan-baseline.neon` was generated on #69's branch, which was cut before #70
(`fb71c83`) and merged after it. The baseline therefore described a tree that
`main` never had: 14 findings in `include/Assets.php`, `Revalidate.php`,
`RevalidateAll.php` and `RevalidateQueue.php` that it did not cover — six
patterns absent outright, three whose `count` had drifted, and one
(`curl_setopt`) that matched nothing *on the PHP the regeneration happened to run
on*, which PHPStan reports as an error in its own right. `include/` has not
changed since #69 merged, so this
was never a regression anyone introduced afterwards; it was wrong on arrival, and
nothing ran to say so. A check nobody runs is a check that is already broken.

**The baseline was regenerated here**, and that is the one thing in this change
that is not just plumbing. It went from 28 entries / 39 errors to 33 / 47. The
rule for that file is that it is a to-do list and only ever shrinks, and this is
the narrow exception that rule assumes away: the list did not stop matching
because someone fixed something, it stopped matching because it was generated
against the wrong tree. Regenerating is what #69 would have committed had it been
rebased. Exactly one PHP file was touched to get there, and only to make a
declared type honest (below) — the alternative was to leave the analysis unwired
and permanently red, which is how it got into this state.

## Never regenerate the baseline where an extension is missing

The regeneration here was run inside the harness sandbox, and the workflow this
ADR adds then failed on its very first run with the single error that
regeneration had just dropped:

```
include/Assets.php:110
Parameter #3 $value of function curl_setopt expects 0|2, false given.
```

**The sandbox image has no ext-curl.** PHPStan cannot produce a finding about
`curl_setopt` without the extension loaded, so regenerating there silently
deleted an entry that every environment which *does* have curl — CI included —
still reports. This is not a PHP-version effect: the finding reproduces
identically on 7.4.33 and on 8.3.32 with curl present, so `phpVersion`'s
`{min, max}` range was never going to cover it.

A baseline is only as complete as the extensions loaded when it was generated.
Regenerate it where the full set is present, and confirm the result is green on
both ends of the declared range before committing it:

```sh
composer install
php vendor/bin/phpstan analyse --no-progress \
	--memory-limit=-1 --generate-baseline phpstan-baseline.neon
PHP_BIN=/path/to/php7.4 npm run analyse:php   # the floor CI runs
```

(`--memory-limit=-1` is #82's OOM at PHP's default 128M, which a 7.4 run hits
locally where the CI runner's ini does not.)

That the sandbox is missing ext-curl is one symptom of a larger problem the
implementers of #38, #49, #51 and #73 all hit independently: the image predates
`.sandcastle/Dockerfile`, which installs curl, unzip and Composer, and the
harness only rebuilds when the image is *missing*. `composer install` cannot
succeed there at all — `phpunit` has required ext-dom since #56 and the image
has no `dom.so` — so the gate's `&&` chain dies before `analyse:php` on any
commit, `main` included. **Rebuilding that image is a prerequisite for the gate
being meaningful, and it is not in this change.**

The fix here is in the call, not the baseline: `CURLOPT_SSL_VERIFYHOST` is
documented as `0` or `2` and was being passed `false`. `false` coerces to `0`,
so the behaviour has always been correct and identical on every version —
nothing was broken and nothing is fixed. Passing the declared type is what lets
the entry leave the baseline honestly instead of being regenerated back into it.

The growth is six new patterns carrying seven errors, three counts that moved and
one entry (`curl_setopt`) that went away. Those seven errors are not seven
problems; they are five, and the two worth fixing are both a docblock that lies
about its own code:

- `Logger::log()` is annotated `@param string $level`, but the levels are the
  `int` constants `INFO`/`DEBUG`/`ERROR`. That one wrong word produces the two
  new `argument.type` findings in `RevalidateAll.php` and `RevalidateQueue.php`
  *and* the `parameter.defaultValue` entry the baseline already carried.
- `RevalidateAll::revalidate_all()` is annotated `@return int` but returns
  `false` when the site is unconfigured. Hence `Strict comparison using ===
  between false and int will always evaluate to false` at the refusal branch #70
  added — where the *code* is right and the annotation is not — alongside the
  `should return int but returns false` entry already in the baseline.

The other three are the plugin's existing magic-property idiom reaching one new
call site each (`NextJsRevalidate::init()->revalidate` from `Assets.php`,
`$this->settings` in `RevalidateQueue` via `Abstracts\Base::__get()`) and one
defensive `method_exists()` the WordPress stubs say can never be false. Fixing
any of them is code in `include/`, which #75 puts out of scope; they are named
here so the follow-up does not have to rediscover them.

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

**The baseline is five entries longer than it should be.** Regenerating it was
the only way to wire the analysis up at all, but the five causes above are
findings a green run now hides. They are somebody's next ticket, and the rule
goes back to what it was the moment this lands: the file only shrinks. A future
regeneration that grows it is a bug in the change asking for it.

**Job names are now an interface.** Renaming a job breaks the branch protection
rule that names it, silently — the rule keeps waiting for a check that no longer
reports. Rename one and update the setting in the same breath.

**A fork's PR runs with `contents: read` and no secrets.** Nothing here
comments, labels or publishes, so nothing needs more, and the release workflow's
deploy credentials stay out of reach of a pull request.

**The release zip is untouched.** `release-plugin.yml` assembles its payload from
an rsync allowlist, which never included `.github/` or `tests/`.
