/**
 * The checks that used to be a five-command ritual in the runbook.
 *
 * A pre-flight an operator has to remember is a bug, not documentation: it is
 * skipped exactly on the night it would have mattered. Every pass runs these
 * itself, before it touches a branch.
 *
 * Two things are checked, and they guard the same invariant — the primary
 * checkout is never checked out of its own branch by anything here:
 *
 * - The checkout must not be sitting on a `sandcastle/*` branch. It would be
 *   there because an earlier pass, or a person, left it there; either way the
 *   freshness step cannot move a branch that is checked out, and the run would
 *   fail halfway through instead of before it started.
 * - Worktrees under `.sandcastle/worktrees/` left behind by a killed run hold a
 *   work branch checked out and block the same step. They are scratch by
 *   construction, so healing them is removing them.
 */
import { realpathSync, rmSync } from 'node:fs';
import { BRANCH_PREFIX } from './config.mts';
import { currentBranch, git, gitTry, worktreePathFor } from './git.mts';

export type PreflightResult = {
	/** Branch the primary checkout is on, to be compared again at the end. */
	startedOn: string;
	/**
	 * `git status --porcelain` as found. Snapshotted rather than asserted clean:
	 * the invariant is that the harness leaves the checkout exactly as it found
	 * it, which has to hold whether or not the operator has local edits.
	 */
	startedDirty: string;
	/** Scratch worktrees removed on the way in. Always empty on a dry run. */
	healed: string[];
};

/**
 * Worktrees the harness owns: anything under `.sandcastle/worktrees/`.
 *
 * Compared against the resolved path as well as the given one — git reports
 * worktree paths with symlinks resolved, and on macOS a checkout under `/tmp`
 * is really under `/private/tmp`.
 */
export function isScratchWorktree(cwd: string, path: string): boolean {
	const roots = new Set([worktreePathFor(cwd, '')]);
	try {
		roots.add(worktreePathFor(realpathSync(cwd), ''));
	} catch {
		// The checkout is always there; a failure here just leaves the raw root.
	}

	return [...roots].some((root) => path.startsWith(`${root}/`));
}

/**
 * Remove every scratch worktree the last run left behind, and prune the
 * administrative entries of ones whose directory is already gone.
 */
export function healWorktrees(cwd: string, log: (message: string) => void): string[] {
	gitTry(cwd, ['worktree', 'prune']);

	const paths: string[] = [];
	for (const line of git(cwd, ['worktree', 'list', '--porcelain']).split('\n')) {
		if (!line.startsWith('worktree ')) continue;
		const path = line.slice('worktree '.length);
		if (isScratchWorktree(cwd, path)) paths.push(path);
	}

	for (const path of paths) {
		log(`removing worktree left behind by an earlier run: ${path}`);
		gitTry(cwd, ['worktree', 'remove', '--force', path]);
		rmSync(path, { recursive: true, force: true });
	}

	gitTry(cwd, ['worktree', 'prune']);

	return paths;
}

/**
 * The one refusal. A checkout parked on a work branch is a state a person has to
 * resolve — moving it would be exactly the thing the harness promises never to
 * do.
 */
export function assertNotOnWorkBranch(branch: string): void {
	if (branch.startsWith(BRANCH_PREFIX)) {
		throw new Error(
			`the checkout is on ${branch}. The harness never moves the primary checkout, and it cannot bring a ` +
				`branch up to date while it is checked out here. Switch back to your own branch and run again.`
		);
	}
}

/**
 * Run every check, in order, and snapshot what the run has to restore.
 *
 * `heal` is off for a dry run, which promises to touch nothing: removing a
 * worktree is a real change to the repository, and a dry run that made one
 * before printing "nothing was touched" would be lying about the smaller half
 * of what it did.
 */
export function preflight(
	cwd: string,
	log: (message: string) => void,
	{ heal = true }: { heal?: boolean } = {}
): PreflightResult {
	const startedOn = currentBranch(cwd);
	assertNotOnWorkBranch(startedOn);

	const healed = heal ? healWorktrees(cwd, log) : [];

	return { startedOn, startedDirty: git(cwd, ['status', '--porcelain']), healed };
}

/**
 * The same invariant, checked again at the end of the pass. Reported rather than
 * thrown: by this point branches exist and PRs may be open, and the operator
 * needs the run's own report before the failure.
 */
export function checkoutMoved(cwd: string, before: PreflightResult): string | null {
	const endedOn = currentBranch(cwd);
	const endedDirty = git(cwd, ['status', '--porcelain']);

	if (endedOn === before.startedOn && endedDirty === before.startedDirty) return null;

	return (
		`primary checkout was modified — started on ${before.startedOn}, now on ${endedOn}` +
		(endedDirty === before.startedDirty ? '' : '; working tree differs from how it was found')
	);
}
