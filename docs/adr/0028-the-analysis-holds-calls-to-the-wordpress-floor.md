# The analysis holds every core call to the WordPress floor, and hooks are held by hand

Decided while fixing #122, alongside
[ADR 0027](0027-the-wordpress-floor-is-the-newest-api-the-plugin-calls.md), which
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

`config/phpstan/WordPressFloorRule.php` does. For every call — `foo()`,
`$x->foo()`, `$x?->foo()`, `X::foo()` and `new X` — that resolves to something
declared in the stubs, it takes the first `@since` (where core records the
introduction; later ones record changes) and fails the analysis when it is newer
than the floor:

```
Function wp_is_block_theme() was added in WordPress 5.9.0, above the 5.6 floor
the plugin declares (`Requires at least`). Raise the floor (ADR 0027), or guard
the call with function_exists().
🪪 nextjsRevalidate.wordpressFloor
```

A method with no `@since` of its own is dated by its class. A call inside a
`function_exists()`, `class_exists()` or `method_exists()` check on what it calls
is not reported: that is how a plugin uses a newer API below its release, and
PHPStan's scope already knows when a call sits inside one.

It runs as part of `npm run analyse:php`, so it is in the same CI job as the
analysis ADR 0016 set up and in the harness's gate, and it passes on the current
code with nothing added to the baseline. Lowering `wordpressFloor` to `4.9`
reports exactly `is_taxonomy_viewable()` (5.1.0) and
`WP_Screen::is_block_editor()` (5.0.0) — the two function-shaped rows of ADR
0027's sweep, found without the sweep.

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

## Hooks are the accepted limit

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

## Considered Options

**Indexing hooks from core's source.** `johnpbloch/wordpress-core` is already a
dev dependency, and every `do_action()` in it has a documented `@since`. It is
pinned at 6.1 for the debugger's path mapping, so it knows nothing newer than
the hooks most likely to be reached for, and moving it moves what `.vscode`
maps. Parsing all of core on each analysis also costs more than the stubs, which
already take most of the memory ADR 0020 pins. Worth doing if the plugin starts
registering hooks often enough for the list to be a burden; three rows are not.

**A table in the ADR, checked by hand.** What this change started as. It holds
the calls somebody remembered to write down, which is the failure it is meant to
prevent.

**Reading the floor from the plugin header.** One copy instead of four, and a
result cache that answers for a floor that is no longer there. Local runs would
disagree with CI, which starts cold.

## Consequences

Reaching for a core API newer than the floor is now a red analysis, not a review
comment. The fix is one of three: guard it, raise the floor in all four places
(ADR 0027), or use something older.

The rule is only as good as the stubs' docblocks. `php-stubs/wordpress-stubs`
tracks the current release and copies core's annotations, so an API core
documents wrongly is dated wrongly here too; an API with no `@since` at all,
on itself or its class, is not reported.

The rule is PHP the analysis loads, not PHP the plugin ships. It lives under
`config/phpstan/`, is autoloaded through `autoload-dev`, and like every tracked
PHP file is parsed by `npm run lint:php` on 7.4.
