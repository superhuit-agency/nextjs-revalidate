# Hook registration is explicit, uniform across every class, and owned by the composition root

Every class the plugin constructs registered its WordPress hooks inside its own
constructor — 30 registrations across nine classes. Construction and
registration were therefore inseparable: obtaining an instance in order to call
one method also mutated global hook state. `NextJsRevalidate::uninstall()` did
exactly that, constructing a second `RevalidateQueue` purely to reach
`delete_table()` and silently registering a second set of four hooks as a side
effect of wanting one method.

That second instance is **inert, not harmful** — uninstall runs after
`admin_init` has fired and redirects before any notice renders, so the duplicate
registration has nothing left to trigger. The cost was paid somewhere else
entirely: the constraint ruled out the natural implementation of the per-site
sweep in #24, where constructing a fresh queue inside each iteration would have
registered 4N hooks on an N-site network. #24 works around it by keeping the
singleton and deriving per-site values lazily, which is correct on its own
merits — but the next caller who wants an instance without the hooks would have
hit the same wall.

Hook registration is now an explicit `register_hooks(): void`, declared by a
**`Hookable`** interface and implemented by all nine classes the **composition
root** constructs. The root enrols each object as it constructs it and calls
`register_hooks()` on every one of them, in construction order. Constructing any
class of this plugin now touches no global state.

**This is a convention, not a bug fix.** Nothing in the codebase needs a hookless
instance today, and #24 retires the only historical caller. What it buys is that
no future caller has to choose between the method it wants and the hooks it does
not — and that the choice is not re-litigated per class.

## Considered Options

**Idempotent registration, left in the constructor.** A per-class static guard,
or `remove_action()` before `add_action()`. Kills the double-registration symptom
for less code and no method extraction. Rejected because it treats the symptom as
the problem: you still cannot obtain an instance without hooks, so the constraint
that shaped #24 survives untouched. It also makes "how many times was this
constructed" unanswerable rather than harmless.

**Enforced singletons — private constructors and static accessors.** Makes a
second instance impossible rather than tolerable. Rejected: it fights the
existing style, where `NextJsRevalidate` holds these as plain properties and
`Base::__get` proxies to them, and it deepens the singleton coupling that already
makes this codebase hard to test. It removes the mistake by removing the
capability.

**The composition root registers all 28 hooks itself.** One file would show the
plugin's entire WordPress surface, which is genuinely useful to read. Rejected
because it separates each hook from the callback it names: priorities and
argument counts would live in the main plugin file while the methods they govern
live in `include/`, and every new callback would mean editing two files. The
class is the right owner of its own hook list; the root is the right owner of
*when* that list is applied.

**A declarative hook spec — each class returns an array, the root applies it.**
Makes registration inspectable without WordPress loaded, which a future test
suite could assert against. Rejected as present-tense indirection for an absent
benefit: the repo has no PHPUnit, so nothing can collect it, and it turns four
plain `add_action` lines into a data structure plus an applier.

**An abstract `register_hooks()` on `Base`.** No new file, and seven of the nine
classes already extend `Base`. Rejected because the other two, `Assets` and
`I18n`, would have to inherit `Base` solely to gain the contract — and `Base`
exists only to proxy property lookups to the `NextJsRevalidate` singleton via
`__get`. Two classes that need none of that coupling would acquire all of it. An
interface asserts exactly one thing and forces nothing else.

**Adopting the convention only where double construction has actually happened.**
`RevalidateQueue` alone, or `RevalidateQueue` and `Settings` as the issue
originally framed it. Rejected because partial adoption is worse than either
extreme: the plugin would carry two competing conventions, the root would have a
registration loop *and* leftover constructor registration, and the `admin_init`
ordering would become harder to reason about rather than easier. A convention
that holds for two classes out of nine is not a convention.

## Consequences

Registration order is now load-bearing in a place it was not before. Eight
callbacks sit on `admin_init` at priority 10 across four classes, and WordPress
runs same-hook, same-priority callbacks in registration order. The root's
enrolment order must therefore reproduce today's construction order exactly, or
the refactor silently reorders `admin_init` — `Settings::migrate_db` no longer
running before `RevalidateQueue::action_reset_queue`, and so on.

The change introduces a **new silent failure mode**: a class can now be
constructed and never registered, which for `Revalidate` would mean eight lost
hooks and content that stops revalidating on save, with no error anywhere. This
is why the root enrols through a `hookable()` helper that stores and returns,
making construction and enrolment a single expression, rather than pairing nine
constructions with nine separate registration calls. Adding a tenth class
without registering it is not a mistake the shape permits.

Eight of the nine constructors did nothing but register hooks and are deleted
outright. `ScheduledPurges` is the only one that survives, and only for its
`$timezone`. `RevalidateQueue`'s went with the rest rather than lingering as this
record first anticipated: #24 landed first and had already made both the table
name and the timezone call-time derivations, leaving its constructor holding
nothing but the four registrations. That eight of nine classes had a constructor
that constructed nothing is the clearest evidence the two concerns were conflated.

A syntax and type gate cannot verify this change: it goes green on a refactor
that silently drops eight hooks. So the change carries its own check,
`tests/HookRegistrationTest.php`, in the standalone idiom of ADR 0008. It stubs
`add_action()` and `add_filter()`, records what it is asked for, and asserts the
two halves of this decision. That constructing any of the nine registers nothing
at all is the property the whole convention exists for. That `register_hooks()`
then produces exactly the sequence the constructors produced before is what
proves nothing was lost and nothing was reordered, and it is asserted twice: per
class, and again through the composition root, which is the only place the
*inter*-class order is decided. The expected sequence is written out literally
rather than derived from the classes, so a dropped or reordered registration
fails the test instead of agreeing with itself.

The root half of that needs the composer autoloader — the plugin file returns
early without it — so with no `vendor/` it reports itself skipped rather than
failed, and the file keeps the one property the standalone idiom is for: it needs
nothing.

Sequenced after #24, which rewrites `uninstall()` and empties `RevalidateQueue`'s
constructor. #24 retains ownership of the second-construction site itself. Taking
them in the other order would gate a real bug — network uninstall drops one table
and leaves the rest — behind a refactor, and would rebase a large rewrite onto
changed files rather than the reverse.

Nothing here requires PHP 8: interfaces and `void` return types are 7.1, and the
plugin declares 7.4.
