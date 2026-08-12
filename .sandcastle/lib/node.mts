/**
 * The Node floor, checked before anything imports sandcastle.
 *
 * sandcastle's prompt library reaches for `styleText` from `node:util`, which
 * only exists from Node 20.12. Under an older runtime the failure is an ESM
 * instantiation error thrown from inside `node_modules`, three frames deep and
 * naming a module nobody here wrote — so the harness says it itself, before the
 * first dynamic import, while the message can still name the fix.
 */

/** Lowest Node the harness runs on: the release that added `util.styleText`. */
export const MINIMUM_NODE = '20.12.0';

function parse(version: string): [number, number, number] {
	const [major = 0, minor = 0, patch = 0] = version.replace(/^v/, '').split('.').map(Number);
	return [major, minor, patch];
}

/** True when `version` is at or above `minimum`. Both are plain `x.y.z`. */
export function satisfies(version: string, minimum: string = MINIMUM_NODE): boolean {
	const [major, minor, patch] = parse(version);
	const [minMajor, minMinor, minPatch] = parse(minimum);

	if (major !== minMajor) return major > minMajor;
	if (minor !== minMinor) return minor > minMinor;
	return patch >= minPatch;
}

/**
 * The refusal for a runtime that is too old, or `null` when the version is
 * fine. A string rather than a throw: the caller prints it and exits, the same
 * way every other operator-facing refusal in the harness behaves.
 */
export function nodeVersionRefusal(version: string, minimum: string = MINIMUM_NODE): string | null {
	if (satisfies(version, minimum)) return null;

	return (
		`this harness needs Node ${minimum} or newer, and is running on ${version}. ` +
		'sandcastle imports `styleText` from `node:util`, which older runtimes do not export. ' +
		'Run `nvm use` (the repo pins its version in .nvmrc) and try again.'
	);
}
