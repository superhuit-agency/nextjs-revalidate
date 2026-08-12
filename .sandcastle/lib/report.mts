import type { GatherResult, Pruned } from './gather.mts';

function describePrune(pruned: Pruned): string {
	const reason = pruned.reason;
	if (reason.kind === 'open-blockers') {
		const list = reason.blockers.map((b) => `#${b.number} (${b.source})`).join(', ');
		return `blocked by ${list}`;
	}
	return `already worked — PR #${reason.pr.number} (${reason.pr.state}) on ${pruned.candidate.workBranch}`;
}

/**
 * The gather step in as few lines as it can be said.
 *
 * One line for the sweep, one line per pruned issue. The batch itself is not
 * listed here — the plan lists it a moment later, with the branch each item is
 * cut from, and printing it twice only makes the run harder to read. What
 * survives is the part nothing downstream repeats: why an issue was left out.
 */
export function renderText(result: GatherResult): string {
	const lines: string[] = [
		`${result.repo}: ${result.considered} open issue(s) labelled ${result.label} — ` +
			`${result.eligible.length} ready, ${result.pruned.length} pruned.`,
	];

	for (const pruned of result.pruned) {
		lines.push(`  pruned #${pruned.candidate.number} ${pruned.candidate.title} — ${describePrune(pruned)}`);
	}

	return lines.join('\n');
}
