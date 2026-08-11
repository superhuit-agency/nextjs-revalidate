#!/usr/bin/env node
/**
 * AFK agent harness entry point.
 *
 * Three modes, each one step further than the last, and none of them starts a
 * container:
 *
 *   --dry-run   print the batch of issues it would work
 *   --plan      + turn the batch into a plan and validate it
 *   --prepare   + bring each work branch to a correct starting point
 *
 * `--prepare` is the first mode that writes anything, and what it writes is
 * local branches only — nothing is pushed. The implement, finalize and merge
 * phases land in later issues; until they exist, running with no mode is an
 * error rather than a no-op, so an operator can never think a real pass has
 * happened.
 */
import { BASE_BRANCH } from './lib/config.mts';
import { currentBranch, ensureLocalBranch, fetch, git } from './lib/git.mts';
import { ensureFreshBranch } from './lib/freshness.mts';
import type { FreshnessOutcome } from './lib/freshness.mts';
import { currentRepo } from './lib/gh.mts';
import { gather } from './lib/gather.mts';
import { PlanAbort, knownEpicBranches, planBatch, renderPlan, validatePlan } from './lib/plan.mts';
import { renderJson, renderText } from './lib/report.mts';

type Mode = 'dry-run' | 'plan' | 'prepare';

type Options = {
	mode: Mode | null;
	json: boolean;
	repo: string | null;
};

function parseArgs(argv: string[]): Options {
	const options: Options = { mode: null, json: false, repo: null };

	for (let index = 0; index < argv.length; index += 1) {
		const arg = argv[index];
		if (arg === '--dry-run') {
			options.mode = 'dry-run';
		} else if (arg === '--plan') {
			options.mode = 'plan';
		} else if (arg === '--prepare') {
			options.mode = 'prepare';
		} else if (arg === '--json') {
			options.json = true;
		} else if (arg === '--repo') {
			index += 1;
			options.repo = argv[index] ?? null;
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
			'Usage: node .sandcastle/run.mts (--dry-run | --plan | --prepare) [--json] [--repo owner/name]',
			'',
			'  --dry-run   Print the batch of issues that would be worked, then exit.',
			'              Takes no action: no branches, no pushes, no containers.',
			'  --plan      Also build the plan and validate it. Read-only; a plan',
			'              violating a hard rule aborts the entire run.',
			'  --prepare   Also bring each work branch to a correct starting point.',
			'              Writes local branches only — still nothing pushed, still',
			'              no container.',
			'  --json      Emit the batch as JSON instead of text.',
			'  --repo      Target repository. Defaults to the current checkout.',
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

function main(): void {
	const options = parseArgs(process.argv.slice(2));

	if (options.mode === null) {
		console.error('Pick a mode: --dry-run, --plan or --prepare. The execute path lands with the implement phase (#43).');
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
	for (const item of plan.items) {
		const outcome = ensureFreshBranch(cwd, item.workBranch, item.base);
		console.log(`  ${item.workBranch}: ${describeFreshness(outcome)}`);
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

	console.error(`\nprepared — nothing pushed, no containers started. Primary checkout untouched, still on ${startedOn}.`);
}

main();
