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
