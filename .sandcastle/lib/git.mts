/**
 * Every git call the harness makes goes through this file.
 *
 * Two things here are load-bearing and must not be worked around elsewhere:
 *
 * - `pushBranch()` is the *only* push in the harness. There is no branch
 *   protection on this repo and there will not be — the harness holds an ADMIN
 *   token, so protection-with-admin-bypass would not constrain it, and
 *   no-bypass protection would block the existing direct-push release flow.
 *   Enforcement is structural instead: one function, which throws on any
 *   branch outside `sandcastle/` and never passes `--force`.
 * - `ensureLocalBranch()` fast-forwards only, and refuses to move a branch
 *   carrying unpushed commits. A blind `git branch -f <b> origin/<b>` silently
 *   discards squash-merges from earlier cycles.
 *
 * The primary checkout is untouchable: nothing here runs `git checkout` in it.
 * Anything needing a working tree gets its own worktree via `withWorktree()`.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { BRANCH_PREFIX } from './config.mts';

export type GitResult = { ok: true; stdout: string } | { ok: false; error: string };

/** Run `git` in `cwd` and return trimmed stdout. Throws on a non-zero exit. */
export function git(cwd: string, args: string[]): string {
	return execFileSync('git', args, { cwd, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }).trim();
}

/** Run `git` without throwing — for probes and for conflict-carrying commands. */
export function gitTry(cwd: string, args: string[]): GitResult {
	try {
		return { ok: true, stdout: git(cwd, args) };
	} catch (error) {
		const err = error as { stderr?: Buffer | string; stdout?: Buffer | string; message?: string };
		const stderr = typeof err.stderr === 'string' ? err.stderr : err.stderr?.toString();
		const stdout = typeof err.stdout === 'string' ? err.stdout : err.stdout?.toString();
		return { ok: false, error: (stderr || stdout || err.message || 'unknown git failure').trim() };
	}
}

/** `owner/name`-independent: the branch the primary checkout is on. */
export function currentBranch(cwd: string): string {
	return git(cwd, ['rev-parse', '--abbrev-ref', 'HEAD']);
}

export function localBranchExists(cwd: string, branch: string): boolean {
	return gitTry(cwd, ['rev-parse', '--verify', '--quiet', `refs/heads/${branch}`]).ok;
}

/**
 * Whether origin carries the branch, read from the remote-tracking ref rather
 * than the network. Call `fetch()` first — a stale ref here would send a branch
 * that *is* on origin down the rebase path, and a force-push over an open PR's
 * review comments is exactly what the freshness rule exists to prevent.
 */
export function originBranchExists(cwd: string, branch: string): boolean {
	return gitTry(cwd, ['rev-parse', '--verify', '--quiet', `refs/remotes/origin/${branch}`]).ok;
}

export function fetch(cwd: string): void {
	git(cwd, ['fetch', '--prune', 'origin']);
}

/** Commits on `branch` that are not on `base`. */
export function commitsAhead(cwd: string, branch: string, base: string): number {
	return Number(git(cwd, ['rev-list', '--count', `${base}..${branch}`]));
}

/** Worktree paths that currently have `branch` checked out, primary included. */
export function checkoutsOf(cwd: string, branch: string): string[] {
	const porcelain = git(cwd, ['worktree', 'list', '--porcelain']);
	const paths: string[] = [];
	let path: string | null = null;

	for (const line of porcelain.split('\n')) {
		if (line.startsWith('worktree ')) path = line.slice('worktree '.length);
		else if (line === `branch refs/heads/${branch}` && path) paths.push(path);
	}

	return paths;
}

export type EnsureLocalBranchOutcome =
	| { action: 'no-remote' }
	| { action: 'created' }
	| { action: 'up-to-date' }
	| { action: 'fast-forwarded'; behind: number }
	| { action: 'refused-unpushed'; ahead: number };

/**
 * Bring a local branch up to its origin counterpart — fast-forward only.
 *
 * Refuses to move a branch that carries commits origin does not have. Those
 * commits may be the only copy of work from an earlier cycle, and `branch -f`
 * would drop them without a word.
 */
export function ensureLocalBranch(cwd: string, branch: string): EnsureLocalBranchOutcome {
	if (!originBranchExists(cwd, branch)) return { action: 'no-remote' };

	const remote = `origin/${branch}`;

	if (!localBranchExists(cwd, branch)) {
		git(cwd, ['branch', '--track', branch, remote]);
		return { action: 'created' };
	}

	const ahead = commitsAhead(cwd, branch, remote);
	if (ahead > 0) return { action: 'refused-unpushed', ahead };

	const behind = commitsAhead(cwd, remote, branch);
	if (behind === 0) return { action: 'up-to-date' };

	// `branch -f` cannot move a ref that is checked out; say so rather than
	// letting git's message surface from three layers down.
	const checkouts = checkoutsOf(cwd, branch);
	if (checkouts.length > 0) {
		throw new Error(`cannot fast-forward ${branch}: checked out at ${checkouts.join(', ')}`);
	}

	git(cwd, ['branch', '--force', branch, remote]);
	return { action: 'fast-forwarded', behind };
}

/**
 * Arguments for the one push the harness is allowed to make. Split out from
 * `pushBranch()` so the guard and the absence of `--force` are assertable
 * without a remote.
 */
export function pushArgs(branch: string): string[] {
	assertWritableBranch(branch, 'push');
	return ['push', '--set-upstream', 'origin', branch];
}

/**
 * The prefix rule itself, in one place.
 *
 * `pushBranch()` is not quite the only way a ref reaches origin: GitHub's
 * `createLinkedBranch` mutation creates one server-side, and it goes nowhere
 * near git. That call carries the same rule (see `lib/epic.mts`), and it
 * carries it by calling this — so the prefix can only ever be changed here.
 */
export function assertWritableBranch(branch: string, verb: string): void {
	if (!branch.startsWith(BRANCH_PREFIX)) {
		throw new Error(`refusing to ${verb} ${branch}: only ${BRANCH_PREFIX}* branches may be written to origin`);
	}
}

/**
 * The push chokepoint. Every push in the harness goes through here; agents
 * never invoke `git push` at all.
 */
export function pushBranch(cwd: string, branch: string): void {
	git(cwd, pushArgs(branch));
}

/** Where a branch's scratch worktree lives. Gitignored; see `.sandcastle/.gitignore`. */
export function worktreePathFor(cwd: string, branch: string): string {
	return join(cwd, '.sandcastle', 'worktrees', branch.replace(/[^A-Za-z0-9._-]+/g, '-'));
}

/**
 * Run `fn` against a throwaway worktree with `branch` checked out, then remove
 * it. The primary checkout is never checked out of its own branch.
 */
export function withWorktree<T>(cwd: string, branch: string, fn: (worktree: string) => T): T {
	const path = worktreePathFor(cwd, branch);

	git(cwd, ['worktree', 'prune']);
	rmSync(path, { recursive: true, force: true });
	mkdirSync(dirname(path), { recursive: true });
	git(cwd, ['worktree', 'add', path, branch]);

	try {
		return fn(path);
	} finally {
		gitTry(cwd, ['worktree', 'remove', '--force', path]);
		rmSync(path, { recursive: true, force: true });
		gitTry(cwd, ['worktree', 'prune']);
	}
}
