import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { after, describe, it } from 'node:test';
import { commitOnBranch, headOf, makeScratch, run } from './helpers.mts';
import type { Scratch } from './helpers.mts';
import { ensureLocalBranch, pushArgs, pushBranch } from '../lib/git.mts';

const scratches: Scratch[] = [];

function scratch(): Scratch {
	const created = makeScratch();
	scratches.push(created);
	return created;
}

after(() => {
	for (const created of scratches) created.cleanup();
});

describe('pushBranch — the one chokepoint', () => {
	it('throws on any branch outside sandcastle/', () => {
		for (const branch of ['main', 'feature/x', 'sandcastle-issue-1', 'release']) {
			assert.throws(() => pushArgs(branch), /refusing to push/, branch);
		}
	});

	it('never passes --force', () => {
		const args = pushArgs('sandcastle/issue-42');
		assert.ok(!args.some((arg) => /^(-f|--force|--force-with-lease.*)$/.test(arg)), args.join(' '));
		assert.deepEqual(args, ['push', '--set-upstream', 'origin', 'sandcastle/issue-42']);
	});

	it('refuses to push the base branch even when the push would succeed', () => {
		const { repo } = scratch();
		assert.throws(() => pushBranch(repo, 'main'), /refusing to push main/);
	});

	it('pushes a sandcastle branch to origin', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'sandcastle/issue-42', 'main']);
		commitOnBranch(repo, 'sandcastle/issue-42', 'work.txt', 'work\n', 'work');

		pushBranch(repo, 'sandcastle/issue-42');

		assert.equal(headOf(repo, 'sandcastle/issue-42'), headOf(repo, 'refs/remotes/origin/sandcastle/issue-42'));
	});

	it('is the only place in the harness that shells out to git push', () => {
		const libDir = fileURLToPath(new URL('../lib/', import.meta.url));
		const sources = [
			...readdirSync(libDir)
				.filter((name) => name.endsWith('.mts'))
				.map((name) => ({ name: `lib/${name}`, path: join(libDir, name) })),
			{ name: 'main.mts', path: fileURLToPath(new URL('../main.mts', import.meta.url)) },
		];

		const offenders = sources
			.filter((source) => source.name !== 'lib/git.mts')
			.filter((source) => /['"`]push['"`]/.test(readFileSync(source.path, 'utf8')))
			.map((source) => source.name);

		assert.deepEqual(offenders, [], `git push must go through pushBranch(); found in ${offenders.join(', ')}`);
	});
});

describe('ensureLocalBranch — fast-forward only', () => {
	it('refuses to move a branch carrying unpushed commits', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'sandcastle/issue-1', 'main']);
		pushBranch(repo, 'sandcastle/issue-1');

		// origin moves on (a squash-merge from an earlier cycle would look like
		// this), and the local branch has work of its own that origin lacks.
		commitOnBranch(repo, 'main', 'a.txt', 'a\n', 'origin moves on');
		run(repo, ['push', 'origin', 'main:sandcastle/issue-1']);
		commitOnBranch(repo, 'sandcastle/issue-1', 'local.txt', 'local\n', 'unpushed work');
		run(repo, ['fetch', '--prune', 'origin']);

		const before = headOf(repo, 'sandcastle/issue-1');
		const outcome = ensureLocalBranch(repo, 'sandcastle/issue-1');

		assert.equal(outcome.action, 'refused-unpushed');
		assert.equal(headOf(repo, 'sandcastle/issue-1'), before, 'the unpushed commit must survive');
	});

	it('fast-forwards a branch that is purely behind origin', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'sandcastle/issue-2', 'main']);
		pushBranch(repo, 'sandcastle/issue-2');
		commitOnBranch(repo, 'main', 'a.txt', 'a\n', 'origin moves on');
		run(repo, ['push', 'origin', 'main:sandcastle/issue-2']);
		run(repo, ['fetch', '--prune', 'origin']);

		const outcome = ensureLocalBranch(repo, 'sandcastle/issue-2');

		assert.equal(outcome.action, 'fast-forwarded');
		assert.equal(headOf(repo, 'sandcastle/issue-2'), headOf(repo, 'refs/remotes/origin/sandcastle/issue-2'));
	});

	it('leaves a branch origin has never seen alone', () => {
		const { repo } = scratch();
		run(repo, ['branch', 'sandcastle/issue-3', 'main']);
		const before = headOf(repo, 'sandcastle/issue-3');

		assert.equal(ensureLocalBranch(repo, 'sandcastle/issue-3').action, 'no-remote');
		assert.equal(headOf(repo, 'sandcastle/issue-3'), before);
	});

	it('creates a local branch tracking one that only exists on origin', () => {
		const { repo } = scratch();
		run(repo, ['push', 'origin', 'main:sandcastle/issue-4']);
		run(repo, ['fetch', '--prune', 'origin']);

		assert.equal(ensureLocalBranch(repo, 'sandcastle/issue-4').action, 'created');
		assert.equal(headOf(repo, 'sandcastle/issue-4'), headOf(repo, 'refs/remotes/origin/sandcastle/issue-4'));
	});
});
