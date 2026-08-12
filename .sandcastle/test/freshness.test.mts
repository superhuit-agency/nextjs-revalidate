import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { after, describe, it } from 'node:test';
import { commitOnBranch, headOf, makeScratch, run } from './helpers.mts';
import type { Scratch } from './helpers.mts';
import { ensureFreshBranch } from '../lib/freshness.mts';
import { pushBranch } from '../lib/git.mts';

const scratches: Scratch[] = [];

function scratch(): Scratch {
	const created = makeScratch();
	scratches.push(created);
	return created;
}

after(() => {
	for (const created of scratches) created.cleanup();
});

/** The invariant that has to hold after every one of these. */
function assertPrimaryCheckoutUntouched(repo: string, branch = 'main'): void {
	assert.equal(run(repo, ['rev-parse', '--abbrev-ref', 'HEAD']), branch, 'primary checkout changed branch');
	assert.equal(run(repo, ['status', '--porcelain']), '', 'primary checkout left dirty');
	assert.equal(
		run(repo, ['worktree', 'list', '--porcelain'])
			.split('\n')
			.filter((line) => line.startsWith('worktree ')).length,
		1,
		'scratch worktree left behind'
	);
	assert.ok(!existsSync(join(repo, '.git', 'rebase-merge')), 'rebase left in progress');
	assert.ok(!existsSync(join(repo, '.git', 'MERGE_HEAD')), 'merge left in progress');
}

/** `merge-base --is-ancestor` exits non-zero when it is not, and `run` throws. */
function assertAncestor(repo: string, ancestor: string, descendant: string): void {
	assert.doesNotThrow(
		() => run(repo, ['merge-base', '--is-ancestor', ancestor, descendant]),
		`${ancestor} is not an ancestor of ${descendant}`
	);
}

describe('ensureFreshBranch', () => {
	it('creates a branch that exists nowhere', () => {
		const { repo } = scratch();
		run(repo, ['fetch', '--prune', 'origin']);

		const outcome = ensureFreshBranch(repo, 'sandcastle/issue-1', 'main');

		assert.equal(outcome.action, 'created');
		assert.equal(headOf(repo, 'sandcastle/issue-1'), headOf(repo, 'main'));
		assertPrimaryCheckoutUntouched(repo);
	});

	it('deletes and re-cuts a branch carrying zero commits over its base', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'sandcastle/issue-2', 'main']);
		const stale = headOf(repo, 'sandcastle/issue-2');
		commitOnBranch(repo, 'main', 'a.txt', 'a\n', 'base moves on');
		run(repo, ['fetch', '--prune', 'origin']);

		const outcome = ensureFreshBranch(repo, 'sandcastle/issue-2', 'main');

		assert.equal(outcome.action, 're-cut');
		assert.notEqual(headOf(repo, 'sandcastle/issue-2'), stale);
		assert.equal(headOf(repo, 'sandcastle/issue-2'), headOf(repo, 'main'), 'must sit on the current base tip');
		assertPrimaryCheckoutUntouched(repo);
	});

	it('rebases a branch that has commits and is not on origin', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'sandcastle/issue-3', 'main']);
		commitOnBranch(repo, 'sandcastle/issue-3', 'work.txt', 'work\n', 'local work');
		commitOnBranch(repo, 'main', 'a.txt', 'a\n', 'base moves on');
		run(repo, ['fetch', '--prune', 'origin']);

		const outcome = ensureFreshBranch(repo, 'sandcastle/issue-3', 'main');

		assert.equal(outcome.action, 'rebased');
		assert.equal(run(repo, ['rev-list', '--count', 'main..sandcastle/issue-3']), '1', 'work replayed, once');
		assert.equal(run(repo, ['merge-base', 'main', 'sandcastle/issue-3']), headOf(repo, 'main'), 'base is now an ancestor');
		assertPrimaryCheckoutUntouched(repo);
	});

	it('merges the base into a branch that has commits and is on origin — never rebases it', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'sandcastle/issue-4', 'main']);
		commitOnBranch(repo, 'sandcastle/issue-4', 'work.txt', 'work\n', 'published work');
		pushBranch(repo, 'sandcastle/issue-4');
		const published = headOf(repo, 'sandcastle/issue-4');

		commitOnBranch(repo, 'main', 'a.txt', 'a\n', 'base moves on');
		run(repo, ['fetch', '--prune', 'origin']);

		const outcome = ensureFreshBranch(repo, 'sandcastle/issue-4', 'main');

		assert.equal(outcome.action, 'merged');
		// The published commit is still reachable and origin's ref is still an
		// ancestor — so the next push is a fast-forward, and no force-push can
		// ever be needed to land this. A rebase would have failed both.
		assertAncestor(repo, published, 'sandcastle/issue-4');
		assertAncestor(repo, 'refs/remotes/origin/sandcastle/issue-4', 'sandcastle/issue-4');
		assertAncestor(repo, 'main', 'sandcastle/issue-4');
		assertPrimaryCheckoutUntouched(repo);
	});

	it('skips the item on a rebase conflict and leaves no rebase in progress', () => {
		const { repo } = scratch();
		commitOnBranch(repo, 'main', 'shared.txt', 'original\n', 'shared file');
		run(repo, ['branch', 'sandcastle/issue-5', 'main']);
		commitOnBranch(repo, 'sandcastle/issue-5', 'shared.txt', 'branch version\n', 'branch edit');
		commitOnBranch(repo, 'main', 'shared.txt', 'base version\n', 'conflicting base edit');
		run(repo, ['fetch', '--prune', 'origin']);

		const before = headOf(repo, 'sandcastle/issue-5');
		const outcome = ensureFreshBranch(repo, 'sandcastle/issue-5', 'main');

		assert.equal(outcome.action, 'skipped');
		assert.equal(headOf(repo, 'sandcastle/issue-5'), before, 'a skipped branch is left exactly as it was found');
		assertPrimaryCheckoutUntouched(repo);
	});

	it('skips the item on a merge conflict and leaves no merge in progress', () => {
		const { repo } = scratch();
		commitOnBranch(repo, 'main', 'shared.txt', 'original\n', 'shared file');
		run(repo, ['branch', 'sandcastle/issue-6', 'main']);
		commitOnBranch(repo, 'sandcastle/issue-6', 'shared.txt', 'branch version\n', 'branch edit');
		pushBranch(repo, 'sandcastle/issue-6');
		commitOnBranch(repo, 'main', 'shared.txt', 'base version\n', 'conflicting base edit');
		run(repo, ['fetch', '--prune', 'origin']);

		const before = headOf(repo, 'sandcastle/issue-6');
		const outcome = ensureFreshBranch(repo, 'sandcastle/issue-6', 'main');

		assert.equal(outcome.action, 'skipped');
		assert.equal(headOf(repo, 'sandcastle/issue-6'), before);
		assertPrimaryCheckoutUntouched(repo);
	});

	it('refuses to work against a base branch that does not exist', () => {
		const { repo } = scratch();
		assert.throws(() => ensureFreshBranch(repo, 'sandcastle/issue-7', 'no-such-base'), /does not exist/);
	});
});
