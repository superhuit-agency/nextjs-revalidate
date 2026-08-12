import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { Candidate, GatherResult } from '../lib/gather.mts';
import {
	PlanAbort,
	epicIssuesFor,
	knownEpicBranches,
	orderForExecution,
	planBatch,
	planItemFor,
	validatePlan,
} from '../lib/plan.mts';
import type { Plan, PlanItem } from '../lib/plan.mts';

function candidate(overrides: Partial<Candidate> & { number: number }): Candidate {
	return {
		title: `issue ${overrides.number}`,
		url: `https://example.test/${overrides.number}`,
		labels: ['ready-for-agent'],
		body: '',
		workBranch: `sandcastle/issue-${overrides.number}`,
		parent: null,
		parentSource: null,
		children: [],
		blockers: [],
		existingPr: null,
		...overrides,
	};
}

function gathered(eligible: Candidate[], pruned: Candidate[] = []): GatherResult {
	return {
		repo: 'owner/name',
		label: 'ready-for-agent',
		considered: eligible.length + pruned.length,
		eligible,
		pruned: pruned.map((c) => ({
			candidate: c,
			reason: { kind: 'already-worked', pr: { number: 1, state: 'MERGED', url: 'https://example.test/pr/1' } },
		})),
	};
}

function planOf(items: PlanItem[], baseBranch = 'main'): Plan {
	return { baseBranch, items, deferred: [] };
}

function item(overrides: Partial<PlanItem>): PlanItem {
	return {
		issue: 7,
		title: 'issue 7',
		role: 'standalone',
		workBranch: 'sandcastle/issue-7',
		mergeInto: null,
		base: 'main',
		...overrides,
	};
}

describe('planBatch', () => {
	it('names branches from issue numbers, never from titles', () => {
		const plan = planBatch(gathered([candidate({ number: 12, title: 'Fix the thing' })]));
		assert.deepEqual(plan.items[0]?.workBranch, 'sandcastle/issue-12');
	});

	it('never routes a standalone into the base branch', () => {
		const plan = planBatch(gathered([candidate({ number: 12 })]));
		assert.deepEqual(plan.items[0]?.mergeInto, null);
	});

	it('plans epics and children alongside standalones', () => {
		const plan = planBatch(
			gathered([candidate({ number: 12 }), candidate({ number: 20, children: [21] }), candidate({ number: 21, parent: 20 })])
		);

		assert.deepEqual(
			plan.items.map((i) => [i.issue, i.role]),
			[
				[12, 'standalone'],
				[20, 'epic'],
				[21, 'child'],
			]
		);
		assert.deepEqual(plan.deferred, [], 'parenthood decides where work lives, not whether it happens');
	});

	it('gives an epic that also describes real work its own branch — #29 is exactly that', () => {
		// Treating a parent as an empty container would drop its implementation
		// work on the floor.
		const plan = planBatch(gathered([candidate({ number: 29, children: [30] })]));
		assert.deepEqual(
			[plan.items[0]?.role, plan.items[0]?.workBranch, plan.items[0]?.base],
			['epic', 'sandcastle/epic-29', 'main']
		);
	});

	it('validates clean when the batch is all children of parents outside it', () => {
		// The live case: #42 and #28 are children of parents that are not
		// themselves ready-for-agent. Aborting here would refuse every run.
		const result = gathered([candidate({ number: 42, parent: 33 }), candidate({ number: 28, parent: 29 })]);
		const plan = planBatch(result);

		assert.deepEqual(
			plan.items.map((i) => i.base),
			['sandcastle/epic-33', 'sandcastle/epic-29']
		);
		validatePlan(plan, knownEpicBranches(result));
	});

	it('defers an issue that is both a parent and a child rather than guessing', () => {
		const plan = planBatch(gathered([candidate({ number: 20, parent: 10, children: [21] })]));

		assert.deepEqual(plan.items, []);
		assert.equal(plan.deferred.length, 1);
		assert.match(plan.deferred[0]?.reason ?? '', /nested epic/);
	});
});

describe('orderForExecution', () => {
	it('puts epics before standalones before children', () => {
		const items = [
			item({ issue: 3, role: 'child', mergeInto: 'sandcastle/epic-1', base: 'sandcastle/epic-1' }),
			item({ issue: 2, role: 'standalone' }),
			item({ issue: 1, role: 'epic', workBranch: 'sandcastle/epic-1' }),
		];

		assert.deepEqual(
			orderForExecution(items).map((i) => i.issue),
			[1, 2, 3],
			'a child is cut from its epic branch, so the epic has to get there first'
		);
	});

	it('leaves the input untouched', () => {
		const items = [item({ issue: 3, role: 'child' }), item({ issue: 1, role: 'epic' })];
		orderForExecution(items);
		assert.deepEqual(
			items.map((i) => i.issue),
			[3, 1]
		);
	});
});

describe('epicIssuesFor — which issues need an epic branch', () => {
	it('counts the epics in the batch and every child\'s parent', () => {
		const items = [
			item({ issue: 1, role: 'epic', workBranch: 'sandcastle/epic-1' }),
			item({ issue: 2, role: 'standalone' }),
			item({ issue: 3, role: 'child', mergeInto: 'sandcastle/epic-9', base: 'sandcastle/epic-9' }),
		];
		const result = gathered([candidate({ number: 1, children: [3] }), candidate({ number: 2 }), candidate({ number: 3, parent: 9 })]);

		// #9 is not in the batch — a parent need not carry ready-for-agent —
		// and its branch still has to exist for #3 to be cut from.
		assert.deepEqual(epicIssuesFor(items, result), [1, 9]);
	});

	it('is empty for a batch of standalones', () => {
		assert.deepEqual(epicIssuesFor([item({ issue: 2 })], gathered([candidate({ number: 2 })])), []);
	});
});

describe('planItemFor — the shapes #45 will execute', () => {
	it('gives an epic its own branch, merging nowhere', () => {
		const item = planItemFor(candidate({ number: 20, children: [21] }));
		assert.deepEqual([item.role, item.workBranch, item.mergeInto], ['epic', 'sandcastle/epic-20', null]);
	});

	it('cuts a child from its epic branch and merges it back there', () => {
		const item = planItemFor(candidate({ number: 21, parent: 20 }));
		assert.deepEqual(
			[item.role, item.workBranch, item.base, item.mergeInto],
			['child', 'sandcastle/issue-21', 'sandcastle/epic-20', 'sandcastle/epic-20']
		);
	});
});

describe('validatePlan hard aborts', () => {
	it('aborts the whole run when any workBranch is the base branch', () => {
		const plan = planOf([item({ issue: 1 }), item({ issue: 2, workBranch: 'main' })]);
		assert.throws(
			() => validatePlan(plan, new Set()),
			(error: unknown) => {
				assert.ok(error instanceof PlanAbort);
				assert.equal(error.violations.length, 2, 'workBranch "main" trips both the base-branch and the prefix rule');
				assert.ok(error.violations.every((v) => v.issue === 2));
				return true;
			}
		);
	});

	it('aborts the whole run when any mergeInto is the base branch', () => {
		const plan = planOf([item({ issue: 1 }), item({ issue: 2, mergeInto: 'main' })]);
		assert.throws(() => validatePlan(plan, new Set()), PlanAbort);
	});

	it('aborts the whole run when a workBranch is outside sandcastle/', () => {
		const plan = planOf([item({ issue: 1 }), item({ issue: 2, workBranch: 'feature/whatever' })]);
		assert.throws(
			() => validatePlan(plan, new Set()),
			(error: unknown) => {
				assert.ok(error instanceof PlanAbort);
				assert.deepEqual(
					error.violations.map((v) => v.rule),
					['branch-prefix']
				);
				return true;
			}
		);
	});

	it('aborts when a child merges into a branch no gathered epic owns', () => {
		const plan = planOf([item({ issue: 5, role: 'child', mergeInto: 'sandcastle/epic-99', base: 'sandcastle/epic-99' })]);
		assert.throws(
			() => validatePlan(plan, new Set(['sandcastle/epic-20'])),
			(error: unknown) => {
				assert.ok(error instanceof PlanAbort);
				assert.deepEqual(
					error.violations.map((v) => v.rule),
					['unknown-epic']
				);
				return true;
			}
		);
	});

	it('reports every violation at once rather than the first', () => {
		const plan = planOf([item({ issue: 1, workBranch: 'hotfix' }), item({ issue: 2, mergeInto: 'main' })]);
		assert.throws(
			() => validatePlan(plan, new Set()),
			(error: unknown) => {
				assert.ok(error instanceof PlanAbort);
				assert.deepEqual(new Set(error.violations.map((v) => v.issue)), new Set([1, 2]));
				return true;
			}
		);
	});

	it('accepts a plan whose children point at gathered epic branches', () => {
		// Built through planItemFor rather than planBatch: planBatch defers
		// children until #45, so this is the shape #45 will hand the validator.
		const result = gathered([candidate({ number: 20, children: [21] }), candidate({ number: 21, parent: 20 })]);
		const plan = planOf(result.eligible.map((c) => planItemFor(c)));

		validatePlan(plan, knownEpicBranches(result));
	});
});

describe('knownEpicBranches', () => {
	it('counts a pruned epic — an epic with an open PR is still a real epic', () => {
		const result = gathered([candidate({ number: 21, parent: 20 })], [candidate({ number: 20, children: [21] })]);
		assert.deepEqual([...knownEpicBranches(result)], ['sandcastle/epic-20']);
		validatePlan(planOf([planItemFor(candidate({ number: 21, parent: 20 }))]), knownEpicBranches(result));
	});

	it('counts a parent that is not in the batch at all', () => {
		// #42's parent #33 is not ready-for-agent, so it is never gathered as a
		// candidate. Its epic branch is still knowable from #42's parent link
		// alone, and ensureEpicBranch() creates it from the number.
		const result = gathered([candidate({ number: 42, parent: 33 })]);
		assert.deepEqual([...knownEpicBranches(result)], ['sandcastle/epic-33']);
		validatePlan(planOf([planItemFor(result.eligible[0]!)]), knownEpicBranches(result));
	});

	it('does not count a branch no gathered issue justifies', () => {
		const result = gathered([candidate({ number: 21, parent: 20 })]);
		const invented = planOf([item({ issue: 21, role: 'child', mergeInto: 'sandcastle/epic-77', base: 'sandcastle/epic-77' })]);
		assert.throws(() => validatePlan(invented, knownEpicBranches(result)), PlanAbort);
	});
});
