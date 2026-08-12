/**
 * The finalize phase — the first mode that puts anything on origin, and the
 * one that ends a pass the way the harness is supposed to end: a PR into
 * `main` waiting for a human.
 *
 * This is deterministic code, not an agent. For each item the implement phase
 * left green it sends the work branch through `pushBranch()`, checks whether a
 * PR already exists for that head, opens one if not, and hands the issue back.
 *
 * Three rules here are load-bearing:
 *
 * - **PR existence is checked across every state.** `findExistingPr()` — the
 *   same re-pick guard the gather phase uses — reports open, merged *and*
 *   closed PRs, so a closed PR on a work branch never triggers a re-create
 *   attempt. GitHub would reject it anyway; the point is that the harness
 *   understands why rather than reporting a confusing API error.
 * - **A rejected push fails loudly.** It becomes an `error` outcome carrying
 *   git's own stderr, and it takes the run's exit code with it. Nothing here
 *   retries, and nothing anywhere forces — see `lib/git.mts`.
 * - **The PR is not a draft.** Nothing in this repo filters on draft state —
 *   no PR CI, no preview environments — so drafting would only add a click
 *   between the harness and the human it is handing to.
 *
 * **The issue stays open.** The harness never closes a standalone issue. The
 * PR body carries `Closes #<n>`, so GitHub closes it when a human merges, and
 * that merge is the only thing that ever reaches `main`. What finalize does to
 * the issue is swap `ready-for-agent` → `ready-for-human` and leave a comment
 * linking the PR — which is also why re-running immediately picks nothing up:
 * the label is gone, and the PR now trips the re-pick guard. Either one alone
 * would be enough.
 */
import { HANDOFF_LABEL, READY_LABEL } from './config.mts';
import { messageOf } from './errors.mts';
import { findExistingPr } from './gather.mts';
import type { ExistingPr } from './gather.mts';
import { gh } from './gh.mts';
import { pushBranch } from './git.mts';
import { greenIssues } from './implement.mts';
import type { ImplementOutcome } from './implement.mts';
import type { PlanItem } from './plan.mts';

/** A PR the harness just opened, as read back from `gh pr create`. */
export type OpenedPr = { number: number; url: string };

/**
 * What happened to the issue after the PR was settled. Separate from the PR's
 * own outcome: a PR that opened and a handoff that failed is a real state, and
 * flattening the two would hide it.
 */
export type Handoff =
	| { status: 'done' }
	| { status: 'not-applicable'; reason: string }
	| { status: 'failed'; error: string };

export type FinalizeOutcome = {
	issue: number;
	branch: string;
	status: 'pr-opened' | 'pr-exists' | 'error';
	pr: (OpenedPr & { state: string }) | null;
	handoff: Handoff;
	error?: string;
};

/* -- The GitHub seam -------------------------------------------------------
 *
 * Every network effect finalize has, behind five functions, so the rules above
 * are assertable against a fake without a remote, a token or a repository.
 */

export type FinalizeDeps = {
	/** Sends the branch to origin. Backed by the one chokepoint in `lib/git.mts`. */
	push: (branch: string) => void;
	/** Any PR on this head, in any state. */
	findPr: (branch: string) => ExistingPr | null;
	/**
	 * The PR body for this item. A seam because an epic's body is written by an
	 * agent from its branch history, while a standalone's is deterministic —
	 * and because the one property that matters about either (it carries
	 * `Closes #<n>`) is then assertable without a model.
	 */
	body: (item: PlanItem) => string;
	openPr: (item: PlanItem, body: string) => OpenedPr;
	swapLabels: (issue: number) => void;
	comment: (issue: number, body: string) => void;
	log: (message: string) => void;
};

/**
 * The items a finalize pass may act on: those the gate left green, minus the
 * children.
 *
 * An item that failed the gate, wrote nothing, or errored is deliberately not
 * finalized — a PR into `main` is the harness asking a human to merge, and it
 * has no business asking that of work it could not verify. Such an item keeps
 * its branch, its label and its issue, and the next cycle picks it up again.
 *
 * A **child** is excluded whatever the gate said: a PR targets the branch its
 * head was cut from, a child is cut from its epic branch, and the merge phase
 * has already taken it there. The epic's PR is the one that reaches `main`.
 */
export function itemsToFinalize(items: readonly PlanItem[], outcomes: readonly ImplementOutcome[]): PlanItem[] {
	const green = greenIssues(outcomes);
	return items.filter((item) => item.role !== 'child' && green.has(item.issue));
}

/**
 * The PR body for a standalone item. `Closes #<n>` is the whole handoff
 * contract: the harness leaves the issue open, and a human merging the PR is
 * what closes it. An epic's body is built in `lib/epicpr.mts` and carries the
 * same keyword for the same reason.
 */
export function prBody(item: PlanItem): string {
	return [
		`Closes #${item.issue}`,
		'',
		`Implemented by the AFK agent harness (\`.sandcastle\`) from #${item.issue} — ${item.title}.`,
		'',
		`Branch \`${item.workBranch}\`, cut from \`${item.base}\`. After the implementer stopped, the harness ran`,
		'the gate itself inside the same sandbox — `npm run typecheck` and `npm run lint:php` — and it passed. That',
		'gate is syntax-level and it is the only automated verdict this pipeline has — review this as you would',
		'any human PR.',
		'',
		'🤖 Opened by `npm run sandcastle`. Nothing reaches `main` except a human merging this.',
	].join('\n');
}

/** The marker comment left on the issue, linking the PR a human now owns. */
export function handoffComment(item: PlanItem, pr: OpenedPr): string {
	return [
		`🏰 Handed off — PR #${pr.number} is open against \`${item.base}\`: ${pr.url}`,
		'',
		`The harness implemented this on \`${item.workBranch}\` with the gate green and swapped`,
		`\`${READY_LABEL}\` → \`${HANDOFF_LABEL}\`.`,
		'',
		`This issue stays open on purpose: the PR body carries \`Closes #${item.issue}\`, so GitHub closes it when a`,
		'human merges. The harness never closes an issue itself.',
	].join('\n');
}

/**
 * Arguments for `gh pr create`.
 *
 * Split out from the call so the two things that matter about it — that the PR
 * targets the item's base and that it is **not** a draft — are assertable with
 * no repository in sight. The absence of `--draft` is the kind of property
 * that only stays true if something checks it.
 */
export function prCreateArgs(repo: string, item: PlanItem, body: string): string[] {
	return [
		'pr',
		'create',
		'--repo',
		repo,
		'--base',
		item.base,
		'--head',
		item.workBranch,
		'--title',
		item.title,
		'--body',
		body,
	];
}

/** Arguments for the label swap. Note there is no `gh issue close` anywhere. */
export function labelSwapArgs(repo: string, issue: number): string[] {
	return ['issue', 'edit', String(issue), '--repo', repo, '--add-label', HANDOFF_LABEL, '--remove-label', READY_LABEL];
}

export function commentArgs(repo: string, issue: number, body: string): string[] {
	return ['issue', 'comment', String(issue), '--repo', repo, '--body', body];
}

/**
 * Read the PR back out of what `gh pr create` printed. It emits the PR's URL,
 * possibly after other chatter, so every line is scanned rather than assuming
 * the first one is it.
 */
export function parseCreatedPr(stdout: string): OpenedPr {
	const matches = [...stdout.matchAll(/(https:\/\/\S*?\/pull\/(\d+))/g)];
	const last = matches[matches.length - 1];

	if (!last?.[1] || !last[2]) {
		throw new Error(`could not read a PR URL out of gh output: ${stdout.trim() || '(nothing)'}`);
	}

	return { number: Number(last[2]), url: last[1] };
}

/** Whether a PR in this state is one a human can still be handed. */
function isOpen(state: string): boolean {
	return state.toUpperCase() === 'OPEN';
}

/**
 * Swap the label and leave the marker comment.
 *
 * A failure here does not undo the PR — it is open, and that is the part that
 * cannot be redone. It is still reported and still fails the run: a handoff
 * nobody was told about is a PR sitting unnoticed. Re-picking is not the risk;
 * the PR itself trips the re-pick guard whatever the label says.
 */
export function handOff(deps: FinalizeDeps, item: PlanItem, pr: OpenedPr): Handoff {
	try {
		deps.swapLabels(item.issue);
		deps.comment(item.issue, handoffComment(item, pr));
		return { status: 'done' };
	} catch (error) {
		return { status: 'failed', error: messageOf(error) };
	}
}

/**
 * Take one implemented item to a PR. Never throws: a failed item becomes an
 * outcome so the rest of the batch still finalizes.
 */
export function finalizeItem(deps: FinalizeDeps, item: PlanItem): FinalizeOutcome {
	const base: Pick<FinalizeOutcome, 'issue' | 'branch'> = { issue: item.issue, branch: item.workBranch };

	try {
		deps.log(`#${item.issue}: sending ${item.workBranch} to origin`);
		deps.push(item.workBranch);
	} catch (error) {
		// Loud, and never retried with force. A refused update means origin has
		// something this branch does not, and resolving that is a human's call.
		return {
			...base,
			status: 'error',
			pr: null,
			handoff: { status: 'not-applicable', reason: 'the branch never reached origin' },
			error: `could not update origin/${item.workBranch}: ${messageOf(error)}`,
		};
	}

	try {
		const existing = deps.findPr(item.workBranch);

		// Every state counts. A *closed* PR on this head means the issue was
		// already worked and a human decided against it; re-creating would be
		// the harness arguing with that decision.
		if (existing) {
			deps.log(`#${item.issue}: PR #${existing.number} already exists on this branch (${existing.state})`);
			return {
				...base,
				status: 'pr-exists',
				pr: existing,
				handoff: isOpen(existing.state)
					? handOff(deps, item, existing)
					: { status: 'not-applicable', reason: `the existing PR is ${existing.state.toLowerCase()}` },
			};
		}

		const opened = deps.openPr(item, deps.body(item));
		deps.log(`#${item.issue}: opened PR #${opened.number} — ${opened.url}`);

		return {
			...base,
			status: 'pr-opened',
			pr: { ...opened, state: 'OPEN' },
			handoff: handOff(deps, item, opened),
		};
	} catch (error) {
		return {
			...base,
			status: 'error',
			pr: null,
			handoff: { status: 'not-applicable', reason: 'no PR was settled' },
			error: messageOf(error),
		};
	}
}

/** Finalize a batch, in order. Sequential: these are cheap API calls, not containers. */
export function finalizeBatch(deps: FinalizeDeps, items: readonly PlanItem[]): FinalizeOutcome[] {
	return items.map((item) => finalizeItem(deps, item));
}

/**
 * An outcome the operator has to act on — either the PR never happened, or it
 * did and nobody was told. Both fail the run.
 */
export function isFailure(outcome: FinalizeOutcome): boolean {
	return outcome.status === 'error' || outcome.handoff.status === 'failed';
}

/** One line per item: the PR it produced, and whether the handoff landed. */
export function renderFinalizeOutcomes(outcomes: readonly FinalizeOutcome[]): string {
	const lines: string[] = [];

	for (const outcome of outcomes) {
		const mark = isFailure(outcome) ? '✗' : '✓';
		const pr = outcome.pr ? ` PR #${outcome.pr.number} (${outcome.pr.state}) ${outcome.pr.url}` : '';

		lines.push(`  ${mark} #${outcome.issue} ${outcome.branch} — ${outcome.status}${pr}`);
		if (outcome.handoff.status === 'done') {
			lines.push(`      handoff: ${READY_LABEL} → ${HANDOFF_LABEL}, comment left; issue still open`);
		} else if (outcome.handoff.status === 'not-applicable') {
			lines.push(`      handoff: none — ${outcome.handoff.reason}`);
		} else {
			lines.push(`      handoff: FAILED — ${outcome.handoff.error}`);
		}
		if (outcome.error) lines.push(`      error: ${outcome.error}`);
	}

	return lines.join('\n');
}

/** The real GitHub, wired to the same chokepoint everything else pushes through. */
export function realFinalizeDeps(
	cwd: string,
	repo: string,
	log: (message: string) => void,
	body: (item: PlanItem) => string
): FinalizeDeps {
	return {
		log,
		body,
		push: (branch) => pushBranch(cwd, branch),
		findPr: (branch) => findExistingPr(repo, branch),
		openPr: (item, body) => parseCreatedPr(gh(prCreateArgs(repo, item, body))),
		swapLabels: (issue) => {
			gh(labelSwapArgs(repo, issue));
		},
		comment: (issue, body) => {
			gh(commentArgs(repo, issue, body));
		},
	};
}
