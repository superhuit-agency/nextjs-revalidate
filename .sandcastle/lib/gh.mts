import { execFileSync } from 'node:child_process';

export type GhResult = { ok: true; stdout: string } | { ok: false; error: string };

/**
 * Run `gh` and return stdout. Throws on a non-zero exit.
 */
export function gh(args: string[]): string {
	return execFileSync('gh', args, { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
}

/**
 * Run `gh` without throwing — callers that have a fallback path (issue
 * dependencies, which are not enabled on every repo) need the error text.
 */
export function ghTry(args: string[]): GhResult {
	try {
		return { ok: true, stdout: gh(args) };
	} catch (error) {
		const err = error as { stderr?: Buffer | string; message?: string };
		const stderr = typeof err.stderr === 'string' ? err.stderr : err.stderr?.toString();
		return { ok: false, error: (stderr || err.message || 'unknown gh failure').trim() };
	}
}

export function ghJson<T>(args: string[]): T {
	return JSON.parse(gh(args)) as T;
}

export function ghJsonTry<T>(args: string[]): { ok: true; value: T } | { ok: false; error: string } {
	const result = ghTry(args);
	if (!result.ok) return result;
	try {
		return { ok: true, value: JSON.parse(result.stdout) as T };
	} catch (error) {
		return { ok: false, error: `unparseable gh output: ${(error as Error).message}` };
	}
}

/** `owner/name` of the repository the current checkout points at. */
export function currentRepo(): string {
	return ghJson<{ nameWithOwner: string }>(['repo', 'view', '--json', 'nameWithOwner']).nameWithOwner;
}
