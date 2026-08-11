/** Label an issue must carry to be considered by the harness. */
export const READY_LABEL = 'ready-for-agent';

/** Label the finalize phase swaps in once a PR is open (not used by the dry run). */
export const HANDOFF_LABEL = 'ready-for-human';

/** Every branch the harness may ever push is under this prefix. */
export const BRANCH_PREFIX = 'sandcastle/';

/** Base branch for standalone work. */
export const BASE_BRANCH = 'main';

/**
 * Deterministic work-branch name — never a title slug, which cannot be
 * recomputed after a retitle, and which would break the re-pick guard.
 */
export function workBranchForIssue(issueNumber: number): string {
	return `${BRANCH_PREFIX}issue-${issueNumber}`;
}

/** Deterministic epic-branch name. Epic machinery itself is out of scope here. */
export function epicBranchForIssue(issueNumber: number): string {
	return `${BRANCH_PREFIX}epic-${issueNumber}`;
}

/* -- The implement phase ---------------------------------------------------
 *
 * The four numbers below the divider are the whole cost envelope of a real
 * pass; the rest names what a run reaches for. All of it lives here rather
 * than inline so an operator can see and change it in one place.
 */

/**
 * Containers in flight at once. Roughly 1 GB each against the host's 11.67 GiB,
 * and a realistic batch is 2–4 items, so three keeps the batch moving without
 * putting the host under memory pressure.
 */
export const CONCURRENCY = 3;

/** Agent invocations per item before the harness gives up on it. */
export const MAX_ITERATIONS = 30;

/** Seconds of no output from the agent before its iteration is failed. */
export const IDLE_TIMEOUT_SECONDS = 600;

/**
 * The implementer model.
 *
 * With a syntax-only gate and no PR CI, the implementer's own judgement is the
 * only real quality control this pipeline has, and the backlog is small enough
 * that the cost of the strongest model is bounded. Downgrade this and the gate
 * does not get any stricter.
 */
export const IMPLEMENTER_MODEL = 'claude-opus-5';

/**
 * The model that writes an epic PR's body — the one agent in the finalize
 * phase. Same tier as the implementer: it is summarising work the implementer
 * did, and a weaker reading of the branch would produce a body a reviewer
 * cannot trust.
 */
export const PR_BODY_MODEL = 'claude-opus-5';

/**
 * How long that one call gets. Short on purpose: it writes a few hundred words
 * from facts it is handed, and a hung call must not sit between a green branch
 * and the PR that hands it to a human — there is a deterministic body waiting.
 */
export const PR_BODY_TIMEOUT_MS = 180_000;

/** What the agent emits to end its iteration loop early. */
export const COMPLETION_SIGNAL = '<promise>COMPLETE</promise>';

/** Image the implementers run inside; built by `.sandcastle/sandbox/build.sh`. */
export const SANDBOX_IMAGE = 'nextjs-revalidate-sandbox:latest';

/**
 * The gate, as run *by the harness* inside the sandbox. Tracked in the repo
 * rather than seeded into the worktree, so the freshness rule keeps every work
 * branch's copy current on its own.
 */
export const GATE_COMMAND = './scripts/gate.sh';
