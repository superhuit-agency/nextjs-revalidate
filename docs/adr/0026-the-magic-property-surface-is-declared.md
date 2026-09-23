# The magic-property surface is declared, class by class, not baselined

Decided while implementing #82.

[ADR 0016](0016-php-compatibility-gate.md) baselined 38 findings and said of
them: "Two thirds of them are one shape: `Abstracts\Base::__get()` reaches into
`NextJsRevalidate::init()` for `queue`, `settings`, `revalidate`,
`revalidateAll` and `restApi`, so every use of those reads as an undefined or
private property. That is real debt, and it is now written down instead of
unknown." [ADR 0009](0009-checks-run-on-pull-requests.md) then regenerated the
file and left a consequence behind — "the baseline is five entries longer than
it should be … they are somebody's next ticket".

This is that ticket, and the debt is paid by **declaring the surface** rather
than by unpicking the idiom.

## The two accessors, and what an analyser sees of them

There are two, one above the other:

- `NextJsRevalidate::__get()` returns `$this->{$name}`, which makes every
  private property of the composition root readable from outside it. That is
  how `Assets` reaches `->revalidate`, how `Logger` reaches `->settings`, and
  how the two API functions at the foot of the plugin file reach theirs.
- `Abstracts\Base::__get()` forwards five of those names to
  `NextJsRevalidate::init()`, so a subclass reads a collaborator as
  `$this->queue` without ever being handed one.

PHPStan sees neither. A read through the first is an access to a *private*
property (`property.private`); a read through the second is an access to a
property that does not exist (`property.notFound`). Both are correct about the
declaration and wrong about the program. `phpstan-baseline.neon` carried 25
entries covering 33 errors; 18 of those entries, and 26 of those errors, were
this one shape.

## The declaration

`@property-read` on `NextJsRevalidate`, one line per object the composition root
constructs. Read, never written: the root is the only thing that assigns them,
and `__get()` has no `__set()` beside it.

`@property` on each class that extends `Base`, naming **only the collaborators
that class actually reaches**. `RevalidateAll` declares the queue, the settings
and `Revalidate`; `Cron\ScheduledPurges` declares the queue and nothing else.

The second half is the part with a choice in it. Declaring all five on `Base`
would be five lines rather than the nine spread over six subclass docblocks, and
would silence exactly the same findings — and it would say that every subclass
reaches everything, which is false of all ten of them. The per-class list is this
plugin's collaborator graph written down in the only place that can go stale
visibly: add a `$this->restApi` to a class that never had one and the analysis
asks for the line, the same way `Settings`' own `@property` block is what stops
its option table drifting from the names the rest of the plugin reads.

Five classes and a trait already carried a docblock of exactly this shape —
`FseSnapshot`, `FailureWindow`, `Probe`, `RevalidateAll`,
`Integrations\Redirection` and `Traits\FrontEndRequest` — which is why those
reads were not in the baseline and their siblings were. `RevalidateAll` shows
the half-finished state plainly: it declared `$revalidate` and was baselined
seven times over for `$settings` and `$queue`. The convention existed; it had
simply never been finished.

## Considered Options

**Making the root's properties public.** They are already readable from outside
— `__get()` sees to that — so this looks like deleting a fiction. It is not: a
public property can be *written*, and the composition root assigning its own
objects exactly once is the whole of [ADR 0003](0003-explicit-hook-registration.md)'s
construct-then-register order. `@property-read` describes what is true today
without widening anything.

**Injecting the collaborators through constructors.** The honest fix, and a much
larger change: ten classes, the composition root, and every test that builds one
of them standalone. It would also cost the property `__get()` buys — a class
reaches the queue of *the site currently being served*, resolved at read time,
which a constructor argument captured at construction would not survive a
`switch_to_blog()`. Out of scope here and not foreclosed.

**A baseline entry, an inline `@var`, or a `@phpstan-ignore`.** The baseline is a
to-do list, which ADR 0016 says at length and `phpstan.neon` repeats; the other
two are the same suppression spelled per call site — twenty-six comments to
maintain at twenty-six call sites, against twenty lines in seven docblocks.

## Consequences

**The baseline is 4 entries, down from 25.** What is left is four unrelated
findings, none of them this shape: `NJR_URI` in `Assets`, an unreachable
statement in `RevalidateQueue`, a `null` callback into `add_settings_section()`,
and `new static()` on the singleton. They are still a to-do list.

**Declaring a type immediately found a bug, which is the argument for declaring
types.** `Cron\ScheduledPurges` passed `true` where `RevalidateQueue::add_item()`
takes an `int $priority` — a literal port of the `$force` flag the pre-queue
`Revalidate::purge()` took there — so every scheduled purge was stored at
priority 1, ahead of the whole queue. #63 fixed it first, from the other side:
the cron now enqueues at `ScheduledPurges::QUEUE_PRIORITY` (5), as the
**Scheduled purge** entry in `CONTEXT.md` defines. That finding was invisible for as long as `$this->queue` had no type, and it was
invisible in a file the baseline covered.

**A class that starts reading a new collaborator has to say so.** One line in a
docblock, or the analysis reports an undefined property. That is the cost, and
it is the point: the alternative is the surface being discovered by whoever next
regenerates the baseline.

**Two docblocks that lied are fixed in the same change, because they produced
findings of their own.** `RevalidateAll::revalidate_all()` is annotated
`int|false` rather than `int` — it answers `false` on a **refusal**, which made
PHPStan call the caller's live `false === $nb_added` branch dead code — and
`tests/revalidate-all-refusal-test.php` now holds the distinction between that
`false` and the `0` a configured site with nothing to revalidate answers.
`Traits\BlockEditorScreen` drops a `method_exists()` guard on
`WP_Screen::is_block_editor()`, which has been there since WordPress 5.0, at or
below the floor the plugin header declares — 5.0 when this landed, 5.6 since
[ADR 0027](0027-the-wordpress-floor-is-the-newest-api-the-plugin-calls.md).
