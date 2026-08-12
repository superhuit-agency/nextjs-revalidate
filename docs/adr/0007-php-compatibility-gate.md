# The PHP compatibility gate is PHPStan over a 7.4–8.4 range, started from a baseline

The plugin header declares `Requires PHP: 7.4` and the hosts it runs on are on
8.3 and 8.4. `npm run lint:php` (#33) parses every tracked file with PHP 7.4,
which holds the *floor*: `readonly`, enums, `?->`, constructor promotion and
attributes cannot reach a release that claims 7.4 support, because they are parse
errors on a 7.4 parser. Nothing held the ceiling.

That end of the range is where the hazards stop being syntax:

| Hazard | `php -l` on 7.4 |
| --- | --- |
| PHP 8-only syntax | parse error — caught |
| Functions removed in 8.x (`create_function()`, `each()`) | clean, fatal at runtime |
| Dynamic property creation (deprecated 8.2) | clean, deprecation at runtime |
| `null` into an internal non-nullable parameter (deprecated 8.1) | clean, deprecation at runtime |
| Implicit float-to-int precision loss (deprecated 8.1) | clean, deprecation at runtime |

Linting on 8.4 *as well* would not close it. Almost no syntax was actually
removed in 8.x, so a second parser reports nearly nothing the first did not — it
adds a step, not coverage. Real range compatibility needs a static analyser, and
this repo has no other net to fall into: no test suite, and nothing runs on pull
requests.

## PHPStan, with `phpVersion` as a range

`phpstan.neon` sets `phpVersion: {min: 70400, max: 80400}` — the whole span the
plugin claims — so one analysis, on whatever PHP the developer happens to have,
reports anything that breaks anywhere inside it. That shape is why
`composer.json` pins `phpstan/phpstan: ^2.2`: an older major reads `phpVersion`
as a single int and would analyse one point in the range instead of the range.

`szepeviktor/phpstan-wordpress` brings WordPress core in as stubs. Without it
every `add_action()` is an unknown function and the findings that matter drown —
263 errors without the stubs, 38 with them.

Level 5 is where the compatibility findings live. Raising it later lengthens the
baseline rather than the gate, and is separate work.

`npm run analyse:php` runs it, alongside `npm run typecheck` and
`npm run lint:php` — the gate stays a set of npm scripts a developer runs by
hand, for the reasons in [ADR 0006](0006-afk-harness-invariants.md). The parse
step keeps its place *in front* of the analysis: it takes seconds, and PHP 8-only
syntax should fail there rather than after a Composer install.

## The baseline is a to-do list

The 12 files in `include/` had never been analysed and carried 38 findings on the
day they first were. `phpstan-baseline.neon` records them so the gate starts
green and a new finding is unambiguous. Two thirds of them are one shape:
`Abstracts\Base::__get()` reaches into `NextJsRevalidate::init()` for `queue`,
`settings`, `revalidate`, `revalidateAll` and `restApi`, so every use of those
reads as an undefined or private property. That is real debt, and it is now
written down instead of unknown.

The baseline is per message, per file, with a count — so a *second* occurrence of
an already-baselined message in the same file is still reported. It suppresses
what was there, not the shape of it.

It is a list of things to fix, never a place to put things. An implementer that
adds an entry rather than fixing what it introduced has defeated the gate, which
the implement prompt says in as many words.

## Considered Options

**PHPCompatibility (the phpcs standard).** Purpose-built for exactly this
question, and it would have answered it better if it had shipped. Its last stable
release is 9.3.5, from December 2019, which predates the 8.1+ sniffs entirely;
10.0.0 has been in alpha since October 2025. A gate whose coverage of 8.2–8.4 —
the versions the plugin is actually running on — is incomplete, and silently so,
is worse than one that reports what it does not know. Worth re-checking when
10.0.0 is stable: it is the better tool for this specific question, and nothing
here forecloses running both.

**Linting on 8.3 or 8.4 as well as 7.4.** Cheap and nearly useless, per the
table above. The hazards at that end are runtime, not syntax.

**Fixing the 38 findings instead of baselining them.** They are a dozen files of
real work, most of it in the magic-`__get` shape above, and some of it is a
design question rather than a typo. Blocking every pull request behind it would
have kept the gate from landing at all — which is how a repo ends up with no
analyser rather than a documented backlog.

## Consequences

The gate now needs Composer dev dependencies, so the sandbox image carries
Composer and `unzip`, and the harness's `GATE_COMMAND` runs `composer install`
between the parse and the analysis. `unzip` is not optional: `phpstan/phpstan`
publishes no source repository, only a dist archive, so a container without it
cannot install the analyser at all. The image is only built when missing —
a Dockerfile change needs a rebuild by hand, which `.sandcastle/README.md` says.

A gated item now pays for a Composer install it did not before. It sits behind
both parsers, so an item that fails on syntax or types never reaches it.

Fixing baselined debt fails the gate until the baseline is regenerated
(`vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon`): PHPStan
reports an ignore pattern that no longer matches as an error of its own, naming
the pattern and the file. That is `reportUnmatchedIgnoredErrors` at its default,
and it is kept there deliberately — the alternative lets a fixed entry sit in the
file forever, silently re-suppressing the finding the day someone reintroduces
it. The cost is one command, prompted by a message that says exactly which
pattern went stale; the benefit is that the baseline can only shrink.

The analysis covers `include/` and `nextjs-revalidate.php`. `config/` and the
harness's own PHP-free TypeScript are outside it; `npm run lint:php` still parses
every tracked PHP file in the repo, so nothing lost its parse check.
