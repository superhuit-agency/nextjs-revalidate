# The analysis pins its own memory limit

Decided while implementing #96.

`npm run analyse:php` ran `phpstan analyse --no-progress` with no
`--memory-limit`, so PHPStan inherited whatever the developer's `php.ini` said.
On PHP's stock 128M it does not report findings — it dies:

```
Child process error (exit code 255): PHP Fatal error: Allowed memory size of
134217728 bytes exhausted … in phar://…/nikic/php-parser/lib/PhpParser/Lexer.php
… while running parallel worker

 [ERROR] Found 1 error
```

That is a fatal in a parallel worker, not a verdict about the code. Someone who
hits it while checking their own change has every reason to read it as damage
they did, and what it says about the memory limit — when the worker's output
surfaces at all; in the harness sandbox it did not, leaving only
`Child process error (exit code 255)` — is buried in a stack trace from inside a
PHAR. A gate that `phpstan.neon` explains at this length
([ADR 0016](0016-php-compatibility-gate.md)) cannot also be one that fatals on a
default PHP install.

## What it actually costs, and why nobody saw it

`szepeviktor/phpstan-wordpress` brings `php-stubs/wordpress-stubs` with it: one
file, 5.3 MB and 150k lines. Holding its syntax tree is the peak, and it is a
*per-worker* peak — `memory_limit` is per process. Measured on PHP 7.4.33
against `wordpress-stubs` v6.9.4, cold cache, this tree:

| `memory_limit` | Result |
| --- | --- |
| 128M, 160M | fatal in a worker; the report is an exit code, not a diagnosis |
| 192M – 832M | `PHPStan process crashed because it reached configured PHP memory limit` |
| 896M and up | `[OK] No errors` |

Below ~192M PHPStan does not get its own diagnosis out — reporting the crash
costs memory too — which is why the default is the one limit that produces the
least legible failure of any.

It went unnoticed because of the result cache. Once a run completes, later runs
read `/tmp/phpstan` instead of re-parsing and pass on 128M happily, so a
developer who got one successful run in never sees it again and has no reason to
believe the report. `vendor/bin/phpstan clear-result-cache` in front of the run
is what makes it deterministic; anyone reproducing this must do that first.

CI never went red on it: `shivammathur/setup-php` writes `memory_limit=-1` into
the ini it installs. That could not be confirmed from inside the implementation
sandbox, which has no network — but it is consistent with what is on the record
here, a local 7.4 run hitting the limit where the runner's ini does not
([ADR 0009](0009-checks-run-on-pull-requests.md), noting the same exhaustion
against the baseline-regeneration command as #82). Either way the pin settles
it, because the gate now names its own ceiling instead of asking the host: the
number is the same on a runner, in the harness sandbox and on a laptop, which is
the agreement [ADR 0009](0009-checks-run-on-pull-requests.md) exists to hold.

## 2G

`--memory-limit=2G`, in the script, next to the existing `vendor/bin/phpstan`
guard.

The floor measured above is 896M, and 1G clears it by 15%. That is not enough to
call this closed: `wordpress-stubs` tracks WordPress and grows with every
release, so a number chosen to just clear today's parse is a number that brings
this issue back on a routine `composer update` — with the same illegible fatal,
for the same reason. 2G is a little over twice the need.

It is still a ceiling, which is the other half of the requirement. An analysis
that genuinely runs away stops, and when it stops at a number this far above the
real peak, PHPStan has the headroom to say `reached configured PHP memory limit:
2G` rather than dying mid-parse. Pinning high buys the good error message as
well as the passing run.

## Considered Options

**Capping parallelism** (`parameters.parallel.maximumNumberOfProcesses`). The
attraction is that exhaustion is per-worker, so fewer workers should mean more
headroom each. It does not: `memory_limit` is per process, not a budget split
across them, and the peak is one worker holding one file's syntax tree. Measured
with `maximumNumberOfProcesses: 1`, the threshold does not move — 768M red, 896M
green, the same wall it was — for more wall-clock. It also does nothing for a
developer whose limit is lower still.

**A guard that detects the low limit and refuses**, the way `lint:php` refuses
on the wrong PHP version and `analyse:php` already refuses without
`vendor/bin/phpstan`. Those guards exist for conditions the script cannot fix
and which would otherwise produce a *misleading verdict*: an 8.x parser reports
a pass it did not perform, and a missing analyser is no analysis at all. A low
`memory_limit` is neither. PHPStan takes the limit per run, so the script can
simply set it — and a guard would send a developer to edit `php.ini` in order to
reach exactly the run the flag already gets them. Refusing is the idiom for what
cannot be fixed here, not for what can.

**`--memory-limit=-1`.** What ADR 0009's baseline-regeneration snippet uses, and
right there: a deliberate one-off, run by hand, where the point is to finish. As
the standing gate it gives up the ceiling for nothing — the analysis needs 896M,
not an open tab.

## Consequences

**The pin lowers CI as well as raising a laptop.** A runner whose ini says `-1`
now analyses under the same 2G everyone else does. That is the intent: one
number, everywhere, or the two disagree again the next time one of them changes.

**Overriding it needs no edit.** `npm run analyse:php -- --memory-limit=512M`
works — the last occurrence of the option wins — which is the escape hatch for
anyone bisecting a runaway or pinning down a new peak.

**`tests/analyse-memory-limit-test.php` holds the flag.** It reads
`package.json` and fails if `analyse:php` drops `--memory-limit`, sets it to
`-1`, or sets it under 1G. A standalone script per
[ADR 0008](0008-two-testing-idioms.md), so it runs in the gate. The reason it is
worth a test at all is the result cache: dropping the flag is invisible to
whoever does it and fatals for whoever next analyses a cold tree.

**Nothing else in the gate takes a memory limit.** `npm run lint:php` parses one
file at a time and `npm run test:php` boots no framework; both are comfortable
inside 128M. This is PHPStan's problem alone, and the flag stays where the
problem is.
