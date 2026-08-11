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
 * Two things put a branch in here, and both are native sub-issue data read at
 * gather time:
 *
 * - a candidate **with sub-issues** — it is an epic, and its branch is its own.
 *   Pruned candidates count: an epic pruned for having an open PR is still a
 *   real epic branch, and a child of it is not an inconsistency.
 * - a candidate **with a parent** — the parent's epic branch. The parent itself
 *   is very often not in the batch, because `ready-for-agent` is per-issue and
 *   a parent need not carry it; #42 and #28 are both exactly that. The branch
 *   is still knowable and still creatable — `ensureEpicBranch()` does it from
 *   the parent's issue number alone — so refusing the child would refuse every
 *   run this repo can currently produce.
 *
 * What is left outside the set is a `mergeInto` no gathered issue justifies,
 * which is the case the guard exists for.
 */
export function knownEpicBranches(result: GatherResult): Set<string> {
	const branches = new Set<string>();
	const all = [...result.eligible, ...result.pruned.map((pruned) => pruned.candidate)];

	for (const candidate of all) {
		if (candidate.children.length > 0) branches.add(epicBranchForIssue(candidate.number));
		if (candidate.parent !== null) branches.add(epicBranchForIssue(candidate.parent));
	}

	return branches;
}

function roleOf(candidate: Candidate): PlanRole {
	if (candidate.children.length > 0) return 'epic';
	if (candidate.parent !== null) return 'child';
	return 'standalone';
}

/**
 * Execution order: epics, then standalones, then children.
 *
 * Load-bearing, not cosmetic. A child is cut from its epic branch and kept
 * fresh against it, so the epic has to reach a correct starting point first —
 * and the epic's own implementation work has to land before a child merges on
 * top of it, or the epic PR would carry the children's code and not its own.
 */
const ORDER: Record<PlanRole, number> = { epic: 0, standalone: 1, child: 2 };

export function orderForExecution(items: readonly PlanItem[]): PlanItem[] {
	return [...items].sort((left, right) => ORDER[left.role] - ORDER[right.role] || left.issue - right.issue);
}

/**
 * The epic issues a batch needs branches for — the epics in it, and every
 * child's parent. Reads the parent link back off the gathered candidate, the
 * same place `knownEpicBranches()` gets it.
 */
export function epicIssuesFor(items: readonly PlanItem[], result: GatherResult): number[] {
	const parents = new Map(result.eligible.map((candidate) => [candidate.number, candidate.parent]));
	const issues = new Set<number>();

	for (const item of items) {
		if (item.role === 'epic') issues.add(item.issue);
		if (item.role === 'child') {
			const parent = parents.get(item.issue) ?? null;
			if (parent !== null) issues.add(parent);
		}
	}

	return [...issues].sort((left, right) => left - right);
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
 * Every eligible issue is planned, whatever its parenthood: `ready-for-agent`
 * always means "implement this issue", and having children or a parent only
 * decides *where* that work lives. An epic's own work goes on its epic branch —
 * #29 is a parent that also describes real implementation work, so treating
 * parents as empty containers would be wrong here.
 *
 * The one shape left deferred is an issue that is **both** a parent and a
 * child. Nested epics have no answer to "which branch does this reach `main`
 * through" that is not a guess, and guessing would put a whole sub-tree on the
 * wrong branch. It is recorded and reported instead.
 */
export function planBatch(result: GatherResult, baseBranch: string = BASE_BRANCH): Plan {
	const items: PlanItem[] = [];
	const deferred: Deferred[] = [];

	for (const candidate of result.eligible) {
		const item = planItemFor(candidate, baseBranch);

		if (candidate.children.length > 0 && candidate.parent !== null) {
			deferred.push({
				issue: candidate.number,
				title: candidate.title,
				role: item.role,
				reason:
					`both a child of #${candidate.parent} and an epic over ${candidate.children.length} sub-issue(s) — ` +
					'a nested epic has no unambiguous route to the base branch, and the harness will not guess one',
			});
			continue;
		}

		items.push(item);
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
