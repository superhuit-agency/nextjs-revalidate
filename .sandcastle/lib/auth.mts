/**
 * Container auth to Claude — the spec's one open item, decided here.
 *
 * **The decision: an environment variable out of the gitignored
 * `.sandcastle/.env`. Host credentials are not mounted.**
 *
 * What sandcastle 0.12.0 actually supports settles it. Its env resolver reads
 * `.sandcastle/.env`, and for every key *named there* takes the file's value or
 * falls back to `process.env` — so a key absent from that file never reaches
 * the container, whatever the host's environment holds. There is no credential
 * mount: `docker({ mounts })` would take an arbitrary host path, but on macOS
 * Claude Code keeps subscription credentials in the login Keychain, so there is
 * no file to mount in the first place. `claude setup-token` turns that
 * subscription into a long-lived token that *is* just a string, which is what
 * the container gets.
 *
 * Either key works, and both are checked here so the run fails on the host with
 * one clear line rather than inside a container on a Claude CLI auth error:
 *
 * - `CLAUDE_CODE_OAUTH_TOKEN` — from `claude setup-token`; bills the operator's
 *   Claude subscription. Preferred, and what `.env.example` puts first.
 * - `ANTHROPIC_API_KEY` — bills the API account instead.
 *
 * `.sandcastle/.env` is gitignored and is the only place a secret lives. The
 * image carries none; the value arrives as a `docker run -e` at start time.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/** Keys the Claude CLI will authenticate with, in the order they are preferred. */
export const AUTH_KEYS = ['CLAUDE_CODE_OAUTH_TOKEN', 'ANTHROPIC_API_KEY'] as const;

export type AuthKey = (typeof AUTH_KEYS)[number];

export type AuthOutcome =
	| { ok: true; key: AuthKey; source: 'env-file' | 'process-env' }
	| { ok: false; reason: string };

/**
 * Parse a `.env` file the way sandcastle's own resolver does.
 *
 * Deliberately a mirror, not an approximation: this check exists to predict
 * what sandcastle will forward, and a parser that disagreed with it would
 * either pass a run that then fails in the container, or refuse one that would
 * have worked.
 */
export function parseEnvFile(contents: string): Record<string, string> {
	const vars: Record<string, string> = {};

	for (const line of contents.split('\n')) {
		const trimmed = line.trim();
		if (!trimmed || trimmed.startsWith('#')) continue;

		const eq = trimmed.indexOf('=');
		if (eq === -1) continue;

		const key = trimmed.slice(0, eq).trim();
		let value = trimmed.slice(eq + 1).trim();

		const doubleQuoted = value.length >= 2 && value.startsWith('"') && value.endsWith('"');
		const singleQuoted = value.length >= 2 && value.startsWith("'") && value.endsWith("'");
		if (doubleQuoted || singleQuoted) value = value.slice(1, -1);

		vars[key] = value;
	}

	return vars;
}

/**
 * Which key the container will authenticate with, given what `.sandcastle/.env`
 * declares and what the host's environment holds.
 *
 * A key declared with an empty value is not a failure — that is the documented
 * way to say "take it from my shell". A key that is *not* declared is invisible
 * to sandcastle even when the host has it exported, which is the trap this
 * function exists to catch.
 */
export function resolveContainerAuth(
	declared: Record<string, string>,
	processEnv: Record<string, string | undefined>
): AuthOutcome {
	for (const key of AUTH_KEYS) {
		if (!(key in declared)) continue;

		const fromFile = declared[key];
		if (fromFile) return { ok: true, key, source: 'env-file' };

		const fromProcess = processEnv[key];
		if (fromProcess) return { ok: true, key, source: 'process-env' };
	}

	const undeclared = AUTH_KEYS.filter((key) => !(key in declared));
	const declaredButEmpty = AUTH_KEYS.filter((key) => key in declared && !declared[key] && !processEnv[key]);

	if (declaredButEmpty.length > 0) {
		return {
			ok: false,
			reason:
				`.sandcastle/.env declares ${declaredButEmpty.join(' and ')} with no value, and the host environment ` +
				`does not supply one either. Fill one in, or export it in the shell running the harness.`,
		};
	}

	return {
		ok: false,
		reason:
			`.sandcastle/.env declares none of ${undeclared.join(', ')}. sandcastle only forwards keys named in that ` +
			`file, so exporting one in your shell is not enough. Copy .sandcastle/.env.example to .sandcastle/.env ` +
			`and fill it in — \`claude setup-token\` produces a CLAUDE_CODE_OAUTH_TOKEN.`,
	};
}

/**
 * The same question, answered against the repository on disk. A missing
 * `.sandcastle/.env` is reported as declaring nothing rather than as an I/O
 * error — it is the ordinary first-run state.
 */
export function containerAuth(repoRoot: string, processEnv: Record<string, string | undefined> = process.env): AuthOutcome {
	let contents = '';
	try {
		contents = readFileSync(join(repoRoot, '.sandcastle', '.env'), 'utf8');
	} catch {
		return {
			ok: false,
			reason:
				'no .sandcastle/.env — copy .sandcastle/.env.example to .sandcastle/.env and fill in one of ' +
				`${AUTH_KEYS.join(' or ')}. \`claude setup-token\` produces a CLAUDE_CODE_OAUTH_TOKEN.`,
		};
	}

	return resolveContainerAuth(parseEnvFile(contents), processEnv);
}
