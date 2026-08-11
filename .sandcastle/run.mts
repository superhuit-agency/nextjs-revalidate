#!/usr/bin/env node
/**
 * AFK agent harness entry point.
 *
 * Today it does one thing: `--dry-run` prints the batch of issues it would
 * work. It branches nothing, pushes nothing and starts no container. The
 * plan, implement, finalize and merge phases land in later issues; until
 * they exist, running without `--dry-run` is an error rather than a no-op,
 * so an operator can never think a real pass has happened.
 */
import { currentRepo } from './lib/gh.mts';
import { gather } from './lib/gather.mts';
import { renderJson, renderText } from './lib/report.mts';

type Options = {
	dryRun: boolean;
	json: boolean;
	repo: string | null;
};

function parseArgs(argv: string[]): Options {
	const options: Options = { dryRun: false, json: false, repo: null };

	for (let index = 0; index < argv.length; index += 1) {
		const arg = argv[index];
		if (arg === '--dry-run') {
			options.dryRun = true;
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
			'Usage: node .sandcastle/run.mts --dry-run [--json] [--repo owner/name]',
			'',
			'  --dry-run   Print the batch of issues that would be worked, then exit.',
			'              Takes no action: no branches, no pushes, no containers.',
			'  --json      Emit the batch as JSON instead of text.',
			'  --repo      Target repository. Defaults to the current checkout.',
		].join('\n')
	);
}

function main(): void {
	const options = parseArgs(process.argv.slice(2));

	if (!options.dryRun) {
		console.error('Only --dry-run is implemented. The execute path lands with the plan phase (#42).');
		usage();
		process.exit(2);
	}

	const repo = options.repo ?? currentRepo();
	const warnings: string[] = [];
	const result = gather(repo, (message) => warnings.push(message));

	console.log(options.json ? renderJson(result) : renderText(result));

	for (const warning of warnings) {
		console.error(`warning: ${warning}`);
	}

	console.error('\ndry run — no branches created, nothing pushed, no containers started.');
}

main();
