import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { Candidate, GatherResult } from '../lib/gather.mts';
import { PlanAbort, knownEpicBranches, planBatch, planItemFor, validatePlan } from '../lib/plan.mts';
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

	it('defers epics and children rather than aborting — epic machinery is #45', () => {
		const plan = planBatch(
			gathered([candidate({ number: 12 }), candidate({ number: 20, children: [21] }), candidate({ number: 21, parent: 20 })])
		);

		assert.deepEqual(
			plan.items.map((i) => i.issue),
			[12],
			'only standalone issues are workable today'
		);
		assert.deepEqual(
			plan.deferred.map((d) => [d.issue, d.role]),
			[
				[20, 'epic'],
				[21, 'child'],
			]
		);
	});

	it('validates clean when the batch is all children — deferring is not an abort', () => {
		// The live case: #42 and #28 are children of parents that are not
		// themselves ready-for-agent. Aborting here would refuse every run.
		const result = gathered([candidate({ number: 42, parent: 33 }), candidate({ number: 28, parent: 29 })]);
		const plan = planBatch(result);

		assert.deepEqual(plan.items, []);
		validatePlan(plan, knownEpicBranches(result));
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

	it('does not count an epic no gathered issue knows about', () => {
		const result = gathered([candidate({ number: 21, parent: 20 })]);
		assert.deepEqual([...knownEpicBranches(result)], []);
		assert.throws(() => validatePlan(planOf([planItemFor(result.eligible[0]!)]), knownEpicBranches(result)), PlanAbort);
	});
});
