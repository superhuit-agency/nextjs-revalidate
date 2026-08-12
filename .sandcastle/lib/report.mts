import type { Candidate, GatherResult, Pruned } from './gather.mts';

function role(candidate: Candidate): string {
	if (candidate.children.length > 0) return `epic (${candidate.children.length} sub-issue(s))`;
	if (candidate.parent !== null) return `child of #${candidate.parent} (${candidate.parentSource})`;
	return 'standalone';
}

function describePrune(pruned: Pruned): string {
	const reason = pruned.reason;
	if (reason.kind === 'open-blockers') {
		const list = reason.blockers.map((b) => `#${b.number} (${b.source})`).join(', ');
		return `blocked by ${list}`;
	}
	return `already worked — PR #${reason.pr.number} (${reason.pr.state}) on ${pruned.candidate.workBranch}`;
}

export function renderText(result: GatherResult): string {
	const lines: string[] = [];

	lines.push(`repo:       ${result.repo}`);
	lines.push(`label:      ${result.label}`);
	lines.push(`considered: ${result.considered} open issue(s)`);
	lines.push('');

	lines.push(`Selected batch — ${result.eligible.length} issue(s):`);
	if (result.eligible.length === 0) {
		lines.push('  (nothing eligible)');
	} else {
		for (const candidate of result.eligible) {
			lines.push(`  #${candidate.number} ${candidate.title}`);
			lines.push(`      branch: ${candidate.workBranch}`);
			lines.push(`      role:   ${role(candidate)}`);
			lines.push(`      ${candidate.url}`);
		}
	}
	lines.push('');

	lines.push(`Pruned — ${result.pruned.length} issue(s):`);
	if (result.pruned.length === 0) {
		lines.push('  (nothing pruned)');
	} else {
		for (const pruned of result.pruned) {
			lines.push(`  #${pruned.candidate.number} ${pruned.candidate.title}`);
			lines.push(`      ${describePrune(pruned)}`);
		}
	}

	return lines.join('\n');
}
