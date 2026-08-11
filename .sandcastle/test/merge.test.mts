/**
 * The merge phase.
 *
 * Two halves. The decisions — what closes an issue, what does not, what fails
 * the run — are asserted against a fake, because none of them are about git.
 * The squash itself is asserted against a real scratch repository with a real
 * origin, because all of it is: a mocked git could not show that a conflict
 * leaves the epic branch untouched.
 */
import assert from 'node:assert/strict';
import { after, describe, it } from 'node:test';
import {
	childClosingComment,
	childrenToMerge,
	closeIssueArgs,
	isMergeFailure,
	mergeChild,
	mergeChildren,
	realSquash,
	squashCommitMessage,
} from '../lib/merge.mts';
import type { MergeDeps, SquashResult } from '../lib/merge.mts';
import type { PlanItem } from '../lib/plan.mts';
import { commitOnBranch, headOf, makeScratch, run } from './helpers.mts';
import type { Scratch } from './helpers.mts';

const CHILD: PlanItem = {
	issue: 30,
	title: 'Do the sub-thing',
	role: 'child',
	workBranch: 'sandcastle/issue-30',
	mergeInto: 'sandcastle/epic-29',
	base: 'sandcastle/epic-29',
};

type FakeOptions = {
	squash?: SquashResult;
	squashThrows?: string;
	pushThrows?: string;
	closeThrows?: string;
};

type Recorded = { squashed: string[]; pushed: string[]; closed: { issue: number; comment: string }[] };

function fakeDeps(options: FakeOptions = {}): { deps: MergeDeps; recorded: Recorded } {
	const recorded: Recorded = { squashed: [], pushed: [], closed: [] };

	return {
		recorded,
		deps: {
			log: () => {},
			squash: (epic, child) => {
				if (options.squashThrows) throw new Error(options.squashThrows);
				recorded.squashed.push(`${child} → ${epic}`);
				return options.squash ?? { status: 'committed', sha: 'f'.repeat(40) };
			},
			push: (branch) => {
				if (options.pushThrows) throw new Error(options.pushThrows);
				recorded.pushed.push(branch);
			},
			closeIssue: (issue, comment) => {
				if (options.closeThrows) throw new Error(options.closeThrows);
				recorded.closed.push({ issue, comment });
			},
		},
	};
}

describe('childrenToMerge', () => {
	it('takes green children and nothing else', () => {
		const items: PlanItem[] = [
			{ ...CHILD, issue: 30 },
			{ ...CHILD, issue: 31 },
			{ ...CHILD, issue: 29, role: 'epic', workBranch: 'sandcastle/epic-29', mergeInto: null },
			{ ...CHILD, issue: 12, role: 'standalone', mergeInto: null },
		];

		assert.deepEqual(
			childrenToMerge(items, new Set([30, 29, 12])).map((item) => item.issue),
			[30],
			'an epic never merges into anything, and #31 was not green'
		);
	});
});

describe('mergeChild', () => {
	it('squashes, sends the epic branch to origin, then closes the child', () => {
		const { deps, recorded } = fakeDeps();
		const outcome = mergeChild(deps, CHILD);

		assert.equal(outcome.status, 'merged');
		assert.equal(outcome.closed, true);
		assert.deepEqual(recorded.squashed, ['sandcastle/issue-30 → sandcastle/epic-29']);
		assert.deepEqual(recorded.pushed, ['sandcastle/epic-29']);
		assert.equal(recorded.closed[0]?.issue, 30);
	});

	it('names the epic branch in the closing comment', () => {
		const { deps, recorded } = fakeDeps();
		mergeChild(deps, CHILD);

		assert.match(recorded.closed[0]?.comment ?? '', /sandcastle\/epic-29/);
	});

	it('leaves the child open and pushes nothing when the squash conflicts', () => {
		const { deps, recorded } = fakeDeps({ squash: { status: 'conflicted', error: 'CONFLICT (content)' } });
		const outcome = mergeChild(deps, CHILD);

		assert.equal(outcome.status, 'conflicted');
		assert.equal(outcome.closed, false);
		assert.deepEqual(recorded.pushed, [], 'nothing changed, so nothing reaches origin');
		assert.deepEqual(recorded.closed, []);
		assert.ok(isMergeFailure(outcome));
	});

	it('does not close a child whose work the epic branch already carries', () => {
		const { deps, recorded } = fakeDeps({ squash: { status: 'nothing-to-merge' } });
		const outcome = mergeChild(deps, CHILD);

		assert.equal(outcome.status, 'nothing-to-merge');
		assert.equal(outcome.closed, false, 'closing on an empty merge would claim work that may never have happened');
		assert.deepEqual(recorded.closed, []);
	});

	it('fails loudly when the epic branch cannot reach origin, and closes nothing', () => {
		const { deps, recorded } = fakeDeps({ pushThrows: 'rejected: non-fast-forward' });
		const outcome = mergeChild(deps, CHILD);

		assert.equal(outcome.status, 'error');
		assert.equal(outcome.closed, false);
		assert.match(outcome.error ?? '', /non-fast-forward/);
		assert.deepEqual(recorded.closed, [], 'a child closed over an unpushed merge would lose its only trail');
		assert.ok(isMergeFailure(outcome));
	});

	it('reports a merge whose issue could not be closed, and fails the run', () => {
		const { deps } = fakeDeps({ closeThrows: 'gh: not found' });
		const outcome = mergeChild(deps, CHILD);

		assert.equal(outcome.status, 'merged', 'the code did reach the epic branch');
		assert.equal(outcome.closed, false);
		assert.ok(isMergeFailure(outcome));
	});

	it('turns a thrown squash into an outcome rather than taking the batch down', () => {
		const { deps } = fakeDeps({ squashThrows: 'worktree add failed' });
		const outcomes = mergeChildren(deps, [CHILD, { ...CHILD, issue: 31, workBranch: 'sandcastle/issue-31' }]);

		assert.equal(outcomes[0]?.status, 'error');
		assert.equal(outcomes.length, 2, 'the rest of the batch still runs');
	});

	it('refuses anything that is not a child with an epic branch', () => {
		const { deps } = fakeDeps();
		const outcome = mergeChild(deps, { ...CHILD, role: 'standalone', mergeInto: null });

		assert.equal(outcome.status, 'error');
	});
});

describe('the messages it writes', () => {
	it('puts the child number in the squash commit subject', () => {
		assert.match(squashCommitMessage(CHILD), /^chore: 🔀 squash #30 — Do the sub-thing$/m);
	});

	it('never puts a closing keyword in either message — the merge does the closing', () => {
		const both = `${squashCommitMessage(CHILD)}\n${childClosingComment(CHILD, 'a'.repeat(40))}`;
		assert.doesNotMatch(both, /\b(closes|fixes|resolves)\s+#\d+/i);
	});

	it('closes the issue and comments in one call', () => {
		const args = closeIssueArgs('owner/name', 30, 'body');
		assert.deepEqual(args, ['issue', 'close', '30', '--repo', 'owner/name', '--comment', 'body']);
	});
});

/* -- The squash itself, against a real repository -------------------------- */

const scratches: Scratch[] = [];

function scratch(): Scratch {
	const created = makeScratch();
	scratches.push(created);
	return created;
}

after(() => {
	for (const created of scratches) created.cleanup();
});

/** An epic branch with one commit of its own, and a child cut from it. */
function epicAndChild(repo: string): void {
	run(repo, ['branch', 'sandcastle/epic-29', 'main']);
	commitOnBranch(repo, 'sandcastle/epic-29', 'epic.txt', 'epic work\n', 'the epic own work');
	run(repo, ['branch', 'sandcastle/issue-30', 'sandcastle/epic-29']);
}

describe('realSquash — against a real repository', () => {
	it('lands the child as exactly one commit on the epic branch', () => {
		const { repo } = scratch();
		epicAndChild(repo);
		commitOnBranch(repo, 'sandcastle/issue-30', 'child.txt', 'one\n', 'first');
		commitOnBranch(repo, 'sandcastle/issue-30', 'child.txt', 'two\n', 'second');

		const before = headOf(repo, 'sandcastle/epic-29');
		const result = realSquash(repo)('sandcastle/epic-29', 'sandcastle/issue-30', 'chore: 🔀 squash #30');

		assert.equal(result.status, 'committed');
		assert.equal(
			run(repo, ['rev-list', '--count', `${before}..sandcastle/epic-29`]),
			'1',
			'two child commits, one squash commit'
		);
		assert.equal(run(repo, ['show', 'sandcastle/epic-29:child.txt']), 'two');
		assert.equal(run(repo, ['log', '-1', '--format=%s', 'sandcastle/epic-29']), 'chore: 🔀 squash #30');
	});

	it('leaves the epic branch exactly as it was on a conflict', () => {
		const { repo } = scratch();
		epicAndChild(repo);
		commitOnBranch(repo, 'sandcastle/issue-30', 'contested.txt', 'child\n', 'child writes it');
		commitOnBranch(repo, 'sandcastle/epic-29', 'contested.txt', 'epic\n', 'epic writes it too');

		const before = headOf(repo, 'sandcastle/epic-29');
		const result = realSquash(repo)('sandcastle/epic-29', 'sandcastle/issue-30', 'chore: 🔀 squash #30');

		assert.equal(result.status, 'conflicted');
		assert.equal(headOf(repo, 'sandcastle/epic-29'), before, 'the branch must be byte-identical');
		assert.equal(run(repo, ['show', 'sandcastle/epic-29:contested.txt']), 'epic');
	});

	it('reports nothing-to-merge rather than an empty commit', () => {
		const { repo } = scratch();
		epicAndChild(repo);

		const before = headOf(repo, 'sandcastle/epic-29');
		const result = realSquash(repo)('sandcastle/epic-29', 'sandcastle/issue-30', 'chore: 🔀 squash #30');

		assert.equal(result.status, 'nothing-to-merge');
		assert.equal(headOf(repo, 'sandcastle/epic-29'), before);
	});

	it('never checks the primary checkout out of its own branch', () => {
		const { repo } = scratch();
		epicAndChild(repo);
		commitOnBranch(repo, 'sandcastle/issue-30', 'child.txt', 'one\n', 'first');

		const on = run(repo, ['rev-parse', '--abbrev-ref', 'HEAD']);
		realSquash(repo)('sandcastle/epic-29', 'sandcastle/issue-30', 'chore: 🔀 squash #30');

		assert.equal(run(repo, ['rev-parse', '--abbrev-ref', 'HEAD']), on);
		assert.equal(run(repo, ['status', '--porcelain']), '');
		assert.equal(run(repo, ['worktree', 'list']).split('\n').length, 1, 'the scratch worktree is removed');
	});
});
