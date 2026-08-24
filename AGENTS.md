# AGENTS.md

Guidance for coding agents working in this repo.

## Agent skills

### Issue tracker

Issues live as GitHub issues in `superhuit-agency/nextjs-revalidate`, managed via the `gh` CLI. External PRs are **not** a triage surface. See `docs/agents/issue-tracker.md`.

### Triage labels

Default vocabulary — `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context — one `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.

### Manual tests

The checks only a person at a browser can perform, split across two files that
partition them — `docs/manual-tests.md` (the core pass, single site) and
`docs/manual-tests-extended.md` (everything else). No step is in both. Committed
**unchecked**. See `docs/agents/manual-tests.md` for what obliges you to update
them, and what obliges you to shrink them.
