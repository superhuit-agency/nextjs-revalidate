/**
 * A run under an old Node used to die inside `node_modules` with a
 * `styleText` import error. The floor is checked here instead, where the
 * message can name the fix.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { MINIMUM_NODE, nodeVersionRefusal, satisfies } from '../lib/node.mts';

describe('satisfies', () => {
	it('accepts the floor itself and anything above it', () => {
		assert.equal(satisfies('v20.12.0'), true);
		assert.equal(satisfies('v22.11.0'), true);
		assert.equal(satisfies('v24.14.1'), true);
	});

	it('rejects a runtime below the floor, including a near miss', () => {
		// v20.11 is the version this actually bites on: same major, no styleText.
		assert.equal(satisfies('v20.11.1'), false);
		assert.equal(satisfies('v18.20.8'), false);
	});
});

describe('nodeVersionRefusal', () => {
	it('stays quiet on a supported runtime', () => {
		assert.equal(nodeVersionRefusal('v24.14.1'), null);
	});

	it('names the floor, the runtime and the fix', () => {
		const refusal = nodeVersionRefusal('v18.20.8');

		assert.ok(refusal !== null);
		assert.match(refusal, new RegExp(MINIMUM_NODE.replace(/\./g, '\\.')));
		assert.match(refusal, /v18\.20\.8/);
		assert.match(refusal, /nvm use/);
	});
});
