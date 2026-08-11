#!/usr/bin/env node
/**
 * AFK agent harness entry point.
 *
 * Five modes, each one step further than the last:
 *
 *   --dry-run    print the batch of issues it would work
 *   --plan       + turn the batch into a plan and validate it
 *   --prepare    + bring each work branch to a correct starting point
 *   --implement  + run implementers in containers, gate the result
 *   --finalize   + merge children into epics, branches to origin, PR into main
 *
 * `--prepare` is the first mode that writes anything: local branches, plus a
 * bare epic branch created and linked on origin where the batch needs one —
 * creating and linking is a single call, and there is no way to get the link
 * otherwise. No code, no PR. `--implement` is the first that starts a container
 * and the first that writes code — and it still puts none of it on origin:
 * commits land on the local work branch and stop there. `--finalize` is the
 * full pass, and it is the only mode that merges a child into its epic branch,
 * opens a PR or closes anything. It never merges into the base branch. Running
 * with no mode is an error rather than a no-op, so an operator can never think
 * a real pass has happened.
 */
import { BASE_BRANCH, CONCURRENCY, READY_LABEL } from './lib/config.mts';
import { currentBranch, ensureLocalBranch, fetch, git } from './lib/git.mts';
import { containerAuth } from './lib/auth.mts';
import { ensureEpicBranches, realEpicDeps } from './lib/epic.mts';
import { epicPrBody, readEpicContext, realNarrativeDeps, writeEpicNarrative } from './lib/epicpr.mts';
import { ensureFreshBranch } from './lib/freshness.mts';
import type { FreshnessOutcome } from './lib/freshness.mts';
import { currentRepo } from './lib/gh.mts';
import { gather } from './lib/gather.mts';
import type { Candidate, GatherResult } from './lib/gather.mts';
import {
	finalizeBatch,
	isFailure,
	itemsToFinalize,
	prBody,
	realFinalizeDeps,
	renderFinalizeOutcomes,
} from './lib/finalize.mts';
import { implementItem, pool, realDeps, renderOutcomes } from './lib/implement.mts';
import type { ImplementOutcome } from './lib/implement.mts';
import { childrenToMerge, isMergeFailure, mergeChildren, realMergeDeps, renderMergeOutcomes } from './lib/merge.mts';
import type { MergeOutcome } from './lib/merge.mts';
import {
	PlanAbort,
	epicIssuesFor,
	knownEpicBranches,
	orderForExecution,
	planBatch,
	renderPlan,
	validatePlan,
} from './lib/plan.mts';
import type { PlanItem } from './lib/plan.mts';
import { renderJson, renderText } from './lib/report.mts';

type Mode = 'dry-run' | 'plan' | 'prepare' | 'implement' | 'finalize';

/** Modes that start containers and write code — `--finalize` implies `--implement`. */
function runsImplementers(mode: Mode): boolean {
	return mode === 'implement' || mode === 'finalize';
}

type Options = {
	mode: Mode | null;
	json: boolean;
	repo: string | null;
	/** Issue numbers to restrict the batch to. Empty means the whole batch. */
	only: number[];
};

function parseArgs(argv: string[]): Options {
	const options: Options = { mode: null, json: false, repo: null, only: [] };

	for (let index = 0; index < argv.length; index += 1) {
		const arg = argv[index];
		if (arg === '--dry-run') {
			options.mode = 'dry-run';
		} else if (arg === '--plan') {
			options.mode = 'plan';
		} else if (arg === '--prepare') {
			options.mode = 'prepare';
		} else if (arg === '--implement') {
			options.mode = 'implement';
		} else if (arg === '--finalize') {
			options.mode = 'finalize';
		} else if (arg === '--json') {
			options.json = true;
		} else if (arg === '--repo') {
			index += 1;
			options.repo = argv[index] ?? null;
		} else if (arg === '--issue') {
			index += 1;
			const raw = argv[index];
			const issue = Number(raw);
			if (!raw || !Number.isInteger(issue) || issue <= 0) {
				console.error(`--issue needs an issue number, got: ${raw ?? '(nothing)'}`);
				process.exit(2);
			}
			options.only.push(issue);
		} else if (arg === '--help' || arg === '-h') {
			usage();
			process.exit(0);
		} else {
			console.error(`unknown argument: ${arg}`);
			usage();
			process.exit(2);
		}
	}

	return options;
}

function usage(): void {
	console.error(
		[
			'Usage: node .sandcastle/run.mts (--dry-run | --plan | --prepare | --implement | --finalize)',
			'                                [--issue N]... [--json] [--repo owner/name]',
			'',
			'  --dry-run    Print the batch of issues that would be worked, then exit.',
			'               Takes no action: no branches, nothing on origin, no containers.',
			'  --plan       Also build the plan and validate it. Read-only; a plan',
			'               violating a hard rule aborts the entire run.',
			'  --prepare    Also bring each work branch to a correct starting point,',
			'               creating and linking a bare epic branch on origin where',
			'               the batch needs one. No code, no container, no PR.',
			'  --implement  Also run an implementer in a container per item and gate',
			'               the result. Commits land on the local work branch; still',
			'               no code on origin and no PR opened.',
			'  --finalize   The full pass: also squash-merge every gate-green child',
			'               into its epic branch, send green branches to origin, open',
			'               a non-draft PR into the base branch and hand the issue',
			'               back to a human. Never merges into the base branch.',
			'  --issue N    Restrict the batch to this issue. Repeatable. Without it,',
			'               every eligible issue is worked.',
			'  --json       Emit the batch as JSON instead of text.',
			'  --repo       Target repository. Defaults to the current checkout.',
		].join('\n')
	);
}

function describeFreshness(outcome: FreshnessOutcome): string {
	switch (outcome.action) {
		case 'created':
			return `created from ${outcome.base}`;
		case 're-cut':
			return `carried nothing over ${outcome.base} — deleted and re-cut`;
		case 'rebased':
			return `local-only work rebased onto ${outcome.base}`;
		case 'merged':
			return `on origin — merged ${outcome.base} in (never rebased)`;
		case 'skipped':
			return `SKIPPED — ${outcome.reason}`;
	}
}

/**
 * Narrow the plan to the issues `--issue` named. An unmatched number is fatal
 * rather than a warning: an operator pointing the harness at one issue and
 * getting a silent no-op would read it as "the run happened and did nothing".
 */
function restrictToIssues(items: readonly PlanItem[], only: readonly number[]): PlanItem[] {
	if (only.length === 0) return [...items];

	const byNumber = new Map(items.map((item) => [item.issue, item]));
	const missing = only.filter((issue) => !byNumber.has(issue));

	if (missing.length > 0) {
		console.error(
			`\nerror: --issue ${missing.join(', ')} — not in the planned batch. An issue is only planned when it is ` +
				`open, labelled ${READY_LABEL}, has no open blockers, and has no PR on its work branch. ` +
				`The batch above says which of those it failed.`
		);
		process.exit(2);
	}

	return only.map((issue) => byNumber.get(issue) as PlanItem);
}

async function main(): Promise<void> {
	const options = parseArgs(process.argv.slice(2));

	if (options.mode === null) {
		console.error('Pick a mode: --dry-run, --plan, --prepare, --implement or --finalize.');
		usage();
		process.exit(2);
	}

	const cwd = git(process.cwd(), ['rev-parse', '--show-toplevel']);
	const startedOn = currentBranch(cwd);
	// Snapshotted, not asserted clean: the invariant is that the harness leaves
	// the primary checkout exactly as it found it, which has to hold whether or
	// not the operator started with local edits of their own.
	const startedDirty = git(cwd, ['status', '--porcelain']);
	const repo = options.repo ?? currentRepo();
	const warnings: string[] = [];
	const result = gather(repo, (message) => warnings.push(message));

	console.log(options.json ? renderJson(result) : renderText(result));

	for (const warning of warnings) {
		console.error(`warning: ${warning}`);
	}

	if (options.mode === 'dry-run') {
		console.error('\ndry run — no branches created, nothing on origin, no containers started.');
		return;
	}

	const plan = planBatch(result, BASE_BRANCH);

	try {
		validatePlan(plan, knownEpicBranches(result));
	} catch (error) {
		if (!(error instanceof PlanAbort)) throw error;
		console.error(`\n${error.message}`);
		process.exit(3);
	}

	console.log(`\nPlan — validated:\n${renderPlan(plan)}`);

	if (options.mode === 'plan') {
		console.error('\nplan only — no branches created, nothing on origin, no containers started.');
		return;
	}

	// Epics first, then standalones, then children: a child is cut from its
	// epic branch and merged back into it, so the epic has to reach a correct
	// starting point — and land its own work — before a child sits on top.
	const items = orderForExecution(restrictToIssues(plan.items, options.only));
	if (options.only.length > 0) {
		console.log(`\nRestricted to --issue ${options.only.join(', ')} — ${items.length} of ${plan.items.length} item(s).`);
	}

	// Auth is settled before any branch is touched, not at the first container:
	// a batch prepared and then abandoned on a missing token is worse than one
	// that never started.
	if (runsImplementers(options.mode)) {
		const auth = containerAuth(cwd);
		if (!auth.ok) {
			console.error(`\nerror: container auth to Claude is not configured — ${auth.reason}`);
			process.exit(5);
		}
		console.log(`\nContainer auth: ${auth.key} (from ${auth.source === 'env-file' ? '.sandcastle/.env' : 'the environment'}).`);
	}

	// Freshness reads remote-tracking refs, so one fetch has to precede it; a
	// stale ref would send a branch that is on origin down the rebase path.
	fetch(cwd);
	const base = ensureLocalBranch(cwd, BASE_BRANCH);
	if (base.action === 'refused-unpushed') {
		console.error(
			`warning: local ${BASE_BRANCH} carries ${base.ahead} unpushed commit(s); work branches will be cut from it as-is`
		);
	}

	prepareEpicBranches(cwd, repo, items, result);

	console.log('\nBranch freshness:');
	const ready: PlanItem[] = [];
	for (const item of items) {
		const outcome = ensureFreshBranch(cwd, item.workBranch, item.base);
		console.log(`  ${item.workBranch}: ${describeFreshness(outcome)}`);
		// A skipped branch was left exactly as found and is not at a correct
		// starting point; implementing on it would build on a stale base.
		if (outcome.action !== 'skipped') ready.push(item);
	}

	if (runsImplementers(options.mode)) {
		const outcomes = await implement(cwd, ready, result.eligible);
		if (options.mode === 'finalize') {
			// Children reach their epic branch first, so the epic's PR carries
			// them. Then the PRs — for epics and standalones only.
			const merges = merge(cwd, repo, ready, outcomes);
			finalize(cwd, repo, ready, outcomes, merges, result);
		}
	}

	// The primary checkout is untouchable — nothing above ran `git checkout` in
	// it. Say so out loud, and fail if it ever stops being true.
	const endedOn = currentBranch(cwd);
	const endedDirty = git(cwd, ['status', '--porcelain']);
	if (endedOn !== startedOn || endedDirty !== startedDirty) {
		console.error(
			`\nerror: primary checkout was modified — started on ${startedOn}, now on ${endedOn}` +
				(endedDirty === startedDirty ? '' : '; working tree differs from how it was found')
		);
		process.exit(4);
	}

	if (options.mode === 'prepare') {
		console.error(
			`\nprepared — no code on origin, no containers started. Primary checkout untouched, still on ${startedOn}.`
		);
		return;
	}

	if (options.mode === 'finalize') {
		console.error(
			`\nPrimary checkout untouched, still on ${startedOn}. Nothing was merged into ${BASE_BRANCH} — a human does that.`
		);
		return;
	}

	console.error(
		`\nPrimary checkout untouched, still on ${startedOn}. No code reached origin, nothing was merged and no PR was opened.`
	);
}

/**
 * Give every epic in the batch its branch, and make it available locally.
 *
 * This is the one thing `--prepare` does that reaches origin, and it is worth
 * being plain about: `createLinkedBranch` creates the branch *and* links it to
 * the issue in a single call, which is the only way to get the link at all.
 * What lands on origin is a bare `sandcastle/epic-<N>` at the base branch's
 * tip — no code, no PR, nothing merged.
 *
 * Epic branches are needed for more issues than the batch has epics: a child's
 * parent very often is not itself `ready-for-agent`, and the child still has to
 * be cut from somewhere.
 */
function prepareEpicBranches(cwd: string, repo: string, items: readonly PlanItem[], result: GatherResult): void {
	const parents = new Map(result.eligible.map((candidate) => [candidate.number, candidate.parent]));
	const issues = epicIssuesFor(items, (issue) => parents.get(issue) ?? null);

	if (issues.length === 0) return;

	console.log('\nEpic branches:');
	const deps = realEpicDeps(cwd, repo, BASE_BRANCH, (message) => console.log(`  ${message}`));
	const branches = ensureEpicBranches(deps, issues);

	// A branch just created server-side is not in any remote-tracking ref yet,
	// and the freshness step reads those to decide rebase-versus-merge.
	fetch(cwd);
	for (const epic of branches) ensureLocalBranch(cwd, epic.branch);
}

/**
 * Take every green child to its epic branch and close it.
 *
 * Part of `--finalize` rather than `--implement`: it puts commits on origin and
 * closes issues, and `--implement` promises neither.
 */
function merge(
	cwd: string,
	repo: string,
	items: readonly PlanItem[],
	outcomes: readonly ImplementOutcome[]
): MergeOutcome[] {
	const green = new Set(
		outcomes.filter((outcome) => outcome.status === 'implemented').map((outcome) => outcome.issue)
	);
	const children = childrenToMerge(items, green);

	if (children.length === 0) return [];

	console.log(`\nMerging ${children.length} child(ren) into their epic branch:`);
	const results = mergeChildren(realMergeDeps(cwd, repo, (message) => console.log(`  ${message}`)), children);
	console.log(`\nMerges:\n${renderMergeOutcomes(results)}`);

	// A child whose epic is not itself in the batch has nowhere to go this
	// pass: its code is on the epic branch and no PR will carry it onward until
	// the epic issue is worked. Not an error — but not something to discover by
	// noticing a PR that never appeared.
	const epicBranches = new Set(items.filter((item) => item.role === 'epic').map((item) => item.workBranch));
	for (const branch of new Set(results.filter((r) => r.status === 'merged').map((r) => r.epic))) {
		if (!epicBranches.has(branch)) {
			console.log(`\nnote: ${branch} is not in this batch, so no PR is opened for it this pass.`);
		}
	}

	const failed = results.filter(isMergeFailure);
	if (failed.length > 0) {
		console.error(`\nerror: ${failed.length} child(ren) did not merge cleanly — see the merges above.`);
		process.exitCode = 1;
	}

	return results;
}

/**
 * Build the PR body for an item. Deterministic for a standalone; for an epic,
 * an agent writes the narrative from the branch's own history and the harness
 * wraps it in the parts that carry meaning — `Closes #<epic>` above all.
 */
function prBodyFor(
	cwd: string,
	items: readonly PlanItem[],
	merges: readonly MergeOutcome[],
	result: GatherResult
): (item: PlanItem) => string {
	const titles = new Map(items.map((item) => [item.issue, item.title]));
	const bodies = new Map(result.eligible.map((candidate) => [candidate.number, candidate.body]));
	const deps = realNarrativeDeps((message) => console.log(`  ${message}`));

	return (item) => {
		if (item.role !== 'epic') return prBody(item);

		const merged = merges
			.filter((outcome) => outcome.status === 'merged' && outcome.epic === item.workBranch)
			.map((outcome) => ({ issue: outcome.issue, title: titles.get(outcome.issue) ?? `issue ${outcome.issue}` }));

		const context = readEpicContext(cwd, item, bodies.get(item.issue) ?? '', merged);
		return epicPrBody(item, writeEpicNarrative(deps, item, context));
	};
}

/**
 * Run the implementers. Concurrency is bounded rather than unlimited: each
 * container costs roughly a gigabyte, and a batch large enough to matter would
 * otherwise put the host into swap.
 */
async function implement(
	cwd: string,
	items: readonly PlanItem[],
	eligible: readonly Candidate[]
): Promise<ImplementOutcome[]> {
	if (items.length === 0) {
		console.error('\nnothing to implement — no item reached a correct starting point.');
		return [];
	}

	const bodies = new Map(eligible.map((candidate) => [candidate.number, candidate.body]));
	const deps = await realDeps(cwd, (message) => console.log(`  ${message}`));

	console.log(`\nImplementing ${items.length} item(s), ${CONCURRENCY} at a time:`);
	const outcomes: ImplementOutcome[] = await pool(items, CONCURRENCY, (item) =>
		implementItem(deps, item, bodies.get(item.issue) ?? '')
	);

	console.log(`\nOutcomes:\n${renderOutcomes(outcomes)}`);

	const done = outcomes.filter((outcome) => outcome.status === 'implemented');
	console.log(`\n${done.length} of ${outcomes.length} item(s) implemented with the gate green.`);

	// A batch where nothing landed is a failed run, not a quiet success — the
	// operator came back to commits or they did not.
	if (done.length === 0) process.exitCode = 1;

	return outcomes;
}

/**
 * Finalize the gate-green items: branch to origin, PR into the base, issue
 * handed back. Deterministic code — no agent is involved, and nothing here
 * merges anything.
 */
function finalize(
	cwd: string,
	repo: string,
	items: readonly PlanItem[],
	outcomes: readonly ImplementOutcome[],
	merges: readonly MergeOutcome[],
	result: GatherResult
): void {
	const finalizable = itemsToFinalize(items, outcomes);

	if (finalizable.length === 0) {
		console.error('\nnothing to finalize — no epic or standalone item came out of the gate green.');
		return;
	}

	console.log(`\nFinalizing ${finalizable.length} of ${outcomes.length} item(s):`);
	const deps = realFinalizeDeps(
		cwd,
		repo,
		(message) => console.log(`  ${message}`),
		prBodyFor(cwd, items, merges, result)
	);
	const results = finalizeBatch(deps, finalizable);

	console.log(`\nHandoff:\n${renderFinalizeOutcomes(results)}`);

	const opened = results.filter((result) => result.status === 'pr-opened');
	const failed = results.filter(isFailure);
	console.log(`\n${opened.length} PR(s) opened, waiting for a human. Nothing was merged.`);

	// Loud: a refused update to origin, or a PR nobody was told about, is a
	// failed run even though the batch carried on past it.
	if (failed.length > 0) {
		console.error(`\nerror: ${failed.length} item(s) did not finish — see the handoff above.`);
		process.exitCode = 1;
	}
}

await main();
