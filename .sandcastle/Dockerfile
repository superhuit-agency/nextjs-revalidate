# Sandbox image for the AFK agent harness.
#
# Carries the two runtimes the gate needs: Node, for `npm run typecheck`, and
# PHP 7.4 for `npm run lint:php`. On top of those it carries the Claude Code
# CLI, which is what sandcastle invokes inside the container, and an `agent`
# user whose UID/GID match the host's so the bind-mounted worktree needs no
# runtime chown.
#
# What is deliberately NOT here: `gh`, and any credential for it. The implement
# phase never pushes and never opens a PR — an agent that cannot reach GitHub
# cannot do either by accident. Auth to Claude arrives as an environment
# variable at `docker run` time, from the gitignored `.sandcastle/.env`; nothing
# secret is baked into the image.
#
# The Node version is pinned below and has to be kept in step with `.nvmrc` by
# hand — one line in one place, rebuilt when it changes. Threading it in from
# `.nvmrc` needed a build script of our own, which cost more than the pin does.
#
# The harness itself never enters this container — it runs on the host.
#
# Nothing builds this by hand: `npm run sandcastle` builds it through
# `sandcastle docker build-image` when it is missing, which is also what bakes
# in the host UID/GID the two ARGs below expect.

FROM node:24-bookworm-slim

# PHP 7.4 is long past upstream EOL, so it is not in Debian bookworm. Sury is
# where maintained 7.4 packages live, and it publishes amd64 and arm64.
ARG SURY_SUITE=bookworm
ARG PHP_VERSION=7.4

# git: the gate resolves its changed-file set with `git merge-base` / `git diff`.
# ca-certificates, curl, gnupg: fetching the Sury key and repository.
RUN set -eux; \
	apt-get update; \
	DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
		ca-certificates \
		curl \
		git \
		gnupg; \
	curl -sSLo /usr/share/keyrings/sury-php.gpg https://packages.sury.org/php/apt.gpg; \
	echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${SURY_SUITE} main" \
		> /etc/apt/sources.list.d/sury-php.list; \
	apt-get update; \
	DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
		"php${PHP_VERSION}-cli"; \
	apt-get purge -y --auto-remove gnupg; \
	rm -rf /var/lib/apt/lists/*

# The gate reads PHP_BIN and refuses to run on anything but 7.4. Pin it to the
# versioned binary rather than the `php` alternative, so installing another PHP
# later cannot silently redirect the lint step to a newer parser.
ENV PHP_BIN=/usr/bin/php${PHP_VERSION}

# Fail the build rather than ship an image whose gate would refuse to run, or —
# worse — lint on a parser that accepts PHP 8-only syntax.
RUN set -eux; \
	actual="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"; \
	[ "$actual" = "${PHP_VERSION}" ] || { echo "PHP_BIN is PHP $actual, expected ${PHP_VERSION}" >&2; exit 1; }; \
	node --version; \
	npm --version

# --- the agent user --------------------------------------------------------
#
# sandcastle's docker provider runs the container as `--user <uid>:<gid>` and
# refuses to start when the image's own UID differs, so the UID has to be baked
# in at build time. `sandcastle docker build-image` passes the host user's,
# which is also what makes the bind-mounted worktree writable without a chown —
# and why an image built by one operator is not reusable by another.
#
# The base image already has a `node` user; renaming it is cheaper than adding
# another, and `-o` allows a GID that is already taken (macOS `staff` is 20,
# which is `dialout` on Debian).
ARG AGENT_UID=1000
ARG AGENT_GID=1000
RUN set -eux; \
	groupmod -o -g "${AGENT_GID}" node; \
	usermod -o -u "${AGENT_UID}" -g "${AGENT_GID}" -d /home/agent -m -l agent node

USER ${AGENT_UID}:${AGENT_GID}
ENV HOME=/home/agent
WORKDIR /home/agent

# Claude Code, installed as the agent user so it lands in the home directory the
# container actually runs with. Installed last: it is the layer that changes
# most often, and everything above it is worth caching.
RUN curl -fsSL https://claude.ai/install.sh | bash
ENV PATH=/home/agent/.local/bin:$PATH
RUN claude --version

# sandcastle bind-mounts the work branch's worktree at /home/agent/workspace and
# overrides the working directory to it at container start. Nothing is copied in
# at build time, so ordinary code changes need no rebuild.
#
# The container is started with no command — it has to stay alive for sandcastle
# to exec into, which is what this entrypoint is for.
ENTRYPOINT ["sleep", "infinity"]
