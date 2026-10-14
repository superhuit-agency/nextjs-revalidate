# A routing setting reports a revalidate all of the whole site

Decided while triaging #172, for v2.1.1. Settles what ADR 0037 left to #172.

> On `v1.x` this is a copy of the ADR on `main`, kept here so `v1.x` holds the
> number as well: `tests/adr-numbers-test.php` sees only its own branch, so
> without it `v1.x`'s next ADR would take 0044. Nothing on `v1.x` implements it:
> under v1 the front-end's timer caught what a routing setting leaves stale, and
> the ADRs and `CONTEXT.md` entries it cites live on `main`.

Some site-wide values decide which content lives at which path: the **routing
settings**, as `CONTEXT.md` defines them. ADR 0037 kept them off the site
settings, because expiring the tag every page carries does not fix what they
leave stale — pages cached at paths that no longer hold them, "not found"
answers cached at paths that now do, and listings paginated by the old page
size. Under v1, the front-end's hourly timer caught all of it. Superstack's
front-end dropped its timer with superstack#141 (closed 2026-10-08), so from
then on these stayed stale until an operator ran **revalidate all**: the
regression ADR 0033's amendment says a release must not ship.

## The decision

**A change to a routing setting reports one revalidate all of the whole site**
— `{ "subject": "all" }` — the same change an operator's revalidate all sends.
The reference route maps it to `content`, which covers `uris`, every node and
every listing. One per request, however many routing settings were saved: the
Reading screen saves four at once.

**The routing settings WordPress owns** are `permalink_structure`,
`category_base`, `tag_base`, `show_on_front`, `page_on_front`, `page_for_posts`
and `posts_per_page`. One reports when it is added, updated to a different
value, or deleted — whether or not the new value moves a path in practice.
Changing `page_on_front` while `show_on_front` is `posts` moves nothing, and
still reports: deciding which paths really moved means rebuilding WordPress's
routing, and a wasted rebuild on a rare admin save costs less than a missed one.

**The routing settings Polylang owns** are `default_lang`, `hide_default`,
`force_lang`, `rewrite`, `domains` and `redirect_lang` in the `polylang`
option. A change of default language moves paths too: with `hide_default` on,
the old default gains its prefix and the new one loses it; with it off, which
language an unprefixed path answers in can still follow the default. So a
change of `default_lang` now reports a revalidate all rather than a `settings`
change, and `content` covers `settings` in the reference scheme.

## Implementation note: Polylang is compared before delivery

Polylang 3.7 and later hold their options in memory and write the `polylang`
option on `shutdown` at priority 1000, after delivery at priority 10 (ADR 0037's
implementation note). Its settings page and its REST `pll/v1/settings` route
write into that memory, and Polylang fires no action of its own for these keys.

So the integration compares, just before delivery, the routing keys in
Polylang's in-memory options with `get_option( 'polylang' )`, which still holds
what was there before. Both sides are normalised first — the stored option can
hold `"1"` where memory holds `true`. One comparison catches every writer that
goes through the shared options: the settings page, REST, renaming the default
language's slug, adding the first language, the importer. It replaces the
`pll_update_default_lang` hook, which only one of those writers fires. The
`update_option_polylang` hook stays, for a direct write from WP-CLI, a
migration, or Polylang's wizard.

## Considered Options

**A new subject**, such as `{ "subject": "routing" }`, so a front-end could
expire less than everything. Rejected for now: it is a contract addition, so a
minor release, and a 2.0 route ignores it until updated — the gap stays open
on exactly the front-ends that regressed. Additive later, under ADR 0033's
rule 1, if a front-end ever wants to tell the two apart.

**Something narrower per setting** — only listings for `posts_per_page`.
Rejected: every paginated URI moves with it, and each narrower answer is its
own contract addition.

**Reporting every rewrite flush.** Rejected. Plugins flush on activation and on
post type registration, and some on every request, so the front-end would
rebuild whole for work that moved nothing. A rewrite slug changed in code
arrives with a deploy, and the operator's revalidate all is the answer there.

**Hooking Polylang's AJAX save and REST route** to take a snapshot before they
write. Rejected: two hooks into Polylang's internals, and every other writer
missed.

## Consequences

- **Revalidate all** is no longer only an operator's request; `CONTEXT.md` says
  so. On the wire the two are the same change, and a front-end cannot tell them
  apart.
- A routing setting saved on a busy site rebuilds every page on its next
  request. These are rare, deliberate admin saves.
- A site's own routing setting — `woocommerce_permalinks`, a post type plugin's
  slug option — is not caught. A filter to name them, beside
  `nextjs_revalidate_site_setting_options`, is new public API, and waits for a
  minor release.
- A front-end that opted the front page options into
  `nextjs_revalidate_site_setting_options` as ADR 0037's stopgap may drop them.
  Keeping them only adds a `settings` change beside the `all`.
