# Actions are pinned to a commit, and Dependabot moves the pins

Raised while reviewing #151, implemented in #154.

The workflows named their actions by tag or branch: `@v4`, `@v2`, `@latest`,
`@main`. The owner of the action's repository can move any of those to different
code, and anyone who takes over their account can too. The next run uses that
code, and no diff in this repository shows it.

The release job is where this matters. It holds `contents: write`, and
`easingthemes/ssh-deploy@main` receives `RELEASE_BELT_PUBLISH_PRIVATE_KEY`. A
push to that repository's `main` decided what code ran with our SSH key.

## Every `uses:` names a commit SHA

Each action is referenced by its full 40-character commit SHA, followed by a
`# vX.Y.Z` comment naming the release that SHA resolves to. A commit SHA cannot
be moved. The comment is there for people and for Dependabot, which rewrites it
when it bumps the SHA.

The one exception is `marvinpinto/action-automatic-releases`, whose comment says
`# latest`. That was the tag the workflow ran, and it resolves to three rebuilds
of the action's `dist/` past v1.2.1, so a `v1.2.1` pin would have run older code
than before.

`release-plugin.yml` moved from v3 to v4 of `actions/checkout`, `actions/cache`
and `actions/setup-node` at the same time, matching `ci.yml`. The v3 releases
target deprecated Node runtimes.

## A pin that doesn't freeze the code is not kept

`montudor/action-zip` is a Docker action whose Dockerfile says
`FROM alpine:latest` and `RUN apk add zip`. Pinning its SHA froze the Dockerfile,
not what ran: every release built a fresh image from whatever Alpine and its zip
package were that day. The runner image already ships `zip`, so the step is now
a plain `run: zip`. Any other action that builds from a floating base image is
replaced in the same way, or pinned by image digest. A SHA pin alone isn't enough
for such an action.

## Dependabot keeps the pins current

A pin that nobody bumps just freezes the action, security fixes included.
`.github/dependabot.yml` watches the `github-actions` ecosystem weekly and groups
all actions into one pull request, which CI checks like any other.

> Widened since. The same file now also watches npm and Composer, and records
> why each pin Dependabot must leave alone is pinned
> ([ADR 0036](0036-dependabot-fixes-every-vulnerability-and-bumps-only-what-ships.md)).

## Consequences

**A bump is a reviewed pull request.** An action's new release no longer reaches
the release job on its own. Someone merges the Dependabot pull request, and the
diff shows the SHA change.

**`v1.x` is not watched.** Dependabot updates only the default branch unless a
`target-branch` is set. The maintenance line keeps the pins it was cut with,
which suits a branch that only takes security fixes. When an action's own
security fix matters there, backport the bump by hand.

**An archived action's pin never moves.** `marvinpinto/action-automatic-releases`
is archived, so Dependabot has nothing to propose, security fixes included. It
receives the job's `GITHUB_TOKEN`. Replacing it with `gh release create` would
remove the last action nobody maintains.

**A pinned action can still fetch code at run time.** `shivammathur/setup-php`
downloads PHP and its tools, and `ssh-deploy` runs `rsync` from the runner. The
pin covers the action's own code, not everything it installs.
