# PSR-4 is the only way a plugin class is found, and the classmap goes

Decided while fixing #73.

`composer.json` declared its PSR-4 prefix as `NextjsRevalidate\` while every file
in `include/` declares `namespace NextJsRevalidate;`. PSR-4 prefix matching is
case-sensitive, so the prefix matched nothing and PSR-4 autoloading was dead for
the whole plugin. It was invisible because the same `autoload` block also
declared a `classmap` over `include/`, and Composer consults the classmap first.

The prefix is now spelled the way the code is. Two further things changed with
it, and they are the parts worth recording.

## The directories are named after the namespace segments

PSR-4 is case-sensitive on the *path* as well as the prefix. Fixing the prefix
alone left `NextJsRevalidate\Abstracts\Base` resolving to `include/Abstracts/Base.php`
against a directory named `abstracts/`, and `NextJsRevalidate\Traits\*` against
`traits/` — so the two subdirectories that actually carry a namespace segment
were still classmap-only, which is exactly the case #64 tripped over when it
added `include/traits/AdminBarMenu.php`.

`abstracts/` and `traits/` are therefore renamed to `Abstracts/` and `Traits/`.
`Cron/` already matched. The alternative — keeping a classmap entry for just
those two directories — was rejected for the reason the whole issue exists: it
leaves a rule that is true of most of `include/` and quietly false for part of
it, which is worse than no rule.

## The classmap is removed rather than kept

Keeping it looks free: PSR-4 works now, and a classmap is a lookup table instead
of a `file_exists()` probe. It is not free, because it is the thing that hid this
bug for as long as the bug existed. A classmap over the same directory PSR-4
covers means a future prefix typo, a directory renamed to lowercase, or a
namespace that stops matching its path all keep working on any machine that ran
`composer dump-autoload` afterwards, and break only for someone who didn't. That
is a failure that reaches a reviewer or a deploy rather than the author.

With the classmap gone, PSR-4 is the only path, so a mapping that does not hold
fails immediately and in the same way for everyone. The cost is a `file_exists()`
per class on first load of thirteen files, and release CI can take it back with
`-o` whenever that is measured to matter — an optimisation derived from the
PSR-4 rules is a different thing from a classmap that substitutes for them.

The one-time cost is real and is not a reason to keep the classmap: any checkout
carrying a `vendor/` generated before this change needs `composer dump-autoload`,
because its stale classmap points at `include/abstracts/Base.php`. That is true
whether or not the classmap entry survives — a stale classmap is in fact the
worse of the two, since Composer `include`s the recorded path without probing it.

## The rule is tested, and the test uses no autoloader

`tests/psr4-autoload-test.php` reads the `autoload.psr-4` map out of
`composer.json`, walks `include/`, and asserts that each declared type resolves
to the file it is actually in. Per ADR-0008 it is a standalone script.

It does not call `class_exists()`, and that is the point. Asserting through a
generated `vendor/` tests whatever was last dumped on that machine, which is the
observation that made this bug survive; asserting on the mapping tests the rule
that gets dumped. It also builds the on-disk paths from directory entries rather
than probing a computed path with `file_exists()`, so a case mismatch is still
caught on a case-insensitive filesystem — where it is otherwise invisible right
up until CI runs on Linux.
