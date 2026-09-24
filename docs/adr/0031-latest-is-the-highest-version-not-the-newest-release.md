# "Latest" is the highest version released, not the newest release

Decided while triaging #81, implemented in #150.

v2 changes the wire format to the front-end, so v1 sites stay on a **`v1.x`
maintenance branch** for security fixes. The branch is cut from the `v1.7.0` tag.
From then on there are two release lines, and the workflows assumed one.

## The workflows travel with the tag

GitHub runs a branch's pushes and pull requests with the `ci.yml` **in that
branch**, and a tag's release with the `release-plugin.yml` **in the tagged
commit**. `v1.x` therefore starts with whatever the `v1.7.0` tag contains, and
both changes below have to be on `main` before that tag exists. Otherwise the
first commit on `v1.x` would have to be a workflow fix, and it would run with no
CI at all.

## CI runs on `v1.x` exactly as on `main`

`ci.yml` lists `v1.x` beside `main` under both `pull_request` and `push`. Only the
`on:` block changes. The jobs stay the gate's scripts
([ADR 0009](0009-checks-run-on-pull-requests.md)), and the drift test in
`.sandcastle/test/implement.test.mts` still reads the file. On `main` the new
entry does nothing until the branch exists.

## "Latest" is decided by version, never by date or branch

GitHub marks a newly published release "Latest" by default. The release step was
`marvinpinto/action-automatic-releases`, which is archived and has no input for
it: it calls `createRelease` with `draft`, `prerelease` and `body` only. Left
alone, a `v1.7.1` tagged after `v2.0.0` would become the repository's latest
release, and anyone following that link would get v1.

The API's `make_latest: legacy` is no fix either. It decides by creation date
*and* semver, so the date alone could still hand "Latest" to a v1 patch.

So the release is split in two:

1. The action creates the release as a **draft**. A draft is never "Latest". It
   keeps the action's changelog, which picks the previous tag by semver, so a
   `v1.7.1` changelog runs from `v1.7.0` even after `v2.0.0` exists.
2. A `Publish release` step sorts every published, non-prerelease release's tag,
   plus this one, with `sort -V`. It publishes the draft with
   `gh release edit --draft=false --latest=<true|false>`, which is `true` only
   when this tag sorts highest.

The rule reads versions only, not branch names. `1.7.0` is "Latest" until `2.0.0`
ships, a `1.7.x` after that never is, and a new highest tag always is.

## Consequences

**A failed publish leaves a draft behind.** The release-belt upload comes after
the publish step, so it does not run either. Delete the draft before re-running
the job, so the tag does not end up with two.

**Version suffixes are not handled.** `sort -V` puts `v2.0.0-rc.1` *above*
`v2.0.0`. This repository tags plain `vX.Y.Z`, and the release step marks nothing
as a prerelease. If release candidates are ever tagged, this comparison and the
`prerelease: false` next to it need changing together.

**The release-belt upload is unchanged.** It names the zip after the tag, so both
lines already coexist there.

**Branch protection on `v1.x` is a repository setting.** It has to be applied by
hand once the branch exists, with the same required checks as `main`
(**Typecheck** and **PHP 7.4**). The workflow file cannot ask for it.
