/**
 * A stand-in pass for `runlog.test.mts`, run as a child process.
 *
 *   node --import tsx runlog-pass.mts <repo-root> <ending> [<started-at-iso>]
 *
 * Starts a run log exactly as `main.mts` does, prints a little on both streams,
 * walks two phases, then ends the way `<ending>` names. A child, not an
 * in-process call, because what is under test is what reaches the file when the
 * process really exits, throws or is signalled — and wrapping the test runner's
 * own streams would test nothing but the runner.
 */
import { startRunLog } from '../../lib/runlog.mts';

const [root, ending, startedAt] = process.argv.slice(2);
if (root === undefined || ending === undefined) throw new Error('usage: runlog-pass.mts <repo-root> <ending> [<started-at>]');

const log = startRunLog(root, startedAt === undefined ? new Date() : new Date(startedAt));

log.phase('pre-flight');
console.log('Pre-flight: on main.');
console.error('warning: something worth seeing');
process.stdout.write('\u001b[1mbold on the terminal\u001b[22m\n');
log.phase('plan');
log.record('implement: #7 started — transcript /repo/.sandcastle/logs/issue-7.log');
// A partial line: the next record must still start on a line of its own.
process.stdout.write('no newline yet');

switch (ending) {
	case 'normal':
		console.log(' — done');
		break;

	case 'exit-code':
		console.log(' — done, but red');
		process.exitCode = 1;
		break;

	case 'exit':
		console.error('\nerror: a refusal, printed immediately before the exit');
		process.exit(3);

	case 'throw':
		throw new Error('a crash mid-phase');

	case 'throw-later':
		// Outside any promise: Node's `uncaughtException` origin.
		setTimeout(() => {
			throw new Error('a crash in a callback');
		}, 0);
		break;

	case 'throw-after-exit-listener':
		// What sandcastle's shutdown registry does once a sandbox is up: an
		// `exit` listener of its own, added after the run log's, that says where
		// it left the worktree.
		process.on('exit', () => {
			console.error('\nWorktree preserved at /tmp/worktree');
		});
		await new Promise((resolve) => setImmediate(resolve));
		throw new Error('a crash with a sandbox up');

	case 'reject':
		// What `await main()` does when `main()` rejects.
		await Promise.reject(new Error('a rejection mid-phase'));
		break;

	case 'signal':
		setInterval(() => {}, 1000);
		process.kill(process.pid, 'SIGINT');
		break;

	case 'signal-owned':
		// Something else owns the signal — as sandcastle does while a container
		// is up — and decides how the pass ends.
		process.on('SIGINT', () => {
			console.log('tearing down');
			process.exit(1);
		});
		setInterval(() => {}, 1000);
		process.kill(process.pid, 'SIGINT');
		break;

	default:
		throw new Error(`unknown ending: ${ending}`);
}
