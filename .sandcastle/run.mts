#!/usr/bin/env node
/**
 * AFK agent harness entry point.
 *
 * Four modes, each one step further than the last:
 *
 *   --dry-run    print the batch of issues it would work
 *   --plan       + turn the batch into a plan and validate it
 *   --prepare    + bring each work branch to a correct starting point
 *   --implement  + run implementers in containers, gate the result
 *
 * `--prepare` is the first mode that writes anything, and what it writes is
 * local branches only. `--implement` is the first that starts a container and
 * the first that writes code — and it still pushes nothing and opens no PR:
 * commits land on the local work branch and stop there. The finalize and merge
 * phases land in later issues; until they exist, running with no mode is an
 * error rather than a no-op, so an operator can never think a real pass has
 * happened.
 */
import { BASE_BRANCH, CONCURRENCY, READY_LABEL } from './lib/config.mts';
import { currentBranch, ensureLocalBranch, fetch, git } from './lib/git.mts';
import { containerAuth } from './lib/auth.mts';
import { ensureFreshBranch } from './lib/freshness.mts';
import type { FreshnessOutcome } from './lib/freshness.mts';
import { currentRepo } from './lib/gh.mts';
import { gather } from './lib/gather.mts';
import type { Candidate } from './lib/gather.mts';
import { implementItem, pool, realDeps, renderOutcomes } from './lib/implement.mts';
import type { ImplementOutcome } from './lib/implement.mts';
import { PlanAbort, knownEpicBranches, planBatch, renderPlan, validatePlan } from './lib/plan.mts';
import type { PlanItem } from './lib/plan.mts';
import { renderJson, renderText } from './lib/report.mts';

type Mode = 'dry-run' | 'plan' | 'prepare' | 'implement';

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
			'Usage: node .sandcastle/run.mts (--dry-run | --plan | --prepare | --implement)',
			'                                [--issue N]... [--json] [--repo owner/name]',
			'',
			'  --dry-run    Print the batch of issues that would be worked, then exit.',
			'               Takes no action: no branches, no pushes, no containers.',
			'  --plan       Also build the plan and validate it. Read-only; a plan',
			'               violating a hard rule aborts the entire run.',
			'  --prepare    Also bring each work branch to a correct starting point.',
			'               Writes local branches only — still nothing pushed, still',
			'               no container.',
			'  --implement  Also run an implementer in a container per item and gate',
			'               the result. Commits land on the local work branch; still',
			'               nothing pushed and no PR opened.',
			'  --issue N    Restrict the batch to this issue. Repeatable. Without it,',
			'               every eligible standalone issue is worked.',
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
				`open, labelled ${READY_LABEL}, has no open blockers, has no PR on its work branch, and is standalone. ` +
				`The batch above says which of those it failed.`
		);
		process.exit(2);
	}

	return only.map((issue) => byNumber.get(issue) as PlanItem);
}

async function main(): Promise<void> {
	const options = parseArgs(process.argv.slice(2));

	if (options.mode === null) {
		console.error('Pick a mode: --dry-run, --plan, --prepare or --implement.');
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
		console.error('\ndry run — no branches created, nothing pushed, no containers started.');
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
		console.error('\nplan only — no branches created, nothing pushed, no containers started.');
		return;
	}

	const items = restrictToIssues(plan.items, options.only);
	if (options.only.length > 0) {
		console.log(`\nRestricted to --issue ${options.only.join(', ')} — ${items.length} of ${plan.items.length} item(s).`);
	}

	// Auth is settled before any branch is touched, not at the first container:
	// a batch prepared and then abandoned on a missing token is worse than one
	// that never started.
	if (options.mode === 'implement') {
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

	console.log('\nBranch freshness:');
	const ready: PlanItem[] = [];
	for (const item of items) {
		const outcome = ensureFreshBranch(cwd, item.workBranch, item.base);
		console.log(`  ${item.workBranch}: ${describeFreshness(outcome)}`);
		// A skipped branch was left exactly as found and is not at a correct
		// starting point; implementing on it would build on a stale base.
		if (outcome.action !== 'skipped') ready.push(item);
	}

	if (options.mode === 'implement') {
		await implement(cwd, ready, result.eligible);
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
			`\nprepared — nothing pushed, no containers started. Primary checkout untouched, still on ${startedOn}.`
		);
		return;
	}

	console.error(`\nPrimary checkout untouched, still on ${startedOn}. Nothing was pushed and no PR was opened.`);
}

/**
 * Run the implementers. Concurrency is bounded rather than unlimited: each
 * container costs roughly a gigabyte, and a batch large enough to matter would
 * otherwise put the host into swap.
 */
async function implement(cwd: string, items: readonly PlanItem[], eligible: readonly Candidate[]): Promise<void> {
	if (items.length === 0) {
		console.error('\nnothing to implement — no item reached a correct starting point.');
		return;
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
}

await main();
