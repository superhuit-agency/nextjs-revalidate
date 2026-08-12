/**
 * The epic PR body — the one thing in finalize an agent writes.
 *
 * An epic branch arrives at its PR carrying its own implementation work plus a
 * squash commit per child, and nothing deterministic can say what that adds up
 * to. So an agent is asked, on the host, for the narrative — and *only* the
 * narrative. Everything with teeth stays in code:
 *
 * - **`Closes #<epic>` is prepended by the harness**, never by the agent. It is
 *   the whole handoff contract — a human merging the PR is what closes the epic
 *   — and it is not something a model gets to forget or reword.
 * - **Closing keywords in the narrative are stripped.** An agent writing
 *   "Closes #30" about a child it saw in the log would close, on merge, an
 *   issue the merge phase already closed — or worse, one nobody asked about.
 *   The epic closes the epic; nothing else.
 * - **A failed or empty agent call is not a failed PR.** The PR is the point of
 *   the entire run; losing it over a narrative would be absurd. The fallback is
 *   a deterministic body built from the same facts, and it says that is what it
 *   is.
 */
import { execFileSync } from 'node:child_process';
import { PR_BODY_MODEL, PR_BODY_TIMEOUT_MS } from './config.mts';
import { messageOf } from './errors.mts';
import { git } from './git.mts';
import type { PlanItem } from './plan.mts';

/** What the narrative is written from. All of it is read off the branch and the issue. */
export type EpicContext = {
	/** Commit subjects on the epic branch that the base does not have, oldest first. */
	commits: string[];
	/** Sub-issues whose work reached the branch this pass. */
	merged: { issue: number; title: string }[];
	/** The epic issue's own body — what it asked for. */
	issueBody: string;
};

/** Closing keywords GitHub acts on, in the forms it accepts. */
const CLOSING_KEYWORD = /\b(clos(e|es|ed)|fix(es|ed)?|resolv(e|es|ed))\b\s*:?\s*#\d+/gi;

/**
 * Neutralise closing keywords in agent-written text. The reference is kept —
 * it is usually the point of the sentence — and only its *closing power* is
 * removed, so the body still reads as English.
 */
export function stripClosingKeywords(text: string): string {
	return text.replace(CLOSING_KEYWORD, (match) => match.replace(/#(\d+)/, 'issue $1'));
}

export function epicNarrativePrompt(item: PlanItem, context: EpicContext): string {
	const merged =
		context.merged.length > 0
			? context.merged.map((child) => `- #${child.issue} — ${child.title}`).join('\n')
			: '- (none this pass; the branch carries the epic\'s own work only)';

	const commits = context.commits.length > 0 ? context.commits.map((subject) => `- ${subject}`).join('\n') : '- (none)';

	return [
		'Write the body of a pull request. Output the body itself and nothing else — no preamble,',
		'no code fences, no "Here is the PR body". Markdown, at most about 250 words.',
		'',
		`The PR takes the branch \`${item.workBranch}\` into \`${item.base}\`. It is an *epic*: GitHub issue`,
		`#${item.issue} and its sub-issues, whose work was squash-merged into this branch.`,
		'',
		`## The epic — #${item.issue}: ${item.title}`,
		'',
		context.issueBody.trim() || '(the issue has no body)',
		'',
		'## Sub-issues merged into this branch',
		'',
		merged,
		'',
		'## Commits on the branch',
		'',
		commits,
		'',
		'## Rules',
		'',
		'- Say what this changes and why, as a reviewer would want it. Lead with the change, not with the process.',
		'- Do NOT write any closing keyword ("Closes", "Fixes", "Resolves") — the harness adds the one that belongs.',
		'- Do not invent testing, review or CI that you were not told about. This repository has no test suite and no',
		'  PR CI; the only automated check is a syntax-level gate.',
		'- Do not claim the work is correct. You did not write it and you cannot see the diff.',
	].join('\n');
}

/**
 * The finished body. The closing keyword and the provenance footer are the
 * harness's; only the middle is the agent's, and it has already been stripped.
 */
export function epicPrBody(item: PlanItem, narrative: string): string {
	return [
		`Closes #${item.issue}`,
		'',
		stripClosingKeywords(narrative).trim(),
		'',
		'---',
		'',
		`Epic branch \`${item.workBranch}\`, cut from \`${item.base}\`, carrying this epic's own work and one squash`,
		'commit per sub-issue. Each sub-issue was closed as it merged; this PR is what carries all of it into',
		`\`${item.base}\`. The harness ran the gate itself after each implementer stopped and it passed —`,
		'that gate is syntax-level and it is the only automated verdict this pipeline has, so review this as you',
		'would any human PR.',
		'',
		'🤖 Opened by `npm run sandcastle`. Nothing reaches the base branch except a human merging this.',
	].join('\n');
}

/** The body used when the agent is unavailable or says nothing useful. */
export function fallbackNarrative(item: PlanItem, context: EpicContext): string {
	const lines = [`Epic #${item.issue} — ${item.title}.`, ''];

	if (context.merged.length > 0) {
		lines.push('Sub-issues merged into this branch:', '');
		for (const child of context.merged) lines.push(`- #${child.issue} — ${child.title}`);
		lines.push('');
	}

	if (context.commits.length > 0) {
		lines.push('Commits:', '');
		for (const subject of context.commits) lines.push(`- ${subject}`);
		lines.push('');
	}

	lines.push('_No narrative: the agent that writes epic PR bodies was unavailable, so this is the branch as it stands._');
	return lines.join('\n');
}

export type NarrativeDeps = {
	/** Puts the prompt to an agent and returns what it wrote. May throw. */
	ask: (prompt: string) => string;
	log: (message: string) => void;
};

/**
 * Ask for the narrative, falling back to the deterministic one. Never throws:
 * the PR is worth more than the prose in it.
 */
export function writeEpicNarrative(deps: NarrativeDeps, item: PlanItem, context: EpicContext): string {
	try {
		const written = deps.ask(epicNarrativePrompt(item, context)).trim();
		if (written.length === 0) {
			deps.log(`#${item.issue}: the PR-body agent returned nothing; using the deterministic body`);
			return fallbackNarrative(item, context);
		}
		return written;
	} catch (error) {
		deps.log(`#${item.issue}: the PR-body agent failed (${messageOf(error)}); using the deterministic body`);
		return fallbackNarrative(item, context);
	}
}

/**
 * Read the epic branch's context off the repository. Read-only, and no
 * checkout: `git log <base>..<branch>` needs neither.
 */
export function readEpicContext(
	cwd: string,
	item: PlanItem,
	issueBody: string,
	merged: EpicContext['merged']
): EpicContext {
	const log = git(cwd, ['log', '--reverse', '--format=%s', `${item.base}..${item.workBranch}`]);
	return {
		commits: log.split('\n').filter((subject) => subject.trim().length > 0),
		merged,
		issueBody,
	};
}

/**
 * The PR body for any item: deterministic for a standalone, agent-written for
 * an epic. This is the factory the finalize phase takes its `body` seam from —
 * assembling it lives here with the rest of the epic-body machinery rather
 * than in the CLI, the same way every other phase keeps its own wiring.
 */
export type BodySources = {
	cwd: string;
	/** Sub-issues whose work reached this epic branch this pass, with titles. */
	mergedInto: (branch: string) => EpicContext['merged'];
	/** The epic issue's own body — what it asked for. */
	issueBody: (issue: number) => string;
	/** The body for anything that is not an epic. */
	standalone: (item: PlanItem) => string;
	log: (message: string) => void;
};

export function realBodyFor(sources: BodySources): (item: PlanItem) => string {
	const deps = realNarrativeDeps(sources.log);

	return (item) => {
		if (item.role !== 'epic') return sources.standalone(item);

		const context = readEpicContext(
			sources.cwd,
			item,
			sources.issueBody(item.issue),
			sources.mergedInto(item.workBranch)
		);
		return epicPrBody(item, writeEpicNarrative(deps, item, context));
	};
}

/** Arguments for the one-shot Claude Code call that writes the narrative. */
export function narrativeArgs(prompt: string): string[] {
	return ['--print', '--model', PR_BODY_MODEL, prompt];
}

/**
 * The real agent: Claude Code on the *host*, one shot, no tools worth the name.
 * It never touches the repository — it is handed the facts in the prompt and
 * asked for prose, so it needs no sandbox and gets no working directory it
 * could write to.
 */
export function realNarrativeDeps(log: (message: string) => void): NarrativeDeps {
	return {
		log,
		ask: (prompt) =>
			execFileSync('claude', narrativeArgs(prompt), {
				encoding: 'utf8',
				timeout: PR_BODY_TIMEOUT_MS,
				maxBuffer: 8 * 1024 * 1024,
			}),
	};
}
