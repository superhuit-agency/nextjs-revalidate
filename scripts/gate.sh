#!/usr/bin/env bash
#
# gate.sh — is this working tree shippable?
#
# Installs dependencies, builds the plugin assets, type-checks, and lints every
# PHP file changed against a base ref using the plugin's declared minimum PHP.
#
# Runnable by hand on any checkout; no harness and no container required.
#
#   ./scripts/gate.sh
#   BASE_REF=main PHP_BIN=/usr/local/bin/php7.4 ./scripts/gate.sh
#
# Environment:
#   BASE_REF  ref the changed-file set is computed against (default: origin/main,
#             falling back to main when there is no remote-tracking ref)
#   PHP_BIN   PHP binary used for `php -l` (default: php). Must be PHP 7.4 — the
#             version the plugin header declares and release-plugin.yml builds
#             on — or the gate refuses to run. Linting on 7.4 is what blocks
#             PHP 8-only *syntax* (readonly, enums, ?->, constructor promotion,
#             attributes) from reaching a release that claims 7.4 support; on a
#             PHP 8 parser the same lint passes and proves nothing.
#   ALLOW_PHP_VERSION_MISMATCH=1
#             Run the rest of the gate on a non-7.4 PHP anyway, accepting that
#             the lint step is then not authoritative.
#
# `php -l` is a parser check only — it does not verify 8.x runtime
# compatibility. That is a separate concern and extends this file.
#
# This is a deliberately weak gate: the repo has no test suite, so it catches
# syntax and type errors and zero logic errors.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

PHP_BIN="${PHP_BIN:-php}"
REQUIRED_PHP="7.4"

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
warn() { printf '\033[33mwarning: %s\033[0m\n' "$1" >&2; }
die() { printf '\033[31merror: %s\033[0m\n' "$1" >&2; exit 1; }

# --- PHP binary ------------------------------------------------------------
# Checked up front: a wrong PHP_BIN should not cost a full install and build
# before it is reported.

command -v "$PHP_BIN" >/dev/null 2>&1 || die "PHP_BIN ($PHP_BIN) not found"

php_version="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if [ "$php_version" != "$REQUIRED_PHP" ]; then
	# Not a warning: on a PHP 8 parser the lint step accepts every PHP 8-only
	# construct and the gate goes green, which is worse than not running it.
	printf '\033[31merror: PHP_BIN (%s) is PHP %s, not %s.\033[0m\n' \
		"$PHP_BIN" "$php_version" "$REQUIRED_PHP" >&2
	cat >&2 <<-EOF
		The plugin header declares 'Requires PHP: $REQUIRED_PHP'. A newer parser accepts
		PHP 8-only syntax (readonly, enums, ?->, constructor promotion, attributes)
		that would fatal on the declared minimum, so linting on it proves nothing.

		Point PHP_BIN at a PHP $REQUIRED_PHP binary. Without one installed, a container
		works and keeps this runnable by hand:

		  printf '#!/bin/sh\nexec docker run --rm -v "\$PWD":/app -w /app php:$REQUIRED_PHP-cli php "\$@"\n' > /tmp/php$REQUIRED_PHP
		  chmod +x /tmp/php$REQUIRED_PHP
		  PHP_BIN=/tmp/php$REQUIRED_PHP ./scripts/gate.sh

		To run the rest of the gate anyway, knowing the lint is not authoritative:
		  ALLOW_PHP_VERSION_MISMATCH=1 ./scripts/gate.sh
	EOF
	[ "${ALLOW_PHP_VERSION_MISMATCH:-}" = "1" ] || exit 1
	warn "ALLOW_PHP_VERSION_MISMATCH=1 — continuing on PHP $php_version."
fi

# --- package manager -------------------------------------------------------
# Detected from the lockfile in the checkout, never hardcoded, so a migration
# between package managers needs no edit here.

if [ -f package-lock.json ]; then
	PKG_MANAGER="npm"
	INSTALL_CMD=(npm ci)
	BUILD_CMD=(npm run build)
elif [ -f yarn.lock ]; then
	PKG_MANAGER="yarn"
	INSTALL_CMD=(yarn install --frozen-lockfile)
	BUILD_CMD=(yarn build)
else
	die "no lockfile found — cannot determine the package manager"
fi

step "Installing dependencies ($PKG_MANAGER)"
"${INSTALL_CMD[@]}"

step "Building assets ($PKG_MANAGER)"
"${BUILD_CMD[@]}"

step "Type-checking"
npx --no-install tsc --noEmit

# --- PHP lint --------------------------------------------------------------

step "Linting changed PHP files (PHP $php_version)"

# Resolve the base ref, preferring the remote-tracking branch so a local `main`
# left behind does not shrink the changed-file set.
base_ref="${BASE_REF:-}"
if [ -z "$base_ref" ]; then
	for candidate in origin/main main; do
		if git rev-parse --verify --quiet "$candidate" >/dev/null; then
			base_ref="$candidate"
			break
		fi
	done
fi
[ -n "$base_ref" ] || die "no base ref found — set BASE_REF"
git rev-parse --verify --quiet "$base_ref" >/dev/null || die "base ref '$base_ref' does not exist"

# Compare against the merge base so commits landed on the base ref since this
# branch was cut do not count as changes, and diff the *working tree* so
# uncommitted edits are gated too. Untracked files are added on top.
merge_base="$(git merge-base "$base_ref" HEAD)"

changed_files="$(
	git diff --name-only --diff-filter=d "$merge_base" --
	git ls-files --others --exclude-standard
)"

# `|| true` covers grep's exit 1 on no match only — a failing git call above has
# already aborted the script rather than reading as "nothing changed".
changed_php="$(printf '%s\n' "$changed_files" | grep -E '\.php$' | sort -u || true)"

if [ -z "$changed_php" ]; then
	echo "No PHP files changed against $base_ref — nothing to lint."
else
	# Read line by line rather than piping to xargs: paths may contain spaces,
	# and BSD xargs splits on them.
	while IFS= read -r file; do
		echo "  $file"
		"$PHP_BIN" -l "$file"
	done <<< "$changed_php"
fi

printf '\n\033[32m==> Gate passed\033[0m\n'
