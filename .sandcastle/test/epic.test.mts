/**
 * Epic branch resolution, without GitHub.
 *
 * The GraphQL calls are a seam, so what these assert is the decision: reuse a
 * linked branch, adopt an unlinked one already on origin, create one otherwise
 * — and never, under any of those, end up with a branch the harness is not
 * allowed to push.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { createLinkedBranchArgs, ensureEpicBranch, ensureEpicBranches, epicRefsQuery, parseEpicRefs } from '../lib/epic.mts';
import type { EpicDeps, EpicRefs } from '../lib/epic.mts';

const REFS: EpicRefs = { issueId: 'I_issue', repositoryId: 'R_repo', linked: [] };

type Created = { name: string; oid: string };

function fakeDeps(
	options: { linked?: string[]; onOrigin?: string[]; createThrows?: string } = {}
): { deps: EpicDeps; created: Created[]; logs: string[] } {
	const created: Created[] = [];
	const logs: string[] = [];

	return {
		created,
		logs,
		deps: {
			log: (message) => logs.push(message),
			refs: () => ({ ...REFS, linked: options.linked ?? [] }),
			onOrigin: (branch) => (options.onOrigin ?? []).includes(branch),
			baseOid: () => 'a'.repeat(40),
			createLinked: (_refs, name, oid) => {
				if (options.createThrows) throw new Error(options.createThrows);
				created.push({ name, oid });
			},
		},
	};
}

describe('ensureEpicBranch', () => {
	it('creates and links a branch for an epic that has none', () => {
		const { deps, created } = fakeDeps();

		assert.deepEqual(ensureEpicBranch(deps, 29), { issue: 29, branch: 'sandcastle/epic-29', source: 'created' });
		assert.deepEqual(created, [{ name: 'sandcastle/epic-29', oid: 'a'.repeat(40) }]);
	});

	it('reuses a branch already linked to the issue', () => {
		const { deps, created } = fakeDeps({ linked: ['sandcastle/epic-29'] });

		assert.equal(ensureEpicBranch(deps, 29).source, 'linked');
		assert.deepEqual(created, [], 'creating over an existing linked branch would fail outright');
	});

	it('adopts an unlinked branch that is already on origin', () => {
		// An earlier run that created the branch but died before linking, or a
		// link a human removed. Either way the branch carries work.
		const { deps, created } = fakeDeps({ onOrigin: ['sandcastle/epic-29'] });

		assert.equal(ensureEpicBranch(deps, 29).source, 'existing');
		assert.deepEqual(created, []);
	});

	it('names the branch from the issue number, never from a linked slug', () => {
		const { deps, created, logs } = fakeDeps({ linked: ['29-set-up-the-thing'] });
		const epic = ensureEpicBranch(deps, 29);

		assert.equal(epic.branch, 'sandcastle/epic-29');
		assert.deepEqual(created, [{ name: 'sandcastle/epic-29', oid: 'a'.repeat(40) }]);
		assert.match(
			logs.join('\n'),
			/29-set-up-the-thing.*cannot be pushed/s,
			'a branch the harness may not push is reported, not silently adopted'
		);
	});

	it('is idempotent — a second pass reuses what the first made', () => {
		const linked: string[] = [];
		const deps: EpicDeps = {
			log: () => {},
			refs: () => ({ ...REFS, linked: [...linked] }),
			onOrigin: () => false,
			baseOid: () => 'b'.repeat(40),
			createLinked: (_refs, name) => linked.push(name),
		};

		assert.equal(ensureEpicBranch(deps, 7).source, 'created');
		assert.equal(ensureEpicBranch(deps, 7).source, 'linked');
	});

	it('resolves every epic in a batch', () => {
		const { deps } = fakeDeps();
		assert.deepEqual(
			ensureEpicBranches(deps, [29, 33]).map((epic) => epic.branch),
			['sandcastle/epic-29', 'sandcastle/epic-33']
		);
	});
});

describe('createLinkedBranchArgs — the second copy of the prefix guard', () => {
	it('refuses to create a branch outside sandcastle/', () => {
		// This call reaches origin without going through pushBranch(), so it
		// carries the rule itself.
		for (const name of ['main', 'epic-29', 'feature/x']) {
			assert.throws(() => createLinkedBranchArgs(REFS, name, 'c'.repeat(40)), /refusing to create/, name);
		}
	});

	it('passes the issue, the repository, the name and the base oid', () => {
		const args = createLinkedBranchArgs(REFS, 'sandcastle/epic-29', 'd'.repeat(40));

		assert.ok(args.includes('issueId=I_issue'));
		assert.ok(args.includes('repositoryId=R_repo'));
		assert.ok(args.includes('name=sandcastle/epic-29'));
		assert.ok(args.includes(`oid=${'d'.repeat(40)}`));
		assert.ok(args.some((arg) => arg.includes('createLinkedBranch')));
	});
});

describe('epicRefsQuery / parseEpicRefs', () => {
	it('asks for the issue and repository ids alongside the linked branches', () => {
		const query = epicRefsQuery('owner/name', 29).join(' ');

		assert.match(query, /repository\(owner:"owner",name:"name"\)/);
		assert.match(query, /issue\(number:29\)/);
		assert.match(query, /linkedBranches/);
	});

	it('reads names out of the response, skipping refs GitHub could not resolve', () => {
		const refs = parseEpicRefs(
			{
				data: {
					repository: {
						id: 'R_repo',
						issue: {
							id: 'I_issue',
							linkedBranches: { nodes: [{ ref: { name: 'sandcastle/epic-29' } }, { ref: null }] },
						},
					},
				},
			},
			29
		);

		assert.deepEqual(refs, { issueId: 'I_issue', repositoryId: 'R_repo', linked: ['sandcastle/epic-29'] });
	});

	it('throws on an issue GitHub does not return', () => {
		assert.throws(() => parseEpicRefs({ data: { repository: { id: 'R', issue: null } } }, 404), /no such issue/);
	});
});
