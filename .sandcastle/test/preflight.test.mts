/**
 * The pre-flight, which used to be a list of commands in a runbook.
 *
 * Worktree healing is about what git really does to real refs, so it runs
 * against a scratch repository rather than a mocked git.
 */
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { after, describe, it } from 'node:test';
import { worktreePathFor } from '../lib/git.mts';
import { assertNotOnWorkBranch, checkoutMoved, healWorktrees, isScratchWorktree, preflight } from '../lib/preflight.mts';
import { makeScratch, run } from './helpers.mts';
import type { Scratch } from './helpers.mts';

const scratches: Scratch[] = [];

function scratch(): Scratch {
	const made = makeScratch();
	scratches.push(made);
	return made;
}

after(() => {
	for (const made of scratches) made.cleanup();
});

describe('the refusal', () => {
	it('refuses a checkout parked on a work branch', () => {
		assert.throws(() => assertNotOnWorkBranch('sandcastle/issue-7'), /never moves the primary checkout/);
	});

	it('lets an ordinary branch through', () => {
		assertNotOnWorkBranch('main');
		assertNotOnWorkBranch('some-feature');
	});
});

describe('healing worktrees an earlier run left behind', () => {
	it('removes a scratch worktree that still holds a work branch checked out', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'sandcastle/issue-7', 'main']);

		const path = worktreePathFor(repo, 'sandcastle/issue-7');
		mkdirSync(join(repo, '.sandcastle', 'worktrees'), { recursive: true });
		run(repo, ['worktree', 'add', path, 'sandcastle/issue-7']);

		const healed = healWorktrees(repo, () => {});

		assert.equal(healed.length, 1);
		assert.equal(existsSync(path), false);
		// The point of healing: freshness can move the branch again.
		assert.equal(run(repo, ['worktree', 'list']).split('\n').length, 1);
	});

	it('heals nothing on a dry run, which promises to touch nothing at all', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'sandcastle/issue-7', 'main']);

		const path = worktreePathFor(repo, 'sandcastle/issue-7');
		mkdirSync(join(repo, '.sandcastle', 'worktrees'), { recursive: true });
		run(repo, ['worktree', 'add', path, 'sandcastle/issue-7']);

		const before = preflight(repo, () => {}, { heal: false });

		assert.deepEqual(before.healed, []);
		assert.equal(existsSync(path), true, 'removing a worktree is a real change to the repository');
	});

	it('leaves worktrees the harness does not own alone', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'review', 'main']);
		const path = join(repo, '..', 'review-worktree');
		run(repo, ['worktree', 'add', path, 'review']);

		const healed = healWorktrees(repo, () => {});

		assert.deepEqual(healed, [], "a worktree a person made is not the harness's to remove");
		assert.equal(existsSync(path), true);
	});

	it('recognises only paths under .sandcastle/worktrees/', () => {
		assert.equal(isScratchWorktree('/repo', '/repo/.sandcastle/worktrees/sandcastle-issue-7'), true);
		assert.equal(isScratchWorktree('/repo', '/repo/.claude/worktrees/pr52'), false);
		assert.equal(isScratchWorktree('/repo', '/repo'), false);
	});
});

describe('the snapshot the run has to restore', () => {
	it('reports nothing when the checkout is where it was', () => {
		const { repo } = scratch();
		const before = preflight(repo, () => {});

		assert.equal(checkoutMoved(repo, before), null);
	});

	it('reports a checkout that moved branch', () => {
		const { repo } = scratch();
		const before = preflight(repo, () => {});
		run(repo, ['checkout', '-b', 'somewhere-else']);

		assert.match(checkoutMoved(repo, before) ?? '', /started on main, now on somewhere-else/);
	});

	it('reports a working tree that differs from how it was found', () => {
		const { repo } = scratch();
		const before = preflight(repo, () => {});
		writeFileSync(join(repo, 'stray.txt'), 'left behind\n');

		assert.match(checkoutMoved(repo, before) ?? '', /working tree differs/);
	});

	it('tolerates a checkout that started dirty — it only has to end as it began', () => {
		const { repo } = scratch();
		writeFileSync(join(repo, 'mine.txt'), "the operator's own edit\n");

		const before = preflight(repo, () => {});

		assert.notEqual(before.startedDirty, '');
		assert.equal(checkoutMoved(repo, before), null);
	});
});
