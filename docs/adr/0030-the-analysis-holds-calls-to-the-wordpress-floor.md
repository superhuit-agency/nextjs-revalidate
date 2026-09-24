# The analysis holds the core APIs the plugin uses to the WordPress floor, and hooks are held by hand

Decided while fixing #122, alongside
[ADR 0028](0028-the-wordpress-floor-is-the-newest-api-the-plugin-calls.md), which
settled the floor this holds.

[ADR 0016](0016-php-compatibility-gate.md) gave the PHP range a gate: PHPStan,
over `phpVersion: {min: 70400, max: 80400}`, on every pull request. WordPress
had nothing equivalent. `Requires at least` said 5.0 for as long as the plugin
hung its headline feature on a 5.6 hook, and every check passed the whole time,
because none of them knew what release anything in core arrived in. Correcting
the number once fixes the number; it does nothing about the next call to
something newer.

## A PHPStan rule reading `@since` out of the stubs

The analysis already loads WordPress core as stubs, through
`szepeviktor/phpstan-wordpress`, and the stubs keep core's docblocks. So every
core function, method and class the plugin calls is resolved by PHPStan to a
declaration that carries the `@since` core gave it. Nothing had been reading it.

`config/phpstan/WordPressFloorRule.php` does. For every use of core that
resolves to something declared in the stubs, it takes the first `@since` (where
core records the introduction; later ones record changes) and fails the analysis
when it is newer than the floor. A use is:

- a call — `foo()`, `$x->foo()`, `$x?->foo()`, `X::foo()`, `new X` — including
  on a receiver that may also be `null` or `false`, as `get_current_screen()`'s
  is;
- a class constant, `X::FOO`;
- a function or method named as a callback — `'foo'`, `'X::foo'`,
  `[ 'X', 'foo' ]` — in an argument whose parameter is a `callable`, which is
  every `add_action()`, `add_filter()`, `call_user_func()` and `array_map()`.
  Only there: a hook name is a string too, and `do_action( 'wp_body_open' )`
  names a hook whatever function shares its name;
- a class a declaration `extends` or `implements`, since a missing parent is
  fatal the moment the file loads.

```
Function wp_is_block_theme() was added in WordPress 5.9.0, above the 5.6 floor
the plugin declares (`Requires at least`). Raise the floor (ADR 0028), or guard
it with function_exists().
🪪 nextjsRevalidate.wordpressFloor
```

A method or constant is as new as the newer of its own `@since` and its
class's: one with none arrived with its class, and none arrived before it. A
`@since` that names no release — `Unknown`, `Beta`, the bundled libraries' own —
dates nothing, rather than letting a later change stand in for the introduction.

A function inside a `function_exists()` check on it, and a class inside a
`class_exists()` check on it, is not reported: that is how a plugin uses a newer
API below its release, and PHPStan's scope already knows when a use sits inside
one. A `class_exists()` check also covers the members no newer than the class it
checks, so `$tags->next_tag()` inside `class_exists( 'WP_HTML_Tag_Processor' )`
is let through, and `$theme->is_block_theme()` (5.9) inside
`class_exists( 'WP_Theme' )` (3.4) is not.

`method_exists()` is not honoured, because it cannot be. The stubs describe the
newest core, where the method exists, so PHPStan has nothing to narrow — and
reports the guard itself as always true (`function.alreadyNarrowedType`), which
at this level fails the analysis on its own; it is why ADR 0026 dropped the one
the plugin had. A call to a newer method that really is guarded says so with an
inline `@phpstan-ignore nextjsRevalidate.wordpressFloor` and its reason, and the
rule's message for a method says that.

It runs as part of `npm run analyse:php`, so it is in the same CI job as the
analysis ADR 0016 set up and in the harness's gate, and it passes on the current
code with nothing added to the baseline. Lowering `wordpressFloor` to `4.9`
reports exactly `is_taxonomy_viewable()` (5.1.0) and
`WP_Screen::is_block_editor()` (5.0.0) — the two function-shaped rows of ADR
0028's sweep, found without the sweep.

The rule is itself in the analysed paths — the file, not `config/phpstan/` —
because PHPStan only notices an edit to an extension it analyses. Outside them,
an edited rule leaves the result cache answering for the old one, and PHPStan
fails the run with a warning saying so.

## A canary proves the rule is still reporting

The rule fails open. If a `php-stubs/wordpress-stubs` release moves its file, or
a PHPStan major changes what reflection answers, it stops recognising core and
reports nothing — and an analysis that reports nothing is green. A check that
has stopped running looks exactly like one that passes, which is #122 again.

So `npm run analyse:php` runs `config/phpstan/floor-canary/check.php` first. It
analyses `fixture.php` beside it, alone, with the project's own `phpstan.neon`,
and fails unless the rule reports exactly the fixture's unguarded uses newer
than `wordpressFloor` — one of each kind above — and nothing else is reported.
Each use in the fixture is marked with the release it arrived in, so the
expectation follows the floor rather than being written down twice; a floor
above every marked use fails the canary too, since it would then prove nothing.

Some lines are there to fail a specific blindness. `WP_Theme::is_block_theme()`
is 5.9 on a 3.4 class, so it is reported only while the rule reads a method's own
`@since` — a method sharing its class's release would stay reported by the
class's alone. `do_action( 'wp_is_block_theme' )` must not be reported, and a
`class_exists( 'WP_Theme' )` check must not cover that 5.9 method. `check.php`
pads versions with its own copy of the rule's comparison, so a broken comparison
cannot agree with itself.

It was checked against the ways it exists to catch: the rule's stubs path
changed, the rule removed from `phpstan.neon`, the `function_exists()` check
dropped, the floor raised past the fixture, a method's own `@since` ignored, the
callback check removed, and a `class_exists()` check covering every member. Each
fails it, naming the line. It
analyses one file, so it neither reads nor writes the result cache, and it costs
a couple of seconds.

## The floor it reads is a copy, held to the others by a test

`wordpressFloor` in `phpstan.neon` is the number the rule compares with. It is a
fourth copy of the floor, after the plugin header, `readme.txt` and README.md,
and deliberately not read from the header: PHPStan's result cache is keyed on its
configuration, not on files a rule happens to open, so a rule reading the header
would keep every cached verdict when only the header moved. A floor in the
configuration invalidates the cache the moment it changes.

`tests/wordpress-floor-test.php` fails when the four disagree, and it runs in the
same job. "Can't drift apart silently" is held by that test rather than by there
being one copy.

## Hooks are the accepted limit, among a few smaller ones

`add_action( 'wp_after_insert_post', … )` is, to an analyser, a call to a 2.0
function with a string in it. A hook's `@since` lives in the docblock above its
`do_action()` in core's source, and the stubs have no function bodies, so there
is no `do_action()` to read one from. The hook that caused #122 is exactly the
kind this rule cannot see.

So hooks stay a list. The ones that set a floor — `wp_after_insert_post` (5.6),
`deleted_post` with its second argument (5.5) and `wp_initialize_site` (5.1) —
are named in `tests/wordpress-floor-test.php`, which fails when any of them is
above the floor, and when any is no longer registered the way that needs it,
since then the floor may be higher than the code needs. It reads the registrations
from tokens, so a hook named only in a comment does not count. What nothing
catches is a **newly registered** hook newer than the floor: adding one means
adding it to that list, which is a discipline and not a check.

The rest of what the rule cannot see is smaller, and none of it is in the plugin
today:

- a callback PHPStan cannot resolve to a constant — built by concatenation, read
  from an option — or passed to a parameter not typed `callable`;
- a static property, `X::$foo`, and a trait `use`d from core;
- a class named only in a type declaration, an `instanceof` or a `catch`. None of
  these is fatal when the class is missing, so none of them sets a floor.

## Considered Options

**Indexing hooks from core's source.** `johnpbloch/wordpress-core` is already a
dev dependency, and every `do_action()` in it has a documented `@since`. It is
pinned at 6.1 for the debugger's path mapping, so it knows nothing newer than
the hooks most likely to be reached for, and moving it moves what `.vscode`
maps. Parsing all of core on each analysis also costs more than the stubs, which
already take most of the memory ADR 0020 pins. Worth doing if the plugin starts
registering hooks often enough for the list to be a burden; three rows are not.

> Superseded on the pin. `johnpbloch/wordpress-core` is no longer "pinned at 6.1
> for the debugger's path mapping": it follows the tested release, now 7.1, and
> moves with it
> ([ADR 0036](0036-dependabot-fixes-every-vulnerability-and-bumps-only-what-ships.md)).
> The `.vscode` paths stay the same. It still knows nothing newer than the
> tested release, so the cost above stands.

**A table in the ADR, checked by hand.** What this change started as. It holds
the calls somebody remembered to write down, which is the failure it is meant to
prevent.

**PHPStan's `RuleTestCase`.** The conventional way to test a rule, and a PHPUnit
suite — which here runs only inside wp-env (`npm run test:integration`), and CI
does not start one. The canary is a shell step's exit code, which is what the CI
job already runs, and it tests the rule as registered rather than as constructed
by a test.

**Reading the floor from the plugin header.** One copy instead of four, and a
result cache that answers for a floor that is no longer there. Local runs would
disagree with CI, which starts cold.

## Consequences

Reaching for a core API newer than the floor, in any of the forms above, is now
a red analysis, not a review comment. The fix is one of three: guard it, raise the floor in all four places
(ADR 0028), or use something older.

The rule is only as good as the stubs' docblocks. `php-stubs/wordpress-stubs`
tracks the current release and copies core's annotations, so an API core
documents wrongly is dated wrongly here too; an API with no `@since` at all,
on itself or its class, is not reported.

The rule and its canary are PHP the analysis loads, not PHP the plugin ships.
They live under `config/phpstan/`, outside the release allowlist; the rule is
autoloaded through `autoload-dev`, and like every tracked PHP file both are
parsed by `npm run lint:php` on 7.4.
