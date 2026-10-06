/**
 * The run log, observed from outside a real process.
 *
 * Every ending under test is one a process cannot survive — an exit, a crash,
 * a signal — so each case runs `fixtures/runlog-pass.mts` as a child, lets it
 * end, then reads what reached the file and what reached the "terminal" (the
 * child's own stdout and stderr).
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import type { SpawnSyncReturns } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { after, describe, it } from 'node:test';
import { RECORD_PREFIX, runLogPathFor, runLogStamp } from '../lib/runlog.mts';

const REPO_ROOT = fileURLToPath(new URL('../..', import.meta.url));
const FIXTURE = fileURLToPath(new URL('./fixtures/runlog-pass.mts', import.meta.url));
const TSX_CLI = join(REPO_ROOT, 'node_modules', 'tsx', 'dist', 'cli.mjs');

const scratch = mkdtempSync(join(tmpdir(), 'sandcastle-runlog-'));
after(() => rmSync(scratch, { recursive: true, force: true }));

let passes = 0;

type Pass = {
	result: SpawnSyncReturns<string>;
	root: string;
	files: string[];
	/** The one run log the pass wrote. */
	content: string;
	/** Its final record, stack included. */
	final: string;
	/** Everything that is not one of the run log's own records. */
	copied: string;
};

/**
 * Run the stand-in pass to the given ending. `via: 'tsx'` goes through tsx's
 * CLI, as `npm run sandcastle` does — tsx keeps a parent process around the
 * real one and handles SIGINT in it, which is the path Ctrl-C really takes.
 */
function pass(ending: string, options: { root?: string; startedAt?: string; via?: 'node' | 'tsx' } = {}): Pass {
	passes += 1;
	const root = options.root ?? join(scratch, `pass-${passes}`);
	const args = [FIXTURE, root, ending, ...(options.startedAt === undefined ? [] : [options.startedAt])];
	const argv = options.via === 'tsx' ? [TSX_CLI, ...args] : ['--import', 'tsx', ...args];

	const result = spawnSync(process.execPath, argv, { cwd: REPO_ROOT, encoding: 'utf8', timeout: 60_000 });
	assert.equal(result.error, undefined, `the child did not run: ${result.error?.message}`);

	const dir = join(root, '.sandcastle', 'logs');
	const files = readdirSync(dir).sort();
	const content = readFileSync(join(dir, files[files.length - 1] ?? ''), 'utf8');

	const lines = content.trimEnd().split('\n');
	const lastRecord = lines.findLastIndex((line) => line.startsWith(RECORD_PREFIX));

	return {
		result,
		root,
		files,
		content,
		final: lines.slice(lastRecord).join('\n'),
		copied: lines.filter((line) => !line.startsWith(RECORD_PREFIX)).join('\n'),
	};
}

/** What the stand-in prints on stdout before it reaches its ending. */
const PRINTED_STDOUT = 'Pre-flight: on main.\n\u001b[1mbold on the terminal\u001b[22m\nno newline yet';

describe('runLogPathFor', () => {
	it('names a file under the logs directory by a UTC stamp with no colon in it', () => {
		const path = runLogPathFor('/repo', new Date('2026-09-17T21:04:05.678Z'));

		assert.equal(path, '/repo/.sandcastle/logs/run-2026-09-17T21-04-05.678Z.log');
	});

	it('sorts by name in the order the passes started', () => {
		const stamps = ['2026-09-17T21:04:05.678Z', '2026-09-17T09:00:00.000Z', '2026-10-01T00:00:00.000Z'].map((iso) =>
			runLogStamp(new Date(iso))
		);

		assert.deepEqual([...stamps].sort(), [stamps[1], stamps[0], stamps[2]]);
	});
});

describe('a pass with a run log', () => {
	it('copies what it printed to the file, leaves the terminal as it was, and ends on a normal-end record', () => {
		const { result, files, content, copied, final } = pass('normal');

		assert.equal(result.status, 0);
		assert.equal(files.length, 1);
		assert.match(files[0] ?? '', /^run-\d{4}-\d\d-\d\dT\d\d-\d\d-\d\d\.\d{3}Z\.log$/);

		// The terminal: exactly what was printed, colour codes and all, and none
		// of the run log's own records.
		assert.equal(result.stdout, `${PRINTED_STDOUT} — done\n`);
		assert.equal(result.stderr, 'warning: something worth seeing\n');

		// The file: the same text in the order it was written, colour removed.
		assert.equal(
			copied,
			['Pre-flight: on main.', 'warning: something worth seeing', 'bold on the terminal', 'no newline yet — done'].join(
				'\n'
			)
		);

		assert.match(content, /^\[run-log \S+\] pass started — pid \d+/);
		assert.match(content, /\] phase: pre-flight\n/);
		// A record after a partial line still starts a line of its own.
		assert.match(content, /\] implement: #7 started — transcript \/repo\/\.sandcastle\/logs\/issue-7\.log\n/);
		assert.match(final, /\] pass ended normally with exit code 0 — last phase: plan$/);
		assert.ok(content.endsWith(`${final}\n`), 'the final record is the last thing in the file');
	});

	it('reports a normal end with process.exitCode set as that exit code', () => {
		const { result, final } = pass('exit-code');

		assert.equal(result.status, 1);
		assert.match(final, /pass ended normally with exit code 1 — last phase: plan$/);
	});

	it('records a process.exit(n), and keeps the line printed immediately before it', () => {
		const { result, content, final } = pass('exit');

		assert.equal(result.status, 3);
		assert.match(final, /pass ended by process\.exit\(3\) — last phase: plan$/);

		const refusal = content.indexOf('error: a refusal, printed immediately before the exit');
		assert.ok(refusal !== -1, 'the line printed before the exit is in the file');
		assert.ok(refusal < content.indexOf(final));
	});

	it('records a throw out of the module — the shape of a crash in main() — with its stack', () => {
		const { result, final } = pass('throw');

		assert.equal(result.status, 1);
		assert.match(final, /pass ended by an uncaught exception \(origin: unhandledRejection\) — last phase: plan\n/);
		assert.match(final, /Error: a crash mid-phase\n\s+at .*runlog-pass\.mts/);
		// Node still reports it on the terminal as it always did.
		assert.match(result.stderr, /a crash mid-phase/);
	});

	it('records an exception thrown outside any promise, with its stack', () => {
		const { result, final } = pass('throw-later');

		assert.equal(result.status, 1);
		assert.match(final, /pass ended by an uncaught exception \(origin: uncaughtException\) — last phase: plan\n/);
		assert.match(final, /Error: a crash in a callback\n\s+at /);
	});

	it('records an unhandled rejection, with its stack', () => {
		const { result, final } = pass('reject');

		assert.equal(result.status, 1);
		assert.match(final, /origin: unhandledRejection\) — last phase: plan\n/);
		assert.match(final, /Error: a rejection mid-phase\n\s+at /);
	});

	it('records SIGINT, and the signal still kills the pass', () => {
		const { result, final } = pass('signal');

		assert.equal(result.signal, 'SIGINT');
		assert.match(final, /pass ended by SIGINT — last phase: plan$/);
	});

	it('records SIGINT under tsx, which still stops the pass with the conventional 130', () => {
		const { result, final } = pass('signal', { via: 'tsx' });

		assert.ok(result.status === 130 || result.signal === 'SIGINT', `status ${result.status}, signal ${result.signal}`);
		assert.match(final, /pass ended by SIGINT — last phase: plan$/);
	});

	it('leaves a signal to whoever else listens for it, and records it on the way out', () => {
		const { result, content, final } = pass('signal-owned');

		// sandcastle's own handler exits 1 after tearing its containers down;
		// the run log must not pre-empt it.
		assert.equal(result.status, 1);
		assert.match(result.stdout, /tearing down/);
		assert.match(content, /\] received SIGINT\n/);
		assert.match(final, /pass ended by SIGINT \(exit code 1\) — last phase: plan$/);
	});

	it('gives two passes in a row two files, overwriting neither — even started in the same millisecond', () => {
		const root = join(scratch, 'twice');
		const startedAt = '2026-09-17T21:04:05.678Z';

		const first = pass('normal', { root, startedAt });
		const second = pass('exit', { root, startedAt });

		assert.deepEqual(second.files, ['run-2026-09-17T21-04-05.678Z-1.log', 'run-2026-09-17T21-04-05.678Z.log']);
		assert.equal(
			readFileSync(join(root, '.sandcastle', 'logs', 'run-2026-09-17T21-04-05.678Z.log'), 'utf8'),
			first.content
		);
		assert.match(
			readFileSync(join(root, '.sandcastle', 'logs', 'run-2026-09-17T21-04-05.678Z-1.log'), 'utf8'),
			/process\.exit\(3\)/
		);
	});
});
