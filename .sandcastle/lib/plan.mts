/**
 * The plan, and the guard that refuses a bad one.
 *
 * A plan says which issue goes on which `workBranch` and what that branch
 * merges into. It is then validated in code, not trusted — three conditions
 * abort the **entire run**, not just the offending item. A planner confused
 * enough to emit one of these is not to be partially obeyed: the items it got
 * right are no more trustworthy than the one it got wrong.
 */
import { BASE_BRANCH, BRANCH_PREFIX, epicBranchForIssue, workBranchForIssue } from './config.mts';
import type { Candidate, GatherResult } from './gather.mts';

export type PlanRole = 'standalone' | 'epic' | 'child';

export type PlanItem = {
	issue: number;
	title: string;
	role: PlanRole;
	workBranch: string;
	/**
	 * The branch this work is merged into by the harness — an epic branch, for
	 * a child. `null` for anything whose only route onward is a PR a human
	 * merges, which is every standalone and every epic. It is never the base
	 * branch: nothing reaches `main` except a human merging a PR.
	 */
	mergeInto: string | null;
	/** Branch the work branch is cut from and kept fresh against. */
	base: string;
};

/** An eligible issue the harness understands but cannot execute yet. */
export type Deferred = {
	issue: number;
	title: string;
	role: PlanRole;
	reason: string;
};

export type Plan = {
	baseBranch: string;
	items: PlanItem[];
	deferred: Deferred[];
};

export type PlanViolation = {
	issue: number;
	workBranch: string;
	rule: 'base-branch-as-target' | 'branch-prefix' | 'unknown-epic';
	detail: string;
};

/** Thrown by `validatePlan()`. Aborts the run — never a per-item skip. */
export class PlanAbort extends Error {
	violations: PlanViolation[];

	constructor(violations: PlanViolation[]) {
		super(
			[
				`plan rejected — ${violations.length} violation(s); aborting the entire run:`,
				...violations.map((v) => `  #${v.issue} (${v.workBranch}) — ${v.rule}: ${v.detail}`),
			].join('\n')
		);
		this.name = 'PlanAbort';
		this.violations = violations;
	}
}

/**
 * Epic branches the gathered context actually knows about.
 *
 * Read from *every* gathered candidate with sub-issues, pruned ones included:
 * an epic pruned for having an open PR is still a real epic branch, and a
 * child of it is not an inconsistency.
 */
export function knownEpicBranches(result: GatherResult): Set<string> {
	const branches = new Set<string>();
	const all = [...result.eligible, ...result.pruned.map((pruned) => pruned.candidate)];

	for (const candidate of all) {
		if (candidate.children.length > 0) branches.add(epicBranchForIssue(candidate.number));
	}

	return branches;
}

function roleOf(candidate: Candidate): PlanRole {
	if (candidate.children.length > 0) return 'epic';
	if (candidate.parent !== null) return 'child';
	return 'standalone';
}

/**
 * The plan item a candidate would get, by role. Deterministic — branch names
 * come from issue numbers, never from title slugs, which cannot be recomputed
 * after a retitle and would break the re-pick guard.
 */
export function planItemFor(candidate: Candidate, baseBranch: string = BASE_BRANCH): PlanItem {
	const role = roleOf(candidate);
	const shared = { issue: candidate.number, title: candidate.title };

	if (role === 'epic') {
		// An epic's own work lives on its epic branch; the branch is cut from
		// the base and reaches `main` only through a PR a human merges.
		return { ...shared, role, workBranch: epicBranchForIssue(candidate.number), mergeInto: null, base: baseBranch };
	}

	if (role === 'child' && candidate.parent !== null) {
		const epic = epicBranchForIssue(candidate.parent);
		return { ...shared, role, workBranch: workBranchForIssue(candidate.number), mergeInto: epic, base: epic };
	}

	return { ...shared, role: 'standalone', workBranch: workBranchForIssue(candidate.number), mergeInto: null, base: baseBranch };
}

/**
 * Turn a gathered batch into a plan.
 *
 * Standalone issues only, for now. Epic and child machinery — creating epic
 * branches, squash-merging children into them, the epic PR — is #45, and a
 * child planned before it exists would be cut from an epic branch nothing
 * creates. Those candidates are *deferred*: recorded, reported, and left for
 * the run that can actually work them.
 *
 * Deferring is deliberately a per-item outcome, not an abort. #42 and #28 are
 * both children of parents that are not themselves `ready-for-agent`, so
 * aborting here would mean the harness refuses every run this repo can
 * currently produce.
 */
export function planBatch(result: GatherResult, baseBranch: string = BASE_BRANCH): Plan {
	const items: PlanItem[] = [];
	const deferred: Deferred[] = [];

	for (const candidate of result.eligible) {
		const item = planItemFor(candidate, baseBranch);

		if (item.role === 'standalone') {
			items.push(item);
			continue;
		}

		deferred.push({
			issue: candidate.number,
			title: candidate.title,
			role: item.role,
			reason:
				item.role === 'child'
					? `child of #${candidate.parent} — needs epic branch ${item.base}; epic machinery is #45`
					: `epic with ${candidate.children.length} sub-issue(s) — epic machinery is #45`,
		});
	}

	return { baseBranch, items, deferred };
}

/**
 * Check the plan in code. Collects every violation before throwing so an
 * operator sees all of them at once, then aborts the whole run.
 */
export function validatePlan(plan: Plan, epicBranches: Set<string>): void {
	const violations: PlanViolation[] = [];

	for (const item of plan.items) {
		if (item.workBranch === plan.baseBranch) {
			violations.push({
				issue: item.issue,
				workBranch: item.workBranch,
				rule: 'base-branch-as-target',
				detail: `workBranch is the base branch (${plan.baseBranch})`,
			});
		}

		if (item.mergeInto === plan.baseBranch) {
			violations.push({
				issue: item.issue,
				workBranch: item.workBranch,
				rule: 'base-branch-as-target',
				detail: `mergeInto is the base branch (${plan.baseBranch}); nothing reaches it except a human merging a PR`,
			});
		}

		if (!item.workBranch.startsWith(BRANCH_PREFIX)) {
			violations.push({
				issue: item.issue,
				workBranch: item.workBranch,
				rule: 'branch-prefix',
				detail: `workBranch does not start with ${BRANCH_PREFIX}`,
			});
		}

		if (item.role === 'child') {
			if (item.mergeInto === null) {
				violations.push({
					issue: item.issue,
					workBranch: item.workBranch,
					rule: 'unknown-epic',
					detail: 'child has no mergeInto',
				});
			} else if (!epicBranches.has(item.mergeInto)) {
				violations.push({
					issue: item.issue,
					workBranch: item.workBranch,
					rule: 'unknown-epic',
					detail: `mergeInto ${item.mergeInto} is not a known epic branch from gathered context`,
				});
			}
		}
	}

	if (violations.length > 0) throw new PlanAbort(violations);
}

export function renderPlan(plan: Plan): string {
	const lines: string[] = [`base:  ${plan.baseBranch}`, `items: ${plan.items.length}`, ''];

	if (plan.items.length === 0) {
		lines.push('  (nothing to plan)');
	}

	for (const item of plan.items) {
		lines.push(`  #${item.issue} ${item.title}`);
		lines.push(`      role:      ${item.role}`);
		lines.push(`      branch:    ${item.workBranch}`);
		lines.push(`      cut from:  ${item.base}`);
		lines.push(`      mergeInto: ${item.mergeInto ?? '— (PR only)'}`);
	}

	if (plan.deferred.length > 0) {
		lines.push('');
		lines.push(`Deferred — ${plan.deferred.length} issue(s):`);
		for (const deferred of plan.deferred) {
			lines.push(`  #${deferred.issue} ${deferred.title}`);
			lines.push(`      ${deferred.reason}`);
		}
	}

	return lines.join('\n');
}
