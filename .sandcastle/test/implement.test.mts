/**
 * The implement phase, exercised without a container.
 *
 * `implementItem()` takes its sandbox from a dependency, so these tests can put
 * a fake one in front of it and assert on the thing that actually matters: that
 * the *gate's* verdict decides an item is done, not the agent's claim.
 */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import {
	COMPLETION_SIGNAL,
	CONCURRENCY,
	GATE_COMMAND,
	IDLE_TIMEOUT_SECONDS,
	IMPLEMENTER_MODEL,
	MAX_ITERATIONS,
} from '../lib/config.mts';
import { classifyRun, implementItem, pool, promptArgsFor, renderOutcomes, tailOf } from '../lib/implement.mts';
import type { ImplementDeps, ImplementOutcome, SandboxSeam } from '../lib/implement.mts';
import type { PlanItem } from '../lib/plan.mts';

const ITEM: PlanItem = {
	issue: 7,
	title: 'Do the thing',
	role: 'standalone',
	workBranch: 'sandcastle/issue-7',
	mergeInto: null,
	base: 'main',
};

type FakeOptions = {
	commits?: number;
	signalled?: boolean;
	gateExitCode?: number;
	runThrows?: string;
};

type Recorded = {
	runOptions: Parameters<SandboxSeam['run']>[0][];
	execCommands: string[];
	closed: number;
};

function fakeDeps(options: FakeOptions = {}): { deps: ImplementDeps; recorded: Recorded } {
	const recorded: Recorded = { runOptions: [], execCommands: [], closed: 0 };

	const deps: ImplementDeps = {
		repoRoot: '/repo',
		agent: { name: 'fake' },
		promptFile: '/repo/.sandcastle/prompts/implement.md',
		log: () => {},
		createSandbox: async ({ branch }) => ({
			branch,
			async run(runOptions) {
				recorded.runOptions.push(runOptions);
				if (options.runThrows) throw new Error(options.runThrows);
				const commits = options.commits ?? 1;
				return {
					commits: Array.from({ length: commits }, (_, index) => ({ sha: `sha${index}` })),
					iterations: [{}],
					...(options.signalled === false ? {} : { completionSignal: COMPLETION_SIGNAL }),
					logFilePath: '/repo/.sandcastle/logs/issue-7.log',
				};
			},
			async exec(command) {
				recorded.execCommands.push(command);
				const exitCode = options.gateExitCode ?? 0;
				return { stdout: exitCode === 0 ? 'up to date' : 'tsc error', stderr: '', exitCode };
			},
			async close() {
				recorded.closed += 1;
				return {};
			},
		}),
	};

	return { deps, recorded };
}

describe('the gate is the arbiter', () => {
	it('runs the gate itself, in the sandbox, after the agent has stopped', async () => {
		const { deps, recorded } = fakeDeps();

		const outcome = await implementItem(deps, ITEM, 'body');

		assert.deepEqual(recorded.execCommands, [GATE_COMMAND]);
		assert.equal(outcome.status, 'implemented');
		assert.equal(outcome.gate?.passed, true);
	});

	it('runs a gate the branch cannot fail to carry', () => {
		// The gate is defined host-side and is nothing but npm scripts, so there
		// is no state where a work branch has no gate on it — which is what used
		// to leave items ungated with nobody at fault.
		assert.match(GATE_COMMAND, /npm run typecheck/);
		assert.match(GATE_COMMAND, /npm run lint:php/);
	});

	it('overrules an agent that signalled completion over a red gate', async () => {
		const { deps } = fakeDeps({ gateExitCode: 1, signalled: true });

		const outcome = await implementItem(deps, ITEM, 'body');

		assert.equal(outcome.signalled, true, 'the agent did claim to be done');
		assert.equal(outcome.status, 'gate-failed');
		assert.equal(outcome.gate?.exitCode, 1);
	});

	it('calls an item with no commits unworked, however green the gate', () => {
		assert.equal(classifyRun(0, { passed: true, exitCode: 0, tail: '' }), 'no-commits');
		assert.equal(classifyRun(1, { passed: true, exitCode: 0, tail: '' }), 'implemented');
		assert.equal(classifyRun(1, { passed: false, exitCode: 2, tail: '' }), 'gate-failed');
	});
});

describe('implementItem — one bad item never takes the batch down', () => {
	it('turns a failure into an outcome instead of throwing', async () => {
		const { deps } = fakeDeps({ runThrows: 'container died' });

		const outcome = await implementItem(deps, ITEM, 'body');

		assert.equal(outcome.status, 'error');
		assert.match(outcome.error ?? '', /container died/);
	});

	it('tears the sandbox down on the failure path too', async () => {
		const { deps, recorded } = fakeDeps({ runThrows: 'container died' });

		await implementItem(deps, ITEM, 'body');

		assert.equal(recorded.closed, 1, 'a leaked container holds the work branch checked out');
	});

	it('runs the agent with the budget the ticket set', async () => {
		const { deps, recorded } = fakeDeps();

		await implementItem(deps, ITEM, 'body');

		const [runOptions] = recorded.runOptions;
		assert.equal(runOptions?.maxIterations, MAX_ITERATIONS);
		assert.equal(runOptions?.idleTimeoutSeconds, IDLE_TIMEOUT_SECONDS);
		assert.equal(runOptions?.completionSignal, COMPLETION_SIGNAL);
	});
});

describe('promptArgsFor', () => {
	it('carries the issue and the branch the agent is on', () => {
		const args = promptArgsFor(ITEM, '  Body text  ');

		assert.equal(args.ISSUE_NUMBER, '7');
		assert.equal(args.ISSUE_TITLE, 'Do the thing');
		assert.equal(args.ISSUE_BODY, 'Body text');
		assert.equal(args.WORK_BRANCH, 'sandcastle/issue-7');
		assert.equal(args.BASE, 'main');
	});

	it('says so rather than passing an empty body', () => {
		assert.equal(promptArgsFor(ITEM, '   ').ISSUE_BODY, '(the issue has no body)');
	});

	it('supplies every placeholder the prompt template references', () => {
		const template = readFileSync(fileURLToPath(new URL('../prompts/implement.md', import.meta.url)), 'utf8');
		const referenced = new Set([...template.matchAll(/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/g)].map((match) => match[1]));
		const supplied = new Set(Object.keys(promptArgsFor(ITEM, 'body')));

		const missing = [...referenced].filter((key) => key !== undefined && !supplied.has(key));
		assert.deepEqual(missing, [], 'sandcastle fails the run on an unsubstituted placeholder');
	});

	it('is the completion signal the prompt asks the agent to emit', () => {
		const template = readFileSync(fileURLToPath(new URL('../prompts/implement.md', import.meta.url)), 'utf8');
		assert.ok(template.includes(COMPLETION_SIGNAL), `prompt must ask for ${COMPLETION_SIGNAL}`);
	});

	it('tells the agent not to push and not to open a PR', () => {
		const template = readFileSync(fileURLToPath(new URL('../prompts/implement.md', import.meta.url)), 'utf8');
		assert.match(template, /never `git push`/i);
		assert.match(template, /never open a pull request/i);
	});
});

describe('pool', () => {
	it('never has more than the limit in flight', async () => {
		let inFlight = 0;
		let peak = 0;

		await pool(Array.from({ length: 9 }, (_, index) => index), 3, async (item) => {
			inFlight += 1;
			peak = Math.max(peak, inFlight);
			await new Promise((resolve) => setTimeout(resolve, 5));
			inFlight -= 1;
			return item;
		});

		assert.equal(peak, 3);
	});

	it('returns results in input order regardless of completion order', async () => {
		const results = await pool([30, 10, 20], 3, async (item) => {
			await new Promise((resolve) => setTimeout(resolve, item));
			return item;
		});

		assert.deepEqual(results, [30, 10, 20]);
	});

	it('handles a batch smaller than the limit', async () => {
		assert.deepEqual(await pool([1], 3, async (item) => item * 2), [2]);
	});

	it('refuses a limit below one rather than hanging', async () => {
		await assert.rejects(() => pool([1], 0, async (item) => item), /at least 1/);
	});
});

describe('the budget the ticket set', () => {
	it('is three containers, 30 iterations, a 600s idle timeout and Opus 5', () => {
		assert.equal(CONCURRENCY, 3);
		assert.equal(MAX_ITERATIONS, 30);
		assert.equal(IDLE_TIMEOUT_SECONDS, 600);
		assert.equal(IMPLEMENTER_MODEL, 'claude-opus-5');
	});
});

describe('nothing outside finalize opens a PR, and finalize only ever opens one', () => {
	function harnessSources(): { name: string; path: string }[] {
		const libDir = fileURLToPath(new URL('../lib/', import.meta.url));
		return [
			...readdirSync(libDir)
				.filter((name) => name.endsWith('.mts'))
				.map((name) => ({ name: `lib/${name}`, path: join(libDir, name) })),
			{ name: 'main.mts', path: fileURLToPath(new URL('../main.mts', import.meta.url)) },
		];
	}

	it('has no gh pr write command outside lib/finalize.mts', () => {
		const offenders = harnessSources()
			.filter((source) => source.name !== 'lib/finalize.mts')
			.filter((source) =>
				/['"`]pr['"`]\s*,\s*['"`](create|merge|edit|close|ready)['"`]/.test(readFileSync(source.path, 'utf8'))
			)
			.map((source) => source.name);

		assert.deepEqual(offenders, [], `PRs are the finalize phase's business; found in ${offenders.join(', ')}`);
	});

	it('never merges, closes or undrafts a PR, finalize included', () => {
		// `pr create` is the harness's whole PR vocabulary. A merge from here
		// would put code on main without a human, which is the one thing the
		// design forbids outright.
		const offenders = harnessSources()
			.filter((source) => /['"`]pr['"`]\s*,\s*['"`](merge|close|ready)['"`]/.test(readFileSync(source.path, 'utf8')))
			.map((source) => source.name);

		assert.deepEqual(offenders, [], `only a human merges; found in ${offenders.join(', ')}`);
	});
});

describe('tailOf', () => {
	it('keeps the last lines, which is where a gate failure says why', () => {
		const output = Array.from({ length: 100 }, (_, index) => `line ${index}`).join('\n');
		const tail = tailOf(output, 3);

		assert.deepEqual(tail.split('\n'), ['line 97', 'line 98', 'line 99']);
	});
});

describe('renderOutcomes — a red gate has to say why', () => {
	const rendered = (gate: ImplementOutcome['gate'], status: ImplementOutcome['status']): string =>
		renderOutcomes([
			{
				issue: 7,
				branch: 'sandcastle/issue-7',
				status,
				commits: 2,
				iterations: 1,
				signalled: true,
				gate,
			} as ImplementOutcome,
		]);

	it('prints the gate tail on failure — the only place the reason exists', () => {
		// The agent's own log stops before the gate runs, so an exit code with
		// no tail leaves an operator with a red item and nowhere to look.
		const output = rendered({ passed: false, exitCode: 1, tail: 'PHP Parse error: syntax error' }, 'gate-failed');

		assert.match(output, /failed, exit 1/);
		assert.match(output, /PHP Parse error: syntax error/);
	});

	it('prefixes every tail line so it cannot be mistaken for harness output', () => {
		const output = rendered({ passed: false, exitCode: 2, tail: 'first\nsecond' }, 'gate-failed');

		assert.match(output, /^\s+\| first$/m);
		assert.match(output, /^\s+\| second$/m);
	});

	it('stays quiet on a green gate — there is nothing to explain', () => {
		const output = rendered({ passed: true, exitCode: 0, tail: 'Gate passed' }, 'implemented');

		assert.match(output, /gate passed/);
		assert.ok(!output.includes('| Gate passed'), 'a passing gate should not dump its output');
	});
});
