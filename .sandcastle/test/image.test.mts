/**
 * The sandbox image is built on demand, so a first run needs no build step the
 * operator has to know about — and a second run does not pay for one.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { SANDBOX_IMAGE } from '../lib/config.mts';
import { ensureSandboxImage } from '../lib/image.mts';
import type { ImageDeps } from '../lib/image.mts';

function fakeDeps(present: boolean): { deps: ImageDeps; built: string[] } {
	const built: string[] = [];

	return {
		built,
		deps: {
			exists: () => present,
			build: (image) => built.push(image),
			log: () => {},
		},
	};
}

describe('ensureSandboxImage', () => {
	it('builds the image when it is missing', () => {
		const { deps, built } = fakeDeps(false);

		assert.equal(ensureSandboxImage(deps), 'built');
		assert.deepEqual(built, [SANDBOX_IMAGE]);
	});

	it('never rebuilds an image that is already there', () => {
		// A rebuild is minutes of network. Rebuilding after a Dockerfile change
		// is the operator's call, not something a routine pass decides.
		const { deps, built } = fakeDeps(true);

		assert.equal(ensureSandboxImage(deps), 'present');
		assert.deepEqual(built, []);
	});
});
