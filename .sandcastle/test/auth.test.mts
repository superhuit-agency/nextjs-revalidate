import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { parseEnvFile, resolveContainerAuth } from '../lib/auth.mts';

describe('parseEnvFile — a mirror of sandcastle’s own resolver', () => {
	it('reads plain, quoted and empty assignments', () => {
		const vars = parseEnvFile(
			['# a comment', '', 'PLAIN=value', 'DOUBLE="quoted value"', "SINGLE='quoted'", 'EMPTY=', '  SPACED = padded '].join(
				'\n'
			)
		);

		assert.deepEqual(vars, {
			PLAIN: 'value',
			DOUBLE: 'quoted value',
			SINGLE: 'quoted',
			EMPTY: '',
			SPACED: 'padded',
		});
	});

	it('keeps a declared-but-empty key, which is how a process-env fallback is requested', () => {
		assert.ok('CLAUDE_CODE_OAUTH_TOKEN' in parseEnvFile('CLAUDE_CODE_OAUTH_TOKEN='));
	});

	it('ignores lines that are not assignments', () => {
		assert.deepEqual(parseEnvFile(['# comment', 'not an assignment', ''].join('\n')), {});
	});
});

describe('resolveContainerAuth', () => {
	it('takes a value straight from the env file', () => {
		const outcome = resolveContainerAuth({ CLAUDE_CODE_OAUTH_TOKEN: 'sk-token' }, {});
		assert.deepEqual(outcome, { ok: true, key: 'CLAUDE_CODE_OAUTH_TOKEN', source: 'env-file' });
	});

	it('falls back to the process environment for a declared-but-empty key', () => {
		const outcome = resolveContainerAuth({ ANTHROPIC_API_KEY: '' }, { ANTHROPIC_API_KEY: 'sk-ant' });
		assert.deepEqual(outcome, { ok: true, key: 'ANTHROPIC_API_KEY', source: 'process-env' });
	});

	it('prefers the OAuth token when both are available', () => {
		const outcome = resolveContainerAuth({ CLAUDE_CODE_OAUTH_TOKEN: 'a', ANTHROPIC_API_KEY: 'b' }, {});
		assert.equal(outcome.ok && outcome.key, 'CLAUDE_CODE_OAUTH_TOKEN');
	});

	it('refuses a key the env file does not name, however loudly the host exports it', () => {
		// The load-bearing case: sandcastle forwards only keys named in the file,
		// so a host-only export would produce a container with no credential and
		// an auth failure three layers down.
		const outcome = resolveContainerAuth({}, { ANTHROPIC_API_KEY: 'sk-ant' });

		assert.equal(outcome.ok, false);
		assert.match(outcome.ok === false ? outcome.reason : '', /only forwards keys named in that file/);
	});

	it('refuses when a declared key resolves to nothing anywhere', () => {
		const outcome = resolveContainerAuth({ CLAUDE_CODE_OAUTH_TOKEN: '' }, {});

		assert.equal(outcome.ok, false);
		assert.match(outcome.ok === false ? outcome.reason : '', /declares CLAUDE_CODE_OAUTH_TOKEN with no value/);
	});
});
