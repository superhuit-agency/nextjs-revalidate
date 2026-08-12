/**
 * The finalize phase, exercised without a remote.
 *
 * Finalize takes GitHub from a dependency, so these tests put a fake in front
 * of it and assert the rules that are expensive to get wrong: a *closed* PR is
 * never re-created, a refused update to origin fails loudly and is never
 * retried with force, the PR is not a draft, and the issue is handed off rather
 * than closed.
 *
 * The one thing a fake cannot prove is that the branch really reaches origin —
 * that is `git.test.mts`, against a real scratch repository.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';
import { HANDOFF_LABEL, READY_LABEL } from '../lib/config.mts';
import {
	commentArgs,
	finalizeBatch,
	finalizeItem,
	handoffComment,
	isFailure,
	itemsToFinalize,
	labelSwapArgs,
	parseCreatedPr,
	prBody,
	prCreateArgs,
	renderFinalizeOutcomes,
} from '../lib/finalize.mts';
import type { FinalizeDeps } from '../lib/finalize.mts';
import type { ExistingPr } from '../lib/gather.mts';
import type { ImplementOutcome } from '../lib/implement.mts';
import type { PlanItem } from '../lib/plan.mts';

const ITEM: PlanItem = {
	issue: 7,
	title: 'Do the thing',
	role: 'standalone',
	workBranch: 'sandcastle/issue-7',
	mergeInto: null,
	base: 'main',
};

function outcome(issue: number, status: ImplementOutcome['status']): ImplementOutcome {
	return {
		issue,
		branch: `sandcastle/issue-${issue}`,
		status,
		commits: status === 'no-commits' ? 0 : 1,
		gate: null,
		signalled: true,
		iterations: 1,
	};
}

type FakeOptions = {
	existingPr?: ExistingPr | null;
	pushThrows?: string;
	createThrows?: string;
	swapThrows?: string;
};

type Recorded = {
	pushed: string[];
	looked: string[];
	created: { item: PlanItem; body: string }[];
	swapped: number[];
	comments: { issue: number; body: string }[];
};

function fakeDeps(options: FakeOptions = {}): { deps: FinalizeDeps; recorded: Recorded } {
	const recorded: Recorded = { pushed: [], looked: [], created: [], swapped: [], comments: [] };

	const deps: FinalizeDeps = {
		log: () => {},
		body: prBody,
		push: (branch) => {
			if (options.pushThrows) throw new Error(options.pushThrows);
			recorded.pushed.push(branch);
		},
		findPr: (branch) => {
			recorded.looked.push(branch);
			return options.existingPr ?? null;
		},
		openPr: (item, body) => {
			if (options.createThrows) throw new Error(options.createThrows);
			recorded.created.push({ item, body });
			return { number: 99, url: 'https://github.com/owner/name/pull/99' };
		},
		swapLabels: (issue) => {
			if (options.swapThrows) throw new Error(options.swapThrows);
			recorded.swapped.push(issue);
		},
		comment: (issue, body) => {
			recorded.comments.push({ issue, body });
		},
	};

	return { deps, recorded };
}

describe('itemsToFinalize — only the gate-green', () => {
	it('finalizes implemented items and nothing else', () => {
		const items: PlanItem[] = [1, 2, 3, 4].map((issue) => ({
			...ITEM,
			issue,
			workBranch: `sandcastle/issue-${issue}`,
		}));
		const outcomes: ImplementOutcome[] = [
			outcome(1, 'implemented'),
			outcome(2, 'gate-failed'),
			outcome(3, 'no-commits'),
			outcome(4, 'error'),
		];

		assert.deepEqual(
			itemsToFinalize(items, outcomes).map((item) => item.issue),
			[1]
		);
	});

	it('finalizes nothing when the batch produced nothing', () => {
		assert.deepEqual(itemsToFinalize([ITEM], []), []);
	});

	it('never finalizes a child, however green — the epic PR is its route to main', () => {
		const items: PlanItem[] = [
			{ ...ITEM, issue: 29, role: 'epic', workBranch: 'sandcastle/epic-29' },
			{ ...ITEM, issue: 30, role: 'child', workBranch: 'sandcastle/issue-30', mergeInto: 'sandcastle/epic-29', base: 'sandcastle/epic-29' },
		];

		assert.deepEqual(
			itemsToFinalize(items, [outcome(29, 'implemented'), outcome(30, 'implemented')]).map((item) => item.issue),
			[29],
			'a PR targets the branch its head was cut from, and #30 was cut from the epic branch'
		);
	});
});

describe('the PR body seam', () => {
	it('opens the PR with whatever body the item was given', () => {
		const { deps, recorded } = fakeDeps();
		finalizeItem({ ...deps, body: () => 'Closes #7\n\nan epic body' }, ITEM);

		assert.equal(recorded.created[0]?.body, 'Closes #7\n\nan epic body');
	});
});

describe('finalizeItem — opening the PR', () => {
	it('puts the branch on origin, opens a PR and hands the issue off', () => {
		const { deps, recorded } = fakeDeps();

		const result = finalizeItem(deps, ITEM);

		assert.equal(result.status, 'pr-opened');
		assert.deepEqual(recorded.pushed, ['sandcastle/issue-7']);
		assert.deepEqual(recorded.looked, ['sandcastle/issue-7']);
		assert.equal(recorded.created.length, 1);
		assert.deepEqual(result.handoff, { status: 'done' });
		assert.deepEqual(recorded.swapped, [7]);
		assert.equal(recorded.comments[0]?.issue, 7);
		assert.match(recorded.comments[0]?.body ?? '', /pull\/99/);
	});

	it('carries Closes #<n> in the PR body — the only thing that ever closes the issue', () => {
		assert.match(prBody(ITEM), /^Closes #7$/m);
	});

	it('checks for an existing PR only after the branch is on origin', () => {
		// A PR cannot be opened on a head origin has never seen, and a lookup
		// before the update would answer about a stale head.
		const { deps, recorded } = fakeDeps({ pushThrows: 'rejected' });

		finalizeItem(deps, ITEM);

		assert.deepEqual(recorded.looked, []);
	});
});

describe('finalizeItem — a PR that already exists', () => {
	it('does not re-create over a CLOSED PR, and does not hand off either', () => {
		const closed: ExistingPr = { number: 12, state: 'CLOSED', url: 'https://github.com/owner/name/pull/12' };
		const { deps, recorded } = fakeDeps({ existingPr: closed });

		const result = finalizeItem(deps, ITEM);

		assert.equal(result.status, 'pr-exists');
		assert.deepEqual(recorded.created, [], 'a closed PR must not trigger a re-create attempt');
		assert.equal(result.handoff.status, 'not-applicable');
		assert.deepEqual(recorded.swapped, []);
		assert.ok(!isFailure(result), 'an already-worked branch is not a failed run');
	});

	it('does not re-create over a MERGED PR', () => {
		const merged: ExistingPr = { number: 13, state: 'MERGED', url: 'https://github.com/owner/name/pull/13' };
		const { deps, recorded } = fakeDeps({ existingPr: merged });

		assert.equal(finalizeItem(deps, ITEM).status, 'pr-exists');
		assert.deepEqual(recorded.created, []);
	});

	it('hands off against an OPEN PR it did not open — a handoff that failed last pass', () => {
		const open: ExistingPr = { number: 14, state: 'OPEN', url: 'https://github.com/owner/name/pull/14' };
		const { deps, recorded } = fakeDeps({ existingPr: open });

		const result = finalizeItem(deps, ITEM);

		assert.equal(result.status, 'pr-exists');
		assert.deepEqual(recorded.created, []);
		assert.deepEqual(result.handoff, { status: 'done' });
		assert.deepEqual(recorded.swapped, [7]);
	});
});

describe('finalizeItem — failing loudly', () => {
	it('turns a rejected update to origin into an error outcome carrying git output', () => {
		const { deps, recorded } = fakeDeps({ pushThrows: '! [rejected] sandcastle/issue-7 (fetch first)' });

		const result = finalizeItem(deps, ITEM);

		assert.equal(result.status, 'error');
		assert.match(result.error ?? '', /rejected/);
		assert.equal(result.pr, null);
		assert.ok(isFailure(result), 'a refused update must fail the run');
		assert.deepEqual(recorded.created, []);
		assert.deepEqual(recorded.swapped, []);
	});

	it('reports a failed handoff without pretending the PR did not open', () => {
		const { deps, recorded } = fakeDeps({ swapThrows: 'label not found' });

		const result = finalizeItem(deps, ITEM);

		assert.equal(result.status, 'pr-opened');
		assert.equal(result.pr?.number, 99);
		assert.equal(result.handoff.status, 'failed');
		assert.ok(isFailure(result), 'a handoff nobody was told about must fail the run');
		assert.deepEqual(recorded.comments, [], 'the comment is not left when the label swap failed');
	});

	it('keeps finalizing the batch after one item fails', () => {
		const second: PlanItem = { ...ITEM, issue: 8, workBranch: 'sandcastle/issue-8' };
		let calls = 0;
		const { deps } = fakeDeps();
		const flaky: FinalizeDeps = {
			...deps,
			push: (branch) => {
				calls += 1;
				if (calls === 1) throw new Error('rejected');
				assert.equal(branch, 'sandcastle/issue-8');
			},
		};

		const results = finalizeBatch(flaky, [ITEM, second]);

		assert.deepEqual(
			results.map((result) => result.status),
			['error', 'pr-opened']
		);
	});
});

describe('the gh arguments', () => {
	it('opens a PR into the item base, from the work branch, and never as a draft', () => {
		const args = prCreateArgs('owner/name', ITEM, prBody(ITEM));

		assert.ok(!args.includes('--draft'), args.join(' '));
		assert.ok(!args.includes('-d'), args.join(' '));
		assert.deepEqual(args.slice(0, 2), ['pr', 'create']);
		assert.equal(args[args.indexOf('--base') + 1], 'main');
		assert.equal(args[args.indexOf('--head') + 1], 'sandcastle/issue-7');
		assert.equal(args[args.indexOf('--title') + 1], 'Do the thing');
	});

	it('swaps the label in one call rather than leaving a window with both', () => {
		const args = labelSwapArgs('owner/name', 7);

		assert.deepEqual(args.slice(0, 3), ['issue', 'edit', '7']);
		assert.equal(args[args.indexOf('--add-label') + 1], HANDOFF_LABEL);
		assert.equal(args[args.indexOf('--remove-label') + 1], READY_LABEL);
	});

	it('comments on the issue', () => {
		const args = commentArgs('owner/name', 7, 'hello');
		assert.deepEqual(args, ['issue', 'comment', '7', '--repo', 'owner/name', '--body', 'hello']);
	});

	it('never closes an issue — the harness leaves that to a human merging the PR', () => {
		const source = readFileSync(fileURLToPath(new URL('../lib/finalize.mts', import.meta.url)), 'utf8');
		assert.ok(!/['"`]close['"`]/.test(source), 'finalize must not invoke `gh issue close`');
		assert.match(handoffComment(ITEM, { number: 99, url: 'u' }), /stays open/);
	});
});

describe('parseCreatedPr', () => {
	it('reads the PR number and URL out of gh output', () => {
		assert.deepEqual(parseCreatedPr('https://github.com/owner/name/pull/99\n'), {
			number: 99,
			url: 'https://github.com/owner/name/pull/99',
		});
	});

	it('ignores chatter printed before the URL', () => {
		const stdout = 'Warning: 3 uncommitted changes\nhttps://github.com/owner/name/pull/101\n';
		assert.equal(parseCreatedPr(stdout).number, 101);
	});

	it('throws rather than inventing a PR number', () => {
		assert.throws(() => parseCreatedPr('something went sideways'), /could not read a PR URL/);
	});
});

describe('renderFinalizeOutcomes', () => {
	it('says the issue is still open on a clean handoff', () => {
		const { deps } = fakeDeps();
		const rendered = renderFinalizeOutcomes([finalizeItem(deps, ITEM)]);

		assert.match(rendered, /pr-opened/);
		assert.match(rendered, /issue still open/);
	});

	it('shouts about a failed handoff', () => {
		const { deps } = fakeDeps({ swapThrows: 'boom' });
		assert.match(renderFinalizeOutcomes([finalizeItem(deps, ITEM)]), /handoff: FAILED — boom/);
	});
});
