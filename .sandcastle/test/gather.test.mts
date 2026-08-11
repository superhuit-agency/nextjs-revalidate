/**
 * The pure half of gathering. Everything else in `lib/gather.mts` is a `gh`
 * call, but which branch the re-pick guard looks at is a decision, and getting
 * it wrong makes the guard silently blind.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { branchForCandidate, parentFromBody } from '../lib/gather.mts';

describe('branchForCandidate — what the re-pick guard looks for a PR on', () => {
	it('uses the epic branch for an issue with sub-issues', () => {
		// The epic's PR is opened from `sandcastle/epic-29`. Looking on
		// `sandcastle/issue-29` would find nothing and re-pick a worked epic
		// on every run.
		assert.equal(branchForCandidate(29, [30]), 'sandcastle/epic-29');
	});

	it('uses the work branch for a standalone', () => {
		assert.equal(branchForCandidate(37, []), 'sandcastle/issue-37');
	});

	it('uses the work branch for a child — a child never gets a PR of its own', () => {
		assert.equal(branchForCandidate(30, []), 'sandcastle/issue-30');
	});
});

describe('parentFromBody', () => {
	it('reads both marker forms', () => {
		assert.equal(parentFromBody('Sub-issue of #33'), 33);
		assert.equal(parentFromBody('Part of #29 — the epic'), 29);
	});

	it('is null when there is no marker', () => {
		assert.equal(parentFromBody('Blocked by #12'), null);
	});
});
