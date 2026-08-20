#!/bin/sh
#
# Regenerate languages/nextjs-revalidate.pot from source, then carry every
# translation forward against it.
#
# The POT is a build artefact of the source tree, and nothing regenerated it —
# which is how it came to sit 20 strings and two years behind the code, with
# operators reading English for strings that had a French translation waiting.
# Run this whenever a translatable string is added, changed or removed.
#
# The X-Poedit-* headers are preserved on purpose: the .po files were written in
# Poedit, and dropping them breaks its "update from source" for whoever opens
# one next.

set -e

command -v wp >/dev/null 2>&1 || {
	echo "make-pot: WP-CLI is missing — install it from https://wp-cli.org. The i18n commands ship with it; there is no separate package." >&2
	exit 1
}

command -v msgmerge >/dev/null 2>&1 && command -v msgfmt >/dev/null 2>&1 || {
	echo "make-pot: GNU gettext is missing — 'brew install gettext' on macOS. msgmerge carries existing translations onto the new POT; without it a rebuild would strand every one of them." >&2
	exit 1
}

cd "$(dirname "$0")/.."

POT=languages/nextjs-revalidate.pot

wp i18n make-pot . "$POT" \
	--domain=nextjs-revalidate \
	--exclude=vendor,node_modules,dist,tests,.sandcastle,config \
	--headers='{"X-Poedit-Basepath":"..","X-Poedit-Flags-xgettext":"--add-comments=translators:","X-Poedit-WPHeader":"nextjs-revalidate.php","X-Poedit-SourceCharset":"UTF-8","X-Poedit-KeywordsList":"__;_e;_n:1,2;_x:1,2c;_ex:1,2c;_nx:4c,1,2;esc_attr__;esc_attr_e;esc_attr_x:1,2c;esc_html__;esc_html_e;esc_html_x:1,2c;_n_noop:1,2;_nx_noop:3c,1,2;__ngettext_noop:1,2","X-Poedit-SearchPath-0":".","X-Poedit-SearchPathExcluded-0":"*.min.js","X-Poedit-SearchPathExcluded-1":"vendor","X-Poedit-SearchPathExcluded-2":"dist","X-Poedit-SearchPathExcluded-3":"node_modules"}'

# Each translation is merged rather than regenerated: a string whose source
# changed becomes `#, fuzzy` — kept for the translator to look at, and ignored
# at runtime, so a stale translation is never shown as a current one.
for po in languages/*.po; do
	[ -f "$po" ] || continue
	msgmerge --update --backup=none "$po" "$POT"
	msgfmt -o "${po%.po}.mo" "$po"
	printf '%s: ' "$po"
	msgfmt --statistics -o /dev/null "$po" 2>&1
done
