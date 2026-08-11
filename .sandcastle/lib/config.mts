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
