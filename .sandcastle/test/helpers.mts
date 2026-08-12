/**
 * Scratch repositories for the freshness and push tests.
 *
 * The freshness rules are all about what git actually does to real refs, so
 * these tests exercise them against a real repository with a real origin
 * rather than a mocked git.
 */
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

export type Scratch = {
	/** Working clone — stands in for the primary checkout. */
	repo: string;
	/** Bare repository serving as `origin`. */
	origin: string;
	cleanup: () => void;
};

export function run(cwd: string, args: string[]): string {
	// stderr piped, not inherited: git narrates every clone and push, and that
	// noise buries the test reporter's own output.
	return execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

/** A repo with an `origin`, one commit on `main`, and identity configured. */
export function makeScratch(): Scratch {
	const root = mkdtempSync(join(tmpdir(), 'sandcastle-test-'));
	const origin = join(root, 'origin.git');
	const repo = join(root, 'repo');

	mkdirSync(origin);
	run(origin, ['init', '--bare', '--initial-branch=main']);
	run(root, ['clone', origin, repo]);
	run(repo, ['config', 'user.email', 'harness@example.test']);
	run(repo, ['config', 'user.name', 'Harness Test']);

	commit(repo, 'README.md', '# scratch\n', 'initial commit');
	run(repo, ['push', '-u', 'origin', 'main']);

	return {
		repo,
		origin,
		cleanup: () => rmSync(root, { recursive: true, force: true }),
	};
}

export function commit(cwd: string, file: string, contents: string, message: string): void {
	writeFileSync(join(cwd, file), contents);
	run(cwd, ['add', file]);
	run(cwd, ['commit', '-m', message]);
}

/**
 * Add a commit to `branch`. The branch the scratch repo has checked out is
 * committed to in place; anything else gets a scratch worktree, so staging
 * test state never checks the primary checkout out of its own branch and the
 * "primary checkout untouched" assertions stay meaningful.
 */
export function commitOnBranch(cwd: string, branch: string, file: string, contents: string, message: string): void {
	if (run(cwd, ['rev-parse', '--abbrev-ref', 'HEAD']) === branch) {
		commit(cwd, file, contents, message);
		return;
	}

	const worktree = mkdtempSync(join(tmpdir(), 'sandcastle-stage-'));
	rmSync(worktree, { recursive: true, force: true });
	run(cwd, ['worktree', 'add', worktree, branch]);
	try {
		commit(worktree, file, contents, message);
	} finally {
		run(cwd, ['worktree', 'remove', '--force', worktree]);
	}
}

export function headOf(cwd: string, ref: string): string {
	return run(cwd, ['rev-parse', ref]);
}
