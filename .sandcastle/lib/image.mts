/**
 * The sandbox image, built on demand.
 *
 * sandcastle ships `sandcastle docker build-image`, which builds
 * `.sandcastle/Dockerfile` and bakes the host user's UID/GID in as build
 * arguments — the alignment its docker provider then insists on at run time.
 * There is no build script here because there is nothing left for one to do.
 *
 * Built only when it is missing. Rebuilding after a Dockerfile change is the
 * operator's call: an image rebuild is minutes of network, and doing it on
 * every pass would make a routine run unpredictable.
 */
import { execFileSync } from 'node:child_process';
import { SANDBOX_IMAGE } from './config.mts';

export type ImageDeps = {
	exists: (image: string) => boolean;
	build: (image: string) => void;
	log: (message: string) => void;
};

export type ImageOutcome = 'present' | 'built';

export function ensureSandboxImage(deps: ImageDeps, image: string = SANDBOX_IMAGE): ImageOutcome {
	if (deps.exists(image)) {
		deps.log(`${image} is present.`);
		return 'present';
	}

	deps.log(`${image} is not built yet — building it from .sandcastle/Dockerfile (this takes a few minutes).`);
	deps.build(image);
	deps.log(`${image} built.`);

	return 'built';
}

export function realImageDeps(cwd: string, log: (message: string) => void): ImageDeps {
	return {
		log,
		exists: (image) => {
			try {
				execFileSync('docker', ['image', 'inspect', image], { cwd, stdio: 'ignore' });
				return true;
			} catch {
				return false;
			}
		},
		// Inherited stdio: a docker build is long enough that silence reads as a
		// hang, and its own output is the only progress there is.
		build: (image) =>
			execFileSync('npx', ['sandcastle', 'docker', 'build-image', '--image-name', image], { cwd, stdio: 'inherit' }),
	};
}
