#!/usr/bin/env node
/**
 * AFK agent harness entry point.
 *
 *   npm run sandcastle              one full pass
 *   npm run sandcastle -- --dry-run print the batch it would work, then stop
 *
 * A pass is four phases over every `ready-for-agent` issue: plan the batch,
 * bring each work branch to a correct starting point, run an implementer in a
 * container per item, then merge green children into their epic branch, push
 * green branches to origin and open a PR into `main` for each.
 *
 * The one rule the whole thing exists to keep: **nothing reaches `main` except
 * a human merging a PR.** No phase merges into the base branch, and the only
 * push in the harness is `pushBranch()`, which refuses any branch outside
 * `sandcastle/`.
 *
 * This file is orchestration only. Every decision it makes lives in `lib/`,
 * with its own tests — the sequence below is the part that is not worth a mode
 * flag.
 */
import { BASE_BRANCH, CONCURRENCY, SANDBOX_IMAGE } from './lib/config.mts';
import { ensureLocalBranch, fetch, git } from './lib/git.mts';
import { checkoutMoved, preflight } from './lib/preflight.mts';
import { containerAuth } from './lib/auth.mts';
import { ensureSandboxImage, realImageDeps } from './lib/image.mts';
import { ensureEpicBranches, realEpicDeps } from './lib/epic.mts';
import type { EpicBranch } from './lib/epic.mts';
import { realBodyFor } from './lib/epicpr.mts';
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
import { greenIssues, implementItem, pool, realDeps, renderOutcomes } from './lib/implement.mts';
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
import { renderText } from './lib/report.mts';

/**
 * The only flag. Everything else a five-mode CLI used to offer is either the
 * whole run or nothing — an operator either wants a pass or wants to see the
 * batch first.
 */
function parseArgs(argv: string[]): { dryRun: boolean } {
	const unknown = argv.filter((arg) => arg !== '--dry-run');

	if (unknown.length > 0) {
		console.error(`unknown argument: ${unknown[0]}\n\nUsage: npm run sandcastle [-- --dry-run]`);
		process.exit(2);
	}

	return { dryRun: argv.includes('--dry-run') };
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

async function main(): Promise<void> {
	const { dryRun } = parseArgs(process.argv.slice(2));

	// The repository root, not wherever the operator invoked npm from: every
	// path the harness builds hangs off it.
	const cwd = git(process.cwd(), ['rev-parse', '--show-toplevel']);

	console.log('Pre-flight:');
	let started;
	try {
		started = preflight(cwd, (message) => console.log(`  ${message}`), { heal: !dryRun });
	} catch (error) {
		// A refusal is an ordinary outcome, not a crash: it says what the operator
		// has to do, and a stack trace above it helps nobody.
		console.error(`error: ${error instanceof Error ? error.message : String(error)}`);
		process.exit(2);
	}
	console.log(
		dryRun
			? `  on ${started.startedOn}; nothing healed — a dry run touches nothing at all.`
			: `  on ${started.startedOn}, ${started.healed.length} stale worktree(s) cleared.`
	);

	const repo = currentRepo();

	const warnings: string[] = [];
	const result = gather(repo, (message) => warnings.push(message));

	console.log(renderText(result));
	for (const warning of warnings) console.error(`warning: ${warning}`);

	const plan = planBatch(result, BASE_BRANCH);

	try {
		validatePlan(plan, knownEpicBranches(result));
	} catch (error) {
		if (!(error instanceof PlanAbort)) throw error;
		console.error(`\n${error.message}`);
		process.exit(3);
	}

	console.log(`\nPlan — validated:\n${renderPlan(plan)}`);

	if (dryRun) {
		console.error('\ndry run — no branches created, nothing on origin, no containers started.');
		return;
	}

	// Epics first, then standalones, then children: a child is cut from its
	// epic branch and merged back into it, so the epic has to reach a correct
	// starting point — and land its own work — before a child sits on top.
	const items = orderForExecution(plan.items);

	if (items.length === 0) {
		console.error('\nnothing to work — no issue is ready for an agent.');
		return;
	}

	// Auth and the image are settled before any branch is touched, not at the
	// first container: a batch prepared and then abandoned on a missing token is
	// worse than one that never started.
	const auth = containerAuth(cwd);
	if (!auth.ok) {
		console.error(`\nerror: container auth to Claude is not configured — ${auth.reason}`);
		process.exit(5);
	}
	console.log(`\nContainer auth: ${auth.key} (from ${auth.source === 'env-file' ? '.sandcastle/.env' : 'the environment'}).`);

	console.log('\nSandbox image:');
	ensureSandboxImage(realImageDeps(cwd, (message) => console.log(`  ${message}`)), SANDBOX_IMAGE);

	// Freshness reads remote-tracking refs, so one fetch has to precede it; a
	// stale ref would send a branch that is on origin down the rebase path.
	fetch(cwd);
	const base = ensureLocalBranch(cwd, BASE_BRANCH);
	if (base.action === 'refused-unpushed') {
		console.error(
			`warning: local ${BASE_BRANCH} carries ${base.ahead} unpushed commit(s); work branches will be cut from it as-is`
		);
	}

	const epics = prepareEpicBranches(cwd, repo, items, result);
	assertEpicBranchesReady(items, epics);

	// Two waves, and the split is the point. Everything that owns its own
	// branch goes first; children go second, *after* the first wave has
	// committed — so a child is cut from an epic branch that already carries
	// the epic's own work, rather than from a bare one. Cutting them all up
	// front would put the child on a stale base, which is exactly what the
	// freshness rules exist to prevent.
	const first = items.filter((item) => item.role !== 'child');
	const children = items.filter((item) => item.role === 'child');

	console.log('\nBranch freshness:');
	const ready = freshen(cwd, first);
	const outcomes = await implement(cwd, ready, result.eligible);

	const readyChildren = children.length > 0 ? (console.log('\nBranch freshness — children:'), freshen(cwd, children)) : [];
	ready.push(...readyChildren);
	outcomes.push(...(await implement(cwd, readyChildren, result.eligible)));

	reportImplemented(outcomes);

	// Children reach their epic branch first, so the epic's PR carries them.
	// Then the PRs — for epics and standalones only.
	const merges = merge(cwd, repo, ready, outcomes);
	finalize(cwd, repo, ready, outcomes, merges, result);

	// The primary checkout is untouchable — nothing above ran `git checkout` in
	// it. Say so out loud, and fail if it ever stops being true.
	const moved = checkoutMoved(cwd, started);
	if (moved) {
		console.error(`\nerror: ${moved}`);
		process.exit(4);
	}

	console.error(
		`\nPrimary checkout untouched, still on ${started.startedOn}. Nothing was merged into ${BASE_BRANCH} — a human does that.`
	);
}

/**
 * Give every epic in the batch its branch, and make it available locally.
 *
 * `createLinkedBranch` creates the branch *and* links it to the issue in a
 * single call, which is the only way to get the link at all. What lands on
 * origin is a bare `sandcastle/epic-<N>` at the base branch's tip — no code, no
 * PR, nothing merged.
 *
 * Epic branches are needed for more issues than the batch has epics: a child's
 * parent very often is not itself `ready-for-agent`, and the child still has to
 * be cut from somewhere.
 */
function prepareEpicBranches(
	cwd: string,
	repo: string,
	items: readonly PlanItem[],
	result: GatherResult
): EpicBranch[] {
	const issues = epicIssuesFor(items, result);
	if (issues.length === 0) return [];

	console.log('\nEpic branches:');
	const deps = realEpicDeps(cwd, repo, BASE_BRANCH, (message) => console.log(`  ${message}`));
	const branches = ensureEpicBranches(deps, issues);

	// A branch just created server-side is not in any remote-tracking ref yet,
	// and the freshness step reads those to decide rebase-versus-merge.
	fetch(cwd);
	for (const epic of branches) ensureLocalBranch(cwd, epic.branch);

	return branches;
}

/**
 * The unknown-epic guard, at the point it can actually fire.
 *
 * `validatePlan()` checks a child's `mergeInto` against the branches the
 * *gathered context* implies, which for a plan this code built is true by
 * construction — it is a regression net, not a runtime possibility. This is
 * the reachable half: the branches that really exist, as GitHub just reported
 * them. A child whose epic branch could not be created or came back under
 * another name has nowhere to be cut from, and that aborts the **entire run**
 * rather than skipping the item.
 */
function assertEpicBranchesReady(items: readonly PlanItem[], epics: readonly EpicBranch[]): void {
	const available = new Set(epics.map((epic) => epic.branch));
	const orphaned = items.filter((item) => item.role === 'child' && !available.has(item.mergeInto ?? ''));

	if (orphaned.length === 0) return;

	console.error(
		[
			`\nerror: ${orphaned.length} child(ren) point at an epic branch that does not exist; aborting the entire run:`,
			...orphaned.map((item) => `  #${item.issue} (${item.workBranch}) — mergeInto ${item.mergeInto} was never created`),
		].join('\n')
	);
	process.exit(3);
}

/**
 * Bring a wave of branches to a correct starting point, dropping any the
 * freshness rules had to skip: such a branch was left exactly as found, so it
 * is not at a correct starting point and implementing on it would build on a
 * stale base.
 */
function freshen(cwd: string, items: readonly PlanItem[]): PlanItem[] {
	const ready: PlanItem[] = [];

	for (const item of items) {
		const outcome = ensureFreshBranch(cwd, item.workBranch, item.base);
		console.log(`  ${item.workBranch}: ${describeFreshness(outcome)}`);
		if (outcome.action !== 'skipped') ready.push(item);
	}

	return ready;
}

/**
 * The verdict over both waves. A batch where nothing landed is a failed run,
 * not a quiet success — the operator came back to commits or they did not.
 */
function reportImplemented(outcomes: readonly ImplementOutcome[]): void {
	if (outcomes.length === 0) return;

	const done = outcomes.filter((outcome) => outcome.status === 'implemented');
	console.log(`\n${done.length} of ${outcomes.length} item(s) implemented with the gate green.`);
	if (done.length === 0) process.exitCode = 1;
}

/** Take every green child to its epic branch and close it. */
function merge(
	cwd: string,
	repo: string,
	items: readonly PlanItem[],
	outcomes: readonly ImplementOutcome[]
): MergeOutcome[] {
	const children = childrenToMerge(items, greenIssues(outcomes));

	if (children.length === 0) return [];

	console.log(`\nMerging ${children.length} child(ren) into their epic branch:`);
	const results = mergeChildren(realMergeDeps(cwd, repo, (message) => console.log(`  ${message}`)), children);
	console.log(`\nMerges:\n${renderMergeOutcomes(results)}`);

	// A child whose epic is not itself in the batch has nowhere to go this
	// pass: its code is on the epic branch and no PR will carry it onward until
	// the epic issue is worked. Not an error — but not something to discover by
	// noticing a PR that never appeared.
	const epicBranches = new Set(items.filter((item) => item.role === 'epic').map((item) => item.workBranch));
	for (const branch of new Set(results.filter((outcome) => outcome.status === 'merged').map((outcome) => outcome.epic))) {
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
	const log = (message: string) => console.log(`  ${message}`);
	const titles = new Map(items.map((item) => [item.issue, item.title]));
	const bodies = new Map(result.eligible.map((candidate) => [candidate.number, candidate.body]));

	const deps = realFinalizeDeps(
		cwd,
		repo,
		log,
		realBodyFor({
			cwd,
			log,
			standalone: prBody,
			issueBody: (issue) => bodies.get(issue) ?? '',
			mergedInto: (branch) =>
				merges
					.filter((outcome) => outcome.status === 'merged' && outcome.epic === branch)
					.map((outcome) => ({ issue: outcome.issue, title: titles.get(outcome.issue) ?? `issue ${outcome.issue}` })),
		})
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
