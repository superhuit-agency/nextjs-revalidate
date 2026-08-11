/**
 * The merge phase — the only place in the harness that merges anything.
 *
 * A child's code has exactly one route onward: a squash-merge into its parent's
 * epic branch. It never gets a PR of its own into `main`, because a PR targets
 * the branch its head was cut from and a child is cut from the epic branch.
 *
 * Three things here are load-bearing:
 *
 * - **It needs a working tree, and it gets its own.** Squash-merging is one of
 *   the few operations git will not do against a bare ref, and the primary
 *   checkout is untouchable — so this runs in a throwaway worktree from
 *   `withWorktree()`, exactly like the freshness rules do.
 * - **A conflict leaves the epic branch exactly as it was.** The merge is
 *   undone, nothing is pushed, the child issue stays open, and the item is
 *   reported. The next cycle re-cuts the child from a moved epic branch and
 *   tries again.
 * - **The child issue closes on a successful squash-merge, and only then.**
 *   This is the one issue the harness ever closes, and it is consistent with
 *   "issues close when their code reaches `main`, never earlier": a child has
 *   no other route to `main` than the epic branch, so reaching that branch is
 *   as far as a child's code ever gets on its own. The closing comment names
 *   the branch, so the trail from a closed child to the PR is one hop.
 */
import { messageOf } from './errors.mts';
import { git, gitTry, pushBranch, withWorktree } from './git.mts';
import { gh } from './gh.mts';
import type { PlanItem } from './plan.mts';

export type SquashResult =
	/** The squash landed as one commit on the epic branch. */
	| { status: 'committed'; sha: string }
	/** The epic branch already carries everything the child had. */
	| { status: 'nothing-to-merge' }
	/** Conflicted; the merge was undone and the epic branch is untouched. */
	| { status: 'conflicted'; error: string };

export type MergeOutcome = {
	issue: number;
	branch: string;
	epic: string;
	status: 'merged' | 'nothing-to-merge' | 'conflicted' | 'error';
	/** The squash commit on the epic branch, when there was one. */
	sha: string | null;
	/** Whether the child issue was closed. Only ever true after a squash-merge. */
	closed: boolean;
	error?: string;
};

export type MergeDeps = {
	squash: (epic: string, child: string, message: string) => SquashResult;
	push: (branch: string) => void;
	closeIssue: (issue: number, comment: string) => void;
	log: (message: string) => void;
};

/** The items a merge pass acts on: children the gate left green. */
export function childrenToMerge(items: readonly PlanItem[], green: ReadonlySet<number>): PlanItem[] {
	return items.filter((item) => item.role === 'child' && item.mergeInto !== null && green.has(item.issue));
}

/**
 * The squash commit's message.
 *
 * Conventional-commit *type* is deliberately `chore`: what the child did is
 * knowable only from its own commits, and inventing a `feat` over a docs change
 * would be worse than a flat, honest label. The narrative lives in the epic PR
 * body, which is written from the branch's real history.
 */
export function squashCommitMessage(item: PlanItem): string {
	return [
		`chore: 🔀 squash #${item.issue} — ${item.title}`,
		'',
		`Squash-merged \`${item.workBranch}\` into \`${item.mergeInto}\` by the AFK agent harness.`,
		'',
		`Part of #${item.issue}`,
	].join('\n');
}

/**
 * The comment left on the child as it closes. `Part of`, never a closing
 * keyword — the child is closed by this comment's own action, and a closing
 * keyword here would be a second, redundant claim on it.
 */
export function childClosingComment(item: PlanItem, sha: string): string {
	return [
		`🔀 Squash-merged into \`${item.mergeInto}\` as \`${sha.slice(0, 7)}\`.`,
		'',
		`This issue closes here rather than on a merge into \`main\`: a sub-issue's code reaches \`main\` only through`,
		`its epic branch, and \`${item.mergeInto}\` is that branch. The epic's own PR is what a human merges, and`,
		'closing it closes the epic.',
		'',
		`Implemented on \`${item.workBranch}\` with \`./scripts/gate.sh\` green.`,
	].join('\n');
}

/**
 * Squash-merge one child into its epic branch and close it.
 *
 * Never throws: a failed item becomes an outcome so the rest of the batch
 * still merges.
 */
export function mergeChild(deps: MergeDeps, item: PlanItem): MergeOutcome {
	const epic = item.mergeInto;
	const base: Pick<MergeOutcome, 'issue' | 'branch' | 'epic'> = {
		issue: item.issue,
		branch: item.workBranch,
		epic: epic ?? '',
	};

	if (item.role !== 'child' || epic === null) {
		return { ...base, status: 'error', sha: null, closed: false, error: 'not a child with an epic branch to merge into' };
	}

	let squashed: SquashResult;
	try {
		deps.log(`#${item.issue}: squash-merging ${item.workBranch} into ${epic}`);
		squashed = deps.squash(epic, item.workBranch, squashCommitMessage(item));
	} catch (error) {
		return { ...base, status: 'error', sha: null, closed: false, error: messageOf(error) };
	}

	if (squashed.status === 'conflicted') {
		// The epic branch is byte-identical to how it was found and the child
		// stays open. Resolving this is a human's call, or the next cycle's.
		deps.log(`#${item.issue}: conflicted — ${epic} left untouched, issue stays open`);
		return { ...base, status: 'conflicted', sha: null, closed: false, error: squashed.error };
	}

	if (squashed.status === 'nothing-to-merge') {
		// Everything the child had is already on the epic branch. Not an error,
		// but not a merge either — the issue is left for a human to look at
		// rather than closed on the strength of an empty commit.
		deps.log(`#${item.issue}: ${epic} already carries this work; nothing to merge`);
		return { ...base, status: 'nothing-to-merge', sha: null, closed: false };
	}

	try {
		deps.push(epic);
	} catch (error) {
		// The squash is on the local epic branch and origin does not have it.
		// Loud, and never retried with force — see `lib/git.mts`.
		return {
			...base,
			status: 'error',
			sha: squashed.sha,
			closed: false,
			error: `squash-merged locally but could not update origin/${epic}: ${messageOf(error)}`,
		};
	}

	try {
		deps.closeIssue(item.issue, childClosingComment(item, squashed.sha));
	} catch (error) {
		// The code reached the epic branch; only the bookkeeping failed. Said
		// out loud, and it fails the run — a child left open will be re-picked
		// next cycle and re-merged into a branch that already has it.
		return {
			...base,
			status: 'merged',
			sha: squashed.sha,
			closed: false,
			error: `merged, but the issue could not be closed: ${messageOf(error)}`,
		};
	}

	deps.log(`#${item.issue}: merged as ${squashed.sha.slice(0, 7)} and closed`);
	return { ...base, status: 'merged', sha: squashed.sha, closed: true };
}

/** Merge a batch of children, in order. Sequential: several may share one epic branch. */
export function mergeChildren(deps: MergeDeps, items: readonly PlanItem[]): MergeOutcome[] {
	return items.map((item) => mergeChild(deps, item));
}

/** An outcome the operator has to act on. */
export function isMergeFailure(outcome: MergeOutcome): boolean {
	return outcome.status === 'error' || outcome.status === 'conflicted' || outcome.error !== undefined;
}

export function renderMergeOutcomes(outcomes: readonly MergeOutcome[]): string {
	const lines: string[] = [];

	for (const outcome of outcomes) {
		lines.push(`  #${outcome.issue} — ${outcome.status}`);
		lines.push(`      branch: ${outcome.branch} → ${outcome.epic}`);
		if (outcome.sha) lines.push(`      commit: ${outcome.sha.slice(0, 7)}`);
		lines.push(`      issue:  ${outcome.closed ? 'closed' : 'still open'}`);
		if (outcome.error) lines.push(`      error:  ${outcome.error}`);
	}

	return lines.join('\n');
}

/**
 * Squash-merge in a throwaway worktree on the epic branch.
 *
 * `git merge --squash` stages the child's net change without recording it as a
 * merge, so the commit that follows is what moves the epic branch. On a
 * conflict the index and working tree are reset — the branch ref was never
 * moved, so there is nothing else to undo.
 */
export function realSquash(cwd: string): MergeDeps['squash'] {
	return (epic, child, message) =>
		withWorktree(cwd, epic, (worktree): SquashResult => {
			const merged = gitTry(worktree, ['merge', '--squash', child]);
			if (!merged.ok) {
				gitTry(worktree, ['reset', '--hard', 'HEAD']);
				return { status: 'conflicted', error: merged.error };
			}

			// A clean cached diff means the squash staged nothing; committing
			// would fail with git's own "nothing to commit" three layers down.
			if (gitTry(worktree, ['diff', '--cached', '--quiet']).ok) {
				gitTry(worktree, ['reset', '--hard', 'HEAD']);
				return { status: 'nothing-to-merge' };
			}

			const committed = gitTry(worktree, ['commit', '--no-verify', '-m', message]);
			if (!committed.ok) {
				gitTry(worktree, ['reset', '--hard', 'HEAD']);
				return { status: 'conflicted', error: committed.error };
			}

			return { status: 'committed', sha: git(worktree, ['rev-parse', 'HEAD']) };
		});
}

export function realMergeDeps(cwd: string, repo: string, log: (message: string) => void): MergeDeps {
	return {
		log,
		squash: realSquash(cwd),
		push: (branch) => pushBranch(cwd, branch),
		closeIssue: (issue, comment) => {
			gh(closeIssueArgs(repo, issue, comment));
		},
	};
}

/**
 * Arguments for closing a child. One call, so the comment and the close cannot
 * come apart — a closed child with no comment leaves no trail to the branch
 * carrying its code.
 */
export function closeIssueArgs(repo: string, issue: number, comment: string): string[] {
	return ['issue', 'close', String(issue), '--repo', repo, '--comment', comment];
}
