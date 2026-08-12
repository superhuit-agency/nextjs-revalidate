/**
 * The implement phase — the first mode that starts a container and the first
 * that writes code.
 *
 * One item, one sandbox. sandcastle cuts a worktree on the item's work branch,
 * bind-mounts it into the sandbox image and runs an implementer against the
 * issue. Commits land on the local work branch and stop there: nothing here
 * pushes, nothing here opens a PR, and the container has no `gh` and no GitHub
 * credential, so an agent cannot do either behind the harness's back.
 *
 * **The gate is the arbiter, and the harness runs it.** The agent is told to
 * run the gate's npm scripts before signalling completion, but that is the
 * agent's self-check; what decides the item is done is `sandbox.exec()` — the
 * harness running the same commands itself, in the same container, after the
 * agent has stopped. A green self-report over a red gate is a `gate-failed`
 * item.
 */
import { join } from 'node:path';
import {
	COMPLETION_SIGNAL,
	GATE_COMMAND,
	IDLE_TIMEOUT_SECONDS,
	IMPLEMENTER_MODEL,
	MAX_ITERATIONS,
	SANDBOX_IMAGE,
} from './config.mts';
import type { PlanItem } from './plan.mts';

/** Verdict of one gate run, as the harness observed it. */
export type GateVerdict = {
	passed: boolean;
	/** The gate's own exit code. */
	exitCode: number;
	/** Last few lines of gate output — enough to see why, not a whole build log. */
	tail: string;
};

export type ImplementOutcome = {
	issue: number;
	branch: string;
	status: 'implemented' | 'gate-failed' | 'no-commits' | 'error';
	commits: number;
	gate: GateVerdict | null;
	/** Whether the agent claimed completion, as distinct from the gate's verdict. */
	signalled: boolean;
	iterations: number;
	logFilePath?: string;
	error?: string;
};

/**
 * The issues the gate left green. What "the harness may act on this" means,
 * in one place: the merge phase and the finalize phase both start here, and
 * they must not drift apart on it.
 */
export function greenIssues(outcomes: readonly ImplementOutcome[]): Set<number> {
	return new Set(outcomes.filter((outcome) => outcome.status === 'implemented').map((outcome) => outcome.issue));
}

/** How many trailing lines of gate output an outcome carries. */
const GATE_TAIL_LINES = 40;

export function tailOf(output: string, lines: number = GATE_TAIL_LINES): string {
	return output.trimEnd().split('\n').slice(-lines).join('\n');
}

/**
 * What a finished run amounts to. Separated from the orchestration so the rule
 * — the gate decides, not the agent — is one testable expression.
 *
 * No commits outranks a green gate: the gate passing on an untouched checkout
 * says the branch was already shippable, not that the issue was worked.
 */
export function classifyRun(commits: number, gate: GateVerdict): ImplementOutcome['status'] {
	if (commits === 0) return 'no-commits';
	return gate.passed ? 'implemented' : 'gate-failed';
}

/**
 * Values substituted into `prompts/implement.md`.
 *
 * Substitution, not interpolation: sandcastle expands `` !`…` `` shell blocks
 * present in a prompt *template* inside the sandbox, but strips the marker from
 * substituted values, so an issue body carrying that syntax is inert. Building
 * the prompt by hand would give that up.
 */
export function promptArgsFor(item: PlanItem, body: string): Record<string, string> {
	return {
		ISSUE_NUMBER: String(item.issue),
		ISSUE_TITLE: item.title,
		ISSUE_BODY: body.trim() || '(the issue has no body)',
		WORK_BRANCH: item.workBranch,
		BASE: item.base,
		GATE_COMMAND,
	};
}

/**
 * Run `worker` over `items`, at most `limit` at a time, preserving input order
 * in the results. A worker that rejects is not caught here — callers turn a
 * failure into an outcome themselves, so one bad item never cancels the batch.
 */
export async function pool<T, R>(
	items: readonly T[],
	limit: number,
	worker: (item: T, index: number) => Promise<R>
): Promise<R[]> {
	if (limit < 1) throw new Error(`pool limit must be at least 1, got ${limit}`);

	const results: R[] = new Array(items.length);
	let next = 0;

	async function drain(): Promise<void> {
		for (;;) {
			const index = next;
			next += 1;
			const item = items[index];
			if (index >= items.length || item === undefined) return;
			results[index] = await worker(item, index);
		}
	}

	await Promise.all(Array.from({ length: Math.min(limit, items.length) }, drain));
	return results;
}

/* -- The sandcastle seam ---------------------------------------------------
 *
 * Only the fragment of sandcastle's surface this file uses, so a test can hand
 * `implementItem()` a fake sandbox and assert on what the harness does with a
 * gate verdict without a container, a network or an API key.
 */

export type SandboxSeam = {
	branch: string;
	run(options: {
		agent: unknown;
		promptFile: string;
		promptArgs: Record<string, string>;
		maxIterations: number;
		idleTimeoutSeconds: number;
		completionSignal: string;
		name: string;
		logging: { type: 'file'; path: string };
	}): Promise<{
		commits: { sha: string }[];
		iterations: unknown[];
		completionSignal?: string;
		logFilePath?: string;
	}>;
	exec(command: string, options?: { cwd?: string }): Promise<{ stdout: string; stderr: string; exitCode: number }>;
	close(): Promise<unknown>;
};

export type ImplementDeps = {
	/** Absolute path to the primary checkout. */
	repoRoot: string;
	/** Creates the sandbox for one item. Injected so tests need no Docker. */
	createSandbox: (options: { branch: string; baseBranch: string }) => Promise<SandboxSeam>;
	/** The agent provider handed to `sandbox.run()`. */
	agent: unknown;
	/** Absolute path to the prompt template. */
	promptFile: string;
	/** Called with progress lines; the harness's own reporting, not the agent's. */
	log: (message: string) => void;
};

/**
 * Work one item to a verdict.
 *
 * Never throws: an item that fails becomes an `error` outcome so the rest of
 * the batch still runs. The sandbox is torn down on every path — a leaked
 * container would hold a worktree checked out on the work branch and block the
 * next pass's freshness step.
 */
export async function implementItem(deps: ImplementDeps, item: PlanItem, body: string): Promise<ImplementOutcome> {
	const base: Pick<ImplementOutcome, 'issue' | 'branch'> = { issue: item.issue, branch: item.workBranch };
	let sandbox: SandboxSeam | null = null;

	try {
		deps.log(`#${item.issue}: starting sandbox on ${item.workBranch}`);
		sandbox = await deps.createSandbox({ branch: item.workBranch, baseBranch: item.base });

		const result = await sandbox.run({
			agent: deps.agent,
			promptFile: deps.promptFile,
			promptArgs: promptArgsFor(item, body),
			maxIterations: MAX_ITERATIONS,
			idleTimeoutSeconds: IDLE_TIMEOUT_SECONDS,
			completionSignal: COMPLETION_SIGNAL,
			name: `issue-${item.issue}`,
			logging: { type: 'file', path: logPathFor(deps.repoRoot, item.issue) },
		});

		const signalled = result.completionSignal !== undefined;
		deps.log(
			`#${item.issue}: agent stopped after ${result.iterations.length} iteration(s), ` +
				`${result.commits.length} commit(s), ${signalled ? 'signalled complete' : 'no completion signal'}`
		);

		// The verdict, taken by the harness rather than believed from the agent.
		const gate = await runGate(sandbox);
		deps.log(`#${item.issue}: gate ${gate.passed ? 'passed' : `failed (exit ${gate.exitCode})`}`);

		return {
			...base,
			status: classifyRun(result.commits.length, gate),
			commits: result.commits.length,
			gate,
			signalled,
			iterations: result.iterations.length,
			...(result.logFilePath === undefined ? {} : { logFilePath: result.logFilePath }),
		};
	} catch (error) {
		return {
			...base,
			status: 'error',
			commits: 0,
			gate: null,
			signalled: false,
			iterations: 0,
			error: error instanceof Error ? error.message : String(error),
		};
	} finally {
		if (sandbox) {
			try {
				await sandbox.close();
			} catch (error) {
				deps.log(`#${item.issue}: warning — sandbox teardown failed: ${(error as Error).message}`);
			}
		}
	}
}

/**
 * Run the gate inside the sandbox and read its exit code.
 *
 * The command is defined host-side, in `config.mts`, and needs nothing from the
 * branch but the npm scripts every checkout carries — so there is no state where
 * the gate is unavailable and an item comes back ungated.
 */
export async function runGate(sandbox: SandboxSeam): Promise<GateVerdict> {
	const result = await sandbox.exec(GATE_COMMAND);

	return {
		passed: result.exitCode === 0,
		exitCode: result.exitCode,
		tail: tailOf(`${result.stdout}\n${result.stderr}`),
	};
}

/** Per-item log file. `.sandcastle/logs/` is gitignored. */
export function logPathFor(repoRoot: string, issue: number): string {
	return join(repoRoot, '.sandcastle', 'logs', `issue-${issue}.log`);
}

/**
 * Build the real dependencies, importing sandcastle only at the point a
 * container is actually wanted. The read-only modes stay free of it.
 */
export async function realDeps(repoRoot: string, log: (message: string) => void): Promise<ImplementDeps> {
	const { claudeCode, createSandbox } = await import('@ai-hero/sandcastle');
	const { docker } = await import('@ai-hero/sandcastle/sandboxes/docker');

	const sandboxProvider = docker({ imageName: SANDBOX_IMAGE });

	return {
		repoRoot,
		agent: claudeCode(IMPLEMENTER_MODEL),
		promptFile: join(repoRoot, '.sandcastle', 'prompts', 'implement.md'),
		log,
		createSandbox: async ({ branch, baseBranch }) =>
			(await createSandbox({ branch, baseBranch, sandbox: sandboxProvider, cwd: repoRoot })) as unknown as SandboxSeam,
	};
}

/** Render a batch's outcomes for the operator. */
export function renderOutcomes(outcomes: readonly ImplementOutcome[]): string {
	const lines: string[] = [];

	for (const outcome of outcomes) {
		lines.push(`  #${outcome.issue} — ${outcome.status}`);
		lines.push(`      branch:  ${outcome.branch}`);
		lines.push(`      commits: ${outcome.commits} over ${outcome.iterations} iteration(s)`);
		if (outcome.gate) {
			lines.push(`      gate:    ${outcome.gate.passed ? 'passed' : `failed, exit ${outcome.gate.exitCode}`}`);
			// The tail is the only place the *reason* exists. The agent's own
			// transcript stops before the gate runs, so a bare exit code would
			// leave an operator with a red item and nowhere to look.
			if (!outcome.gate.passed && outcome.gate.tail.trim() !== '') {
				for (const line of outcome.gate.tail.split('\n')) lines.push(`      | ${line}`);
			}
		}
		if (outcome.signalled && outcome.gate && !outcome.gate.passed) {
			lines.push('      note:    the agent signalled completion; the gate disagreed');
		}
		if (outcome.logFilePath) lines.push(`      log:     ${outcome.logFilePath}`);
		if (outcome.error) lines.push(`      error:   ${outcome.error}`);
	}

	return lines.join('\n');
}
