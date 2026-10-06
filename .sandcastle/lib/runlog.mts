/**
 * The run log: one file per pass, holding everything the harness printed and
 * how the pass ended.
 *
 * The terminal is where a pass narrates itself, and before this it was the only
 * place: a pass that died mid-phase left no trace of which phase it reached or
 * why it stopped. The 2026-09-17 pass reached `merge()` and never reached
 * `finalize()`, and nothing could say whether that was a crash, an exit or a
 * Ctrl-C.
 *
 * So, once, at start-up, the process's own `stdout` and `stderr` are wrapped:
 * every write still reaches the terminal exactly as before, and is also copied
 * to `.sandcastle/logs/run-<timestamp>.log`. No call site changes — every
 * `console.log`, every `log` callback handed to a phase, and sandcastle's own
 * terminal lines all go through the same two streams.
 *
 * The file is written **synchronously**. A pass that ends in `process.exit()`
 * gives an asynchronous write no chance to land, and the line printed just
 * before the exit is exactly the one that says why.
 *
 * Next to the copied output the file carries records of its own — never shown
 * on the terminal — each on its own line under {@link RECORD_PREFIX}: the pass
 * starting, every phase it enters, every item the implement phase starts, and a
 * final record naming the last phase reached and how the pass ended.
 */
import { closeSync, mkdirSync, openSync, writeSync } from 'node:fs';
import { join } from 'node:path';
import { stripVTControlCharacters } from 'node:util';

/**
 * The phases a pass moves through, in the order `main()` enters them.
 *
 * `start-up` is everything before the pre-flight: the Node floor and the
 * argument check. `freshness` covers what readies a branch for work — container
 * auth, the sandbox image, the epic branches and the freshness rules — and is
 * entered a second time, with `implement`, for the children's wave.
 */
export type Phase = 'start-up' | 'pre-flight' | 'plan' | 'freshness' | 'implement' | 'merge' | 'finalize';

/** Every line the run log writes itself starts with this, so they grep apart from the copied output. */
export const RECORD_PREFIX = '[run-log';

/** The signals whose arrival is recorded. Each still ends the pass as it would have without the log. */
export const RECORDED_SIGNALS = ['SIGINT', 'SIGTERM', 'SIGHUP'] as const;

export type RunLog = {
	/** Absolute path of this pass's file. */
	path: string;
	/** Enter a phase: the final record names the last one entered. Re-entering the current one is a no-op. */
	phase(phase: Phase): void;
	/** A line for the file only. The terminal never sees it. */
	record(message: string): void;
};

/**
 * A timestamp that sorts, is UTC and is safe in a file name: ISO 8601 with the
 * colons swapped for dashes — `2026-10-06T08-54-00.123Z`.
 */
export function runLogStamp(date: Date): string {
	return date.toISOString().replace(/:/g, '-');
}

/** Per-pass run log. Sits next to the per-item transcripts; `.sandcastle/logs/` is gitignored. */
export function runLogPathFor(repoRoot: string, startedAt: Date): string {
	return join(repoRoot, '.sandcastle', 'logs', `run-${runLogStamp(startedAt)}.log`);
}

/** How the pass came to an end, as the final record states it. */
export type Ending =
	| { kind: 'normal'; code: number }
	| { kind: 'exit'; code: number }
	| { kind: 'exception'; origin: 'uncaughtException' | 'unhandledRejection'; stack: string }
	| { kind: 'signal'; signal: string; code?: number };

/** The final record's text, without its prefix. */
export function describeEnding(ending: Ending, phase: Phase): string {
	const last = `last phase: ${phase}`;

	switch (ending.kind) {
		case 'normal':
			return `pass ended normally with exit code ${ending.code} — ${last}`;
		case 'exit':
			return `pass ended by process.exit(${ending.code}) — ${last}`;
		case 'exception':
			// Node's own name for the path the error took. A throw out of `main()`
			// arrives as `unhandledRejection` — `await main()` is a promise — so
			// the origin is reported as-is rather than read as two kinds of crash.
			return `pass ended by an uncaught exception (origin: ${ending.origin}) — ${last}\n${ending.stack}`;
		case 'signal':
			return `pass ended by ${ending.signal}${ending.code === undefined ? '' : ` (exit code ${ending.code})`} — ${last}`;
	}
}

type Write = (chunk: unknown, encoding?: unknown, callback?: unknown) => boolean;

/**
 * Start this pass's run log, and wire it into the process. Called once, before
 * anything else is printed.
 *
 * Opens a new file — exclusively, so a pass never overwrites or appends to
 * another pass's log — and from then on:
 *
 * - every write to `stdout` or `stderr` is copied to it, colour codes removed;
 * - a normal end, a `process.exit(n)`, an uncaught exception or unhandled
 *   rejection, and SIGINT / SIGTERM / SIGHUP each leave a final record.
 *
 * Nothing here changes how the pass ends. Exceptions are observed through
 * `uncaughtExceptionMonitor`, which leaves Node's own report and exit code
 * alone. A signal is recorded and then, when nothing else is listening for it,
 * re-raised, so Ctrl-C still stops the pass with the conventional status; when
 * something else is listening — sandcastle tears its containers down on one —
 * that listener decides, as it did before, and the record follows on exit.
 */
export function startRunLog(repoRoot: string, startedAt: Date = new Date()): RunLog {
	const { path, fd } = openExclusive(repoRoot, startedAt);

	let phase: Phase = 'start-up';
	let atLineStart = true;
	let ended = false;
	let drained = false;
	let signalled: string | null = null;
	let exception: Extract<Ending, { kind: 'exception' }> | null = null;

	// A log that cannot be written must never take the terminal, or the pass,
	// down with it: it is a record of the run, not part of it.
	const append = (text: string): void => {
		if (text === '') return;
		try {
			writeSync(fd, text);
			atLineStart = text.endsWith('\n');
		} catch {
			// Disk full, descriptor gone: the terminal still has everything.
		}
	};

	const record = (message: string): void => {
		if (ended) return;
		append(`${atLineStart ? '' : '\n'}${RECORD_PREFIX} ${new Date().toISOString()}] ${message}\n`);
	};

	const end = (ending: Ending): void => {
		if (ended) return;
		record(describeEnding(ending, phase));
		ended = true;
		try {
			closeSync(fd);
		} catch {
			// Already gone; nothing left to say.
		}
	};

	for (const stream of [process.stdout, process.stderr]) {
		const original = stream.write.bind(stream) as Write;
		(stream as unknown as { write: Write }).write = (chunk, encoding, callback) => {
			if (!ended) append(stripVTControlCharacters(textOf(chunk)));
			return original(chunk, encoding, callback);
		};
	}

	// `beforeExit` fires only when the event loop runs dry — never after an
	// explicit `process.exit()`, an exception or a signal — so it is what tells
	// a normal end from a refusal on the `exit` event below.
	process.on('beforeExit', () => {
		drained = true;
	});

	process.on('uncaughtExceptionMonitor', (error, origin) => {
		exception = { kind: 'exception', origin, stack: stackOf(error) };
	});

	process.on('exit', (code) => {
		if (exception) end(exception);
		else if (signalled) end({ kind: 'signal', signal: signalled, code });
		else if (drained) end({ kind: 'normal', code });
		else end({ kind: 'exit', code });
	});

	for (const signal of RECORDED_SIGNALS) {
		const onSignal = (): void => {
			process.removeListener(signal, onSignal);

			if (process.listenerCount(signal) > 0) {
				// Someone else owns what happens next; the exit record will say
				// it was this signal that set it off.
				signalled = signal;
				record(`received ${signal}`);
				return;
			}

			end({ kind: 'signal', signal });
			// Nobody else is listening, so raise it again with this listener gone:
			// whatever it did before the log existed — Node's default, or under
			// tsx an exit with 128 + the signal number — is what happens now.
			process.kill(process.pid, signal);
		};
		process.on(signal, onSignal);
	}

	record(
		`pass started — pid ${process.pid}, node ${process.version}, ` +
			`argv ${JSON.stringify(process.argv.slice(2))}, cwd ${process.cwd()}`
	);

	return {
		path,
		phase(next) {
			if (next === phase) return;
			phase = next;
			record(`phase: ${next}`);
		},
		record,
	};
}

/**
 * Open a new file for this pass. Exclusive: should two passes ever start in the
 * same millisecond, the second gets a suffix rather than the first's file.
 */
function openExclusive(repoRoot: string, startedAt: Date): { path: string; fd: number } {
	const base = runLogPathFor(repoRoot, startedAt);
	mkdirSync(join(repoRoot, '.sandcastle', 'logs'), { recursive: true });

	for (let attempt = 0; ; attempt += 1) {
		const path = attempt === 0 ? base : base.replace(/\.log$/, `-${attempt}.log`);
		try {
			return { path, fd: openSync(path, 'wx') };
		} catch (error) {
			if ((error as NodeJS.ErrnoException).code !== 'EEXIST') throw error;
		}
	}
}

function textOf(chunk: unknown): string {
	if (typeof chunk === 'string') return chunk;
	if (chunk instanceof Uint8Array) return Buffer.from(chunk.buffer, chunk.byteOffset, chunk.byteLength).toString('utf8');
	return String(chunk);
}

function stackOf(error: unknown): string {
	if (error instanceof Error) return error.stack ?? `${error.name}: ${error.message}`;
	return `(a non-Error value was thrown) ${String(error)}`;
}

