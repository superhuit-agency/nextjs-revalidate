/**
 * The epic PR body.
 *
 * An agent writes the middle of it, so what these assert is everything around
 * the agent: that `Closes #<epic>` is there whatever the agent did, that no
 * other closing keyword survives, and that a failed agent still produces a
 * body — the PR is the point of the run, and losing it over prose would be
 * absurd.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
	epicNarrativePrompt,
	epicPrBody,
	fallbackNarrative,
	narrativeArgs,
	realBodyFor,
	stripClosingKeywords,
	writeEpicNarrative,
} from '../lib/epicpr.mts';
import type { EpicContext, NarrativeDeps } from '../lib/epicpr.mts';
import type { PlanItem } from '../lib/plan.mts';

const EPIC: PlanItem = {
	issue: 29,
	title: 'Revalidate on menu save',
	role: 'epic',
	workBranch: 'sandcastle/epic-29',
	mergeInto: null,
	base: 'main',
};

const CONTEXT: EpicContext = {
	commits: ['feat: 🚀 add the thing', 'chore: 🔀 squash #30 — the sub-thing'],
	merged: [{ issue: 30, title: 'the sub-thing' }],
	issueBody: 'Make menu saves revalidate.',
};

function fakeDeps(answer: string | Error): { deps: NarrativeDeps; logs: string[] } {
	const logs: string[] = [];
	return {
		logs,
		deps: {
			log: (message) => logs.push(message),
			ask: () => {
				if (answer instanceof Error) throw answer;
				return answer;
			},
		},
	};
}

describe('epicPrBody', () => {
	it('carries Closes #<epic> as its first line, from the harness and not the agent', () => {
		assert.match(epicPrBody(EPIC, 'A narrative.'), /^Closes #29\n/);
	});

	it('keeps the agent narrative in the middle', () => {
		assert.match(epicPrBody(EPIC, 'The menu cache now clears on save.'), /The menu cache now clears on save\./);
	});

	it('strips a closing keyword the agent wrote, keeping the reference', () => {
		const body = epicPrBody(EPIC, 'This also closes #30 and fixes #31.');

		assert.doesNotMatch(body.slice('Closes #29'.length), /\b(closes|fixes|resolves)\s*:?\s*#\d+/i);
		assert.match(body, /issue 30/);
		assert.match(body, /issue 31/);
	});

	it('leaves exactly one closing keyword in the finished body', () => {
		const body = epicPrBody(EPIC, 'Closes #30. Fixes #31. Resolves #32.');
		assert.deepEqual(body.match(/\b(clos(e|es|ed)|fix(es|ed)?|resolv(e|es|ed))\b\s*:?\s*#\d+/gi), ['Closes #29']);
	});

	it('names the branch it came from and the base it targets', () => {
		const body = epicPrBody(EPIC, 'x');
		assert.match(body, /sandcastle\/epic-29/);
		assert.match(body, /`main`/);
	});
});

describe('stripClosingKeywords', () => {
	it('neutralises every form GitHub acts on', () => {
		for (const phrase of ['Closes #1', 'closed #1', 'Fixes #1', 'fixed #1', 'Resolves #1', 'resolve #1', 'Closes: #1']) {
			assert.doesNotMatch(stripClosingKeywords(phrase), /#1/, phrase);
		}
	});

	it('leaves a bare reference alone — it closes nothing', () => {
		assert.equal(stripClosingKeywords('Part of #33, see #34.'), 'Part of #33, see #34.');
	});
});

describe('writeEpicNarrative', () => {
	it('uses what the agent wrote', () => {
		const { deps } = fakeDeps('  The narrative.  ');
		assert.equal(writeEpicNarrative(deps, EPIC, CONTEXT), 'The narrative.');
	});

	it('falls back to a deterministic body when the agent fails', () => {
		const { deps, logs } = fakeDeps(new Error('command not found: claude'));
		const narrative = writeEpicNarrative(deps, EPIC, CONTEXT);

		assert.match(narrative, /#30 — the sub-thing/);
		assert.match(narrative, /No narrative/);
		assert.match(logs.join('\n'), /command not found/);
	});

	it('falls back when the agent says nothing at all', () => {
		const { deps } = fakeDeps('   \n  ');
		assert.match(writeEpicNarrative(deps, EPIC, CONTEXT), /No narrative/);
	});

	it('still produces a body with Closes #<epic> when the agent fails', () => {
		const { deps } = fakeDeps(new Error('timed out'));
		assert.match(epicPrBody(EPIC, writeEpicNarrative(deps, EPIC, CONTEXT)), /^Closes #29\n/);
	});
});

describe('epicNarrativePrompt', () => {
	it('hands the agent the sub-issues, the commits and the epic body', () => {
		const prompt = epicNarrativePrompt(EPIC, CONTEXT);

		assert.match(prompt, /#30 — the sub-thing/);
		assert.match(prompt, /feat: 🚀 add the thing/);
		assert.match(prompt, /Make menu saves revalidate\./);
		assert.match(prompt, /sandcastle\/epic-29/);
	});

	it('tells the agent not to write a closing keyword', () => {
		assert.match(epicNarrativePrompt(EPIC, CONTEXT), /Do NOT write any closing keyword/);
	});

	it('says so plainly when nothing was merged this pass', () => {
		assert.match(epicNarrativePrompt(EPIC, { ...CONTEXT, merged: [] }), /none this pass/);
	});
});

describe('fallbackNarrative', () => {
	it('says what it is, so a reviewer is not left thinking an agent wrote it', () => {
		assert.match(fallbackNarrative(EPIC, CONTEXT), /the agent that writes epic PR bodies was unavailable/i);
	});
});

describe('realBodyFor — which body an item gets', () => {
	it('gives a standalone the deterministic body without asking any agent', () => {
		let asked = false;
		const body = realBodyFor({
			cwd: process.cwd(),
			log: () => {},
			standalone: (item) => `standalone body for #${item.issue}`,
			issueBody: () => {
				asked = true;
				return '';
			},
			mergedInto: () => [],
		});

		assert.equal(body({ ...EPIC, issue: 7, role: 'standalone', workBranch: 'sandcastle/issue-7' }), 'standalone body for #7');
		assert.equal(asked, false, 'nothing about an epic is read for a standalone');
	});
});

describe('narrativeArgs', () => {
	it('is one non-interactive shot at the configured model', () => {
		const args = narrativeArgs('write it');

		assert.equal(args[0], '--print');
		assert.ok(args.includes('--model'));
		assert.equal(args[args.length - 1], 'write it');
	});
});
