# The WordPress floor is the newest core API the plugin calls, declared as `MAJOR.MINOR`

`Requires at least` said `5.0.0`, in `nextjs-revalidate.php` and `readme.txt`
both, from before this plugin had most of what it now does. It was not true.
`wp_after_insert_post` — the hook the headline feature hangs on — arrived in
WordPress **5.6.0**, so on 5.0 through 5.5 that hook never fires and saving a
post revalidates nothing. Not loudly: the plugin activates, the settings screen
works, the admin bar renders, revalidate-all works, the REST routes answer. The
operator has evidence the plugin functions and no evidence at all that its
primary feature does not (#122).

A declared floor is not documentation. WordPress.org uses it to decide which
sites are offered the plugin, and core uses it to decide whether the plugin may
be activated at all. Declaring one the code does not honour is how a site gets
told "compatible", configures a domain and a secret, and runs a plugin that looks
healthy and never revalidates a save.

## The floor is 5.6, and it came from a sweep

Picking the number from the one hook that surfaced the problem would have left
the next one unknown. Every function call, hook name and class reference in
`include/` and `nextjs-revalidate.php` was extracted and matched against core's
own `@since` annotations. What sets the floor, newest first:

| Surface | `@since` | Where |
| --- | --- | --- |
| `wp_after_insert_post` (action) | 5.6.0 | `Revalidate::register_hooks()` |
| `deleted_post`, two-argument form | 5.5.0 | `FseSnapshot::register_hooks()` |
| `is_taxonomy_viewable()` | 5.1.0 | `Revalidate::should_revalidate_taxonomy()` |
| `wp_initialize_site` (action) | 5.1.0 | `NextJsRevalidate::__construct()` |
| `WP_Screen::is_block_editor()` | 5.0.0 | `Traits\BlockEditorScreen` |

Everything else the plugin touches predates 5.0 — the newest below that line are
`wp_unschedule_hook()` (4.9.0), `get_sites()` and `get_current_network_id()`
(4.6.0), and the REST and viewability APIs at 4.4.0.

So 5.6 it is: the lowest number at which nothing in this plugin is silently
inert. It is not the newest WordPress, and deliberately not — raising the floor
past what the code needs locks the plugin out of sites where it would work
correctly, which is a support decision rather than a mechanical one, and nothing
here is entitled to make it.

Two things are *not* on that list, on purpose:

**FSE.** `wp_template` and `wp_template_part` are post types WordPress 5.8
introduced, so on 5.6 and 5.7 `save_post_wp_template` never fires and the
**FSE snapshot** is never invalidated. That is inert in the same mechanical sense
and benign in a way `wp_after_insert_post` is not: a site below 5.8 has no
templates of that kind, so there is no snapshot to be stale and no operator being
misled about anything. A feature that cannot have work to do is not a broken
feature. The same reasoning is what makes `is_taxonomy_viewable()` safe — see
[ADR 0022](0022-taxonomy-viewability-gates-term-revalidation.md).

**The admin script's `wp-data` and `wp-notices` dependencies.** Both are block
editor packages, registered by core since 5.0, below the floor either way.

## `5.6`, not `5.6.0`

Core compares the header with `version_compare( $wp_version, $required, '>=' )`.
`$wp_version` on a WordPress 5.6 install is the string `5.6`, and
`version_compare( '5.6', '5.6.0', '>=' )` is **false** — a three-part header
excludes the very release it names. The old `5.0.0` carried that bug already:
it locked the plugin out of WordPress 5.0 while claiming to support it, which
nobody noticed because the floor was wrong by five releases anyway.

Both files now say `5.6`, and `tests/wordpress-floor-test.php` holds the shape as
well as the number.

## Nothing is added for installs below the floor

Core has refused to activate a plugin whose `Requires at least` exceeds the
site's version since **5.2.0** (`validate_plugin_requirements()`, reading the
main file's header since 5.3.0 and only that since 5.8.0). So the correction
above *is* the guard for 5.2 through 5.5: those sites now get core's own error,
naming both versions, in place of a silent no-op.

That leaves 5.0 and 5.1, where the header is not enforced, and there is nothing
worth building for them. A site already running the plugin is not reached by a
header change at all — core does not deactivate what is active — and once
WordPress.org honours the new floor such a site is no longer offered updates, so
it would never receive the notice that told it about the problem. An activation
notice would therefore fire only on a fresh install on a two-release window from
early 2019 that WordPress.org will also refuse to serve. This ticket is
distribution metadata; the code stays as it is.

## `Tested up to` is a record, not a header edit

The two files disagreed — `6.2` in `nextjs-revalidate.php`, `6.1` in
`readme.txt` — and `6.2` appears nowhere else in this repository. The readme's
value is the operative one, since that is the file WordPress.org reads
`Tested up to` from, so the plugin file came down to meet it rather than the
other way round. Neither number moved on wp.org: both were already past the
"not tested with the latest 3 major releases" line.

Raising it is a separate act with a prerequisite. `.wp-env.json` pins no `core`
version, so every **pass** — manual and PHPUnit alike — runs against whatever
WordPress was latest that day, and no record of which one survives.
`johnpbloch/wordpress-core: 6.1` in `composer.json` is not that record either:
it is there for the debugger's path mapping in `.vscode/launch.json` and nothing
loads it. Pinning `core` in the wp-env configuration is what would make
`Tested up to` a number somebody can point at, and it is its own piece of work.

## Consequences

`tests/wordpress-floor-test.php` runs in the gate, per
[ADR 0008](0008-two-testing-idioms.md), and holds four things: that the two
headers agree, that they equal the floor this ADR settled on, that the floor is
written `MAJOR.MINOR`, and that every row of the table above still names
something the source actually uses.

What it cannot hold is the direction that matters most — a *newly added* call to
an API newer than 5.6 is invisible to it, because deciding that offline needs
core's `@since` annotations and the test deliberately reads nothing outside this
repository so it can run before `composer install`. So the table is maintained by
hand: **reaching for a core API, hook or class introduced after the floor means
adding a row and raising the header, or establishing that its absence is benign
the way FSE's is above.** That is the discipline
[ADR 0016](0016-php-compatibility-gate.md) gives PHP and this repository had no
WordPress counterpart for. A PHPStan rule reading the stubs it already loads
could automate it; that is a larger build than this ticket and is not blocked by
anything here.

## Considered Options

**Leaving the floor at 5.0 and documenting the gap.** Prose does not reach
WordPress.org. The header is the only thing deciding which sites are offered the
plugin, so a comment saying "actually 5.6" changes the behaviour of nobody's
install.

**A higher, rounder floor — 6.0, or the current security floor.** Tempting,
because it matches what the project is willing to test, and wrong for this
ticket: it would decline sites on 5.6 through 5.9 where every feature here
works. "What we test" and "what the code requires" are different claims with
different headers, and conflating them costs real installs. Raising the floor
further stays available, as a decision made on support grounds and taken on its
own.

**Version-guarding the 5.6 API instead — `wp_after_insert_post` where available,
`save_post` below it.** Two code paths through the plugin's single most
important hook, differing in exactly the ways that made core add the newer hook
(`save_post` fires before terms and meta are written), maintained forever for a
WordPress release line that has had no security support for years. The floor is
one line and honest.
