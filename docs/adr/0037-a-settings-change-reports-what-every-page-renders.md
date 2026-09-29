# A settings change reports what every page renders, never what moves a path

Decided while grilling #171, for v2.0 (see ADR 0033's amendment).

A `settings` change — `{ "subject": "settings" }`, one per request however many
**site settings** were saved — tells the front-end that something it renders on
every page changed. The front-end expires one tag for it, which every cached page
carries, so every change reported here costs a rebuild of every page on its
next request. Everything below follows from keeping that cost for the
edits that need it.

## The decision

**A site setting is a value the front-end renders as it is**, as `CONTEXT.md`
defines it. The built-in list is WordPress's own: `blogname`, `blogdescription`,
`date_format`, `time_format`, `timezone_string`, `gmt_offset`, `home`,
`site_icon` and `WPLANG`, filtered through
`nextjs_revalidate_site_setting_options`. An option on the list reports a change
when it is added, updated to a different value, or deleted.

**Options that move which content lives at which path are not site settings** —
`permalink_structure`, the category and tag bases, `show_on_front`,
`page_on_front`, `page_for_posts`, `posts_per_page` — though they are site-wide
options too. Expiring the tag every page carries does not fix what they leave
stale: pages cached at paths that no longer hold them, and "not found" answers
cached at paths that now do. They are a different fact, and are left to #172.

**SEO defaults and languages come from integrations** (Yoast SEO, Polylang), each
as inert as Redirection's when its plugin is absent (ADR 0014):

- **Yoast** adds `wpseo_titles` and `wpseo_social` to the list, through the same
  filter a site uses. **Not `wpseo`**: nothing the front-end renders lives there,
  and Yoast writes it on its own — indexing progress, activation timestamps,
  notification and tracking state — so watching it would rebuild every page
  from Yoast's background work. A site whose front-end does read it adds it
  through the filter.
- **Polylang** reports a change when a language is created, edited or deleted —
  a term of its `language` taxonomy, not an option — and when the `polylang`
  option is updated **with a different `default_lang`, and only then**. The
  same option holds `hide_default`, `force_lang` and `rewrite`, which decide
  whether a language prefix is in the path at all: they move paths, and are
  excluded for the reason above. It also holds bookkeeping such as `version`,
  which a Polylang upgrade rewrites.

## Considered Options

**Watch the whole `polylang` option.** Simpler, and it cannot miss a key.
Rejected because a Polylang upgrade would rebuild every page, and a URL-shaping
switch would report a change whose tag fixes nothing.

**Watch every Yoast option.** Rejected for `wpseo`'s background writes, above.

**Put Yoast's and Polylang's option names in the built-in list** instead of in
integrations. Rejected because Polylang's languages are terms, which no option
list can catch, and because the plugin supports its integrations without
naming them in its core — the Redirection precedent.

**Name which setting changed.** Rejected for the reason ADR 0033 gives for
templates: the front-end caches them as one dependency, and nothing reads the
name. Additive later, under ADR 0033's rule 1.

## Implementation note: Polylang writes its option after delivery

Found while implementing #171. Polylang 3.7 and later hold their options in
memory and write the `polylang` option on `shutdown` at priority 1000 — after
the pending changes are delivered at priority 10 (ADR 0034). A change of default
language made through Polylang is therefore reported from the
`pll_update_default_lang` action it fires while the request is running; the
option's own hook stays, for a direct write from WP-CLI or a migration. The
decision above is unchanged: only a different `default_lang` reports.

One upgrade path still reports. Some Polylang upgrades rewrite the language
terms themselves (`install/upgrade.php`, e.g. the 3.9 flag migration), which
fires the term hooks and reports one `settings` change on the upgrade request.
That is accepted: it is one-off, the flags it rewrites are rendered, and it is
one change — not a rebuild on every `version` bump, which is what watching the
whole option would cost.

## Amended for #180: the logo, prefixes, and Polylang's strings

Found porting a site's front-end to Cache Components. Three gaps, and none of
them changes the decision above:

- **`site_logo` is on the built-in list**, beside `site_icon`. The core Site Logo
  block renders it, and core keeps it in step with the theme's `custom_logo`
  mod.
- **An entry of `nextjs_revalidate_site_setting_options` ending in `*` names
  every option starting with what comes before it.** An option stored once per
  language — `landbot_config_url_fr`, or ACF options under
  acf-options-for-polylang — cannot be listed ahead of time. A prefix, and not a
  pattern language, because a prefix is all those names share. A bare `*` names
  no option: it would make the cron array and every transient a site setting.
- **Polylang's string translations are site settings.** The translated site
  title, tagline, and every string registered with Polylang are kept in the
  language term's `_pll_strings_translations` meta, not in an option, so the
  integration reports a change from that meta's hooks.
