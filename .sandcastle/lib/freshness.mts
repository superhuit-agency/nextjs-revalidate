/**
 * Branch freshness.
 *
 * sandcastle 0.12.0 ignores `baseBranch` when the branch already exists —
 * confirmed in its types. Branches are never re-cut from an updated parent, so
 * the host has to bring each work branch to a correct starting point itself,
 * before every execute:
 *
 * | Branch state                      | Action                                    |
 * | --------------------------------- | ----------------------------------------- |
 * | Zero commits over its base        | delete and re-cut                         |
 * | Has commits, *not* on origin      | rebase onto the base; on conflict, skip   |
 * | Has commits *and* on origin       | merge the base in — never rebase          |
 *
 * The last row is the one with teeth: rebasing a branch that exists on origin
 * would need a force-push to land, and that destroys an open PR's review
 * comments.
 *
 * All of it is deterministic code that runs before the container starts. No
 * agent decides any of this.
 */
import {
	commitsAhead,
	ensureLocalBranch,
	git,
	gitTry,
	localBranchExists,
	originBranchExists,
	withWorktree,
} from './git.mts';

export type FreshnessOutcome =
	/** No such branch anywhere — cut from the base. */
	| { action: 'created'; branch: string; base: string }
	/** Carried nothing over the base, so the old ref was worth nothing. */
	| { action: 're-cut'; branch: string; base: string }
	/** Local-only work, replayed onto the current base. */
	| { action: 'rebased'; branch: string; base: string }
	/** Published work; the base comes to it. */
	| { action: 'merged'; branch: string; base: string }
	/** Left exactly as it was found; the item sits this iteration out. */
	| { action: 'skipped'; branch: string; base: string; reason: string };

/**
 * Bring `branch` to a correct starting point over `base`.
 *
 * Assumes a `fetch()` has already run this pass and that `base` itself is
 * current — `originBranchExists()` reads remote-tracking refs, and a stale one
 * would route a published branch down the rebase path.
 *
 * Never throws on a conflict: a conflicting item is skipped for this
 * iteration, with no rebase or merge left in progress.
 */
export function ensureFreshBranch(cwd: string, branch: string, base: string): FreshnessOutcome {
	if (!localBranchExists(cwd, base)) {
		throw new Error(`base branch ${base} does not exist in ${cwd}`);
	}

	const onOrigin = originBranchExists(cwd, branch);

	if (!localBranchExists(cwd, branch) && !onOrigin) {
		git(cwd, ['branch', branch, base]);
		return { action: 'created', branch, base };
	}

	// Pick up anything origin has that the local ref does not — including a
	// squash-merge from an earlier cycle. Fast-forward only; a branch carrying
	// unpushed commits is left where it is and handled below on its own terms.
	ensureLocalBranch(cwd, branch);

	if (commitsAhead(cwd, branch, base) === 0) {
		// Nothing on it worth keeping, and the base has moved on. Deleting the
		// local ref cannot lose published work: origin still has its own.
		git(cwd, ['branch', '--delete', '--force', branch]);
		git(cwd, ['branch', branch, base]);
		return { action: 're-cut', branch, base };
	}

	return onOrigin ? mergeBaseIn(cwd, branch, base) : rebaseOntoBase(cwd, branch, base);
}

/**
 * Local-only work: replay it onto the current base. A conflict aborts the
 * rebase and skips the item — the branch is left byte-identical to how it was
 * found, with no rebase in progress.
 */
function rebaseOntoBase(cwd: string, branch: string, base: string): FreshnessOutcome {
	return withWorktree(cwd, branch, (worktree) => {
		const rebase = gitTry(worktree, ['rebase', base]);
		if (rebase.ok) return { action: 'rebased', branch, base };

		gitTry(worktree, ['rebase', '--abort']);
		return {
			action: 'skipped',
			branch,
			base,
			reason: `rebase onto ${base} conflicted; aborted and skipped this iteration`,
		};
	});
}

/**
 * Published work: bring the base to the branch instead. Never a rebase — the
 * force-push it would require would destroy an open PR's review comments.
 */
function mergeBaseIn(cwd: string, branch: string, base: string): FreshnessOutcome {
	return withWorktree(cwd, branch, (worktree) => {
		const merge = gitTry(worktree, ['merge', '--no-edit', base]);
		if (merge.ok) return { action: 'merged', branch, base };

		gitTry(worktree, ['merge', '--abort']);
		return {
			action: 'skipped',
			branch,
			base,
			reason: `merging ${base} conflicted; aborted and skipped this iteration`,
		};
	});
}
