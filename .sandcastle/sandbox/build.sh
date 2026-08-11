#!/usr/bin/env bash
#
# build.sh — build the sandbox image the AFK harness runs implementers inside.
#
# Reads the Node version from `.nvmrc` and passes it to the image as a build
# argument, so the image follows the plugin's pin with no edit to the
# Dockerfile. After a change to `.nvmrc`, rebuilding is the whole procedure.
#
#   .sandcastle/sandbox/build.sh
#   IMAGE_NAME=my-sandbox .sandcastle/sandbox/build.sh --no-cache
#
# Any extra arguments are passed through to `docker build`.
#
# Environment:
#   IMAGE_NAME  image name to tag (default: nextjs-revalidate-sandbox)
#   DOCKER_BIN  container CLI to use (default: docker)

set -euo pipefail

SANDBOX_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SANDBOX_DIR/../.." && pwd)"

IMAGE_NAME="${IMAGE_NAME:-nextjs-revalidate-sandbox}"
DOCKER_BIN="${DOCKER_BIN:-docker}"

die() { printf '\033[31merror: %s\033[0m\n' "$1" >&2; exit 1; }

command -v "$DOCKER_BIN" >/dev/null 2>&1 || die "$DOCKER_BIN not found"
[ -f "$REPO_ROOT/.nvmrc" ] || die "no .nvmrc at $REPO_ROOT — cannot determine the Node version"

# `.nvmrc` may carry a leading `v`, a trailing newline, or an alias such as
# `lts/*`. Only a numeric version maps to a `node:<version>` image tag.
node_version="$(tr -d '[:space:]' < "$REPO_ROOT/.nvmrc")"
node_version="${node_version#v}"
[ -n "$node_version" ] || die ".nvmrc is empty"
case "$node_version" in
	[0-9]*) ;;
	*) die ".nvmrc holds '$node_version', which is not a version number the image can be built from" ;;
esac

# Tag both the version and `latest`: the version tag makes it obvious which
# Node an image carries, `latest` is what the harness refers to.
printf '\n\033[1m==> Building %s on Node %s\033[0m\n' "$IMAGE_NAME" "$node_version"

# The build context is this directory, not the repo: nothing is copied into the
# image — the checkout is mounted at run time.
"$DOCKER_BIN" build \
	--build-arg "NODE_VERSION=$node_version" \
	--tag "$IMAGE_NAME:node$node_version" \
	--tag "$IMAGE_NAME:latest" \
	"$@" \
	"$SANDBOX_DIR"

printf '\n\033[32m==> Built %s:node%s (also tagged :latest)\033[0m\n' "$IMAGE_NAME" "$node_version"
