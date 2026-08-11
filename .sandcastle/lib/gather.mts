import { ghJson, ghJsonTry } from './gh.mts';
import { READY_LABEL, workBranchForIssue } from './config.mts';

export type Blocker = {
	number: number;
	state: 'open' | 'closed';
	/** How the blocker was discovered — the native API, or the body fallback. */
	source: 'dependencies-api' | 'body-marker';
};

export type ExistingPr = {
	number: number;
	state: string;
	url: string;
};

export type Candidate = {
	number: number;
	title: string;
	url: string;
	labels: string[];
	body: string;
	workBranch: string;
	/** Native sub-issue parent, else a `Sub-issue of #N` / `Part of #N` body marker. */
	parent: number | null;
	parentSource: 'native' | 'body-marker' | null;
	/** Native sub-issues. A candidate with children is an epic. */
	children: number[];
	blockers: Blocker[];
	existingPr: ExistingPr | null;
};

export type PruneReason =
	| { kind: 'open-blockers'; blockers: Blocker[] }
	| { kind: 'already-worked'; pr: ExistingPr };

export type Pruned = { candidate: Candidate; reason: PruneReason };

export type GatherResult = {
	repo: string;
	label: string;
	considered: number;
	eligible: Candidate[];
	pruned: Pruned[];
};

type IssueListItem = {
	number: number;
	title: string;
	url: string;
	body: string;
	labels: { name: string }[];
	state: string;
};

type GraphqlIssueRelations = {
	data: {
		repository: {
			issue: {
				parent: { number: number } | null;
				subIssues: { nodes: { number: number }[] };
			} | null;
		};
	};
};

type DependencyIssue = { number: number; state: string };

/**
 * Every open issue carrying the ready label. This is the snapshot the whole
 * run works from — the harness makes one pass and exits, it does not re-poll.
 */
export function fetchCandidates(repo: string): IssueListItem[] {
	return ghJson<IssueListItem[]>([
		'issue',
		'list',
		'--repo',
		repo,
		'--label',
		READY_LABEL,
		'--state',
		'open',
		'--limit',
		'200',
		'--json',
		'number,title,url,body,labels,state',
	]);
}

/**
 * Blockers from GitHub's native issue dependencies, falling back to a
 * `Blocked by: #<n>` body line where the API is unavailable on this repo.
 * A candidate is eligible only when every blocker is closed.
 */
export function fetchBlockers(repo: string, issue: IssueListItem): Blocker[] {
	const native = ghJsonTry<DependencyIssue[]>([
		'api',
		`repos/${repo}/issues/${issue.number}/dependencies/blocked_by`,
		'--paginate',
	]);

	if (native.ok) {
		return native.value.map((dep) => ({
			number: dep.number,
			state: dep.state === 'closed' ? ('closed' as const) : ('open' as const),
			source: 'dependencies-api' as const,
		}));
	}

	return blockersFromBody(repo, issue.body ?? '');
}

/**
 * Fallback path: collect `#<n>` references from any line mentioning
 * "blocked by", then resolve each one's state.
 */
export function blockersFromBody(repo: string, body: string): Blocker[] {
	const numbers = new Set<number>();

	for (const line of body.split('\n')) {
		if (!/blocked\s+by/i.test(line)) continue;
		for (const match of line.matchAll(/#(\d+)/g)) {
			const raw = match[1];
			if (raw) numbers.add(Number(raw));
		}
	}

	// A `## Blocked by` heading puts its references on the lines that follow.
	const section = body.match(/^#{1,6}\s*Blocked by\s*$([\s\S]*?)(?=^#{1,6}\s|$(?![\s\S]))/im);
	if (section?.[1]) {
		for (const match of section[1].matchAll(/#(\d+)/g)) {
			const raw = match[1];
			if (raw) numbers.add(Number(raw));
		}
	}

	return [...numbers].map((number) => {
		const state = ghJsonTry<{ state: string }>(['issue', 'view', String(number), '--repo', repo, '--json', 'state']);
		// An unreadable blocker is treated as open — the harness refuses to
		// guess its way into working a blocked issue.
		const resolved = state.ok && state.value.state.toLowerCase() === 'closed' ? 'closed' : 'open';
		return { number, state: resolved as 'open' | 'closed', source: 'body-marker' as const };
	});
}

/**
 * Native sub-issue parent/children, with a body marker as fallback only when
 * there is no native parent. If both exist and disagree, native wins and the
 * conflict is reported.
 */
export function fetchRelations(
	repo: string,
	issue: IssueListItem,
	warn: (message: string) => void
): Pick<Candidate, 'parent' | 'parentSource' | 'children'> {
	const [owner, name] = repo.split('/');
	const query = `query{repository(owner:"${owner}",name:"${name}"){issue(number:${issue.number}){parent{number} subIssues(first:100){nodes{number}}}}}`;
	const result = ghJsonTry<GraphqlIssueRelations>(['api', 'graphql', '-f', `query=${query}`]);

	const nativeParent = result.ok ? (result.value.data.repository.issue?.parent?.number ?? null) : null;
	const children = result.ok ? (result.value.data.repository.issue?.subIssues.nodes.map((n) => n.number) ?? []) : [];
	const markerParent = parentFromBody(issue.body ?? '');

	if (nativeParent !== null) {
		if (markerParent !== null && markerParent !== nativeParent) {
			warn(`#${issue.number}: native parent #${nativeParent} disagrees with body marker #${markerParent}; using native`);
		}
		return { parent: nativeParent, parentSource: 'native', children };
	}

	if (markerParent !== null) {
		return { parent: markerParent, parentSource: 'body-marker', children };
	}

	return { parent: null, parentSource: null, children };
}

export function parentFromBody(body: string): number | null {
	const match = body.match(/(?:Sub-issue of|Part of)\s+#(\d+)/i);
	const raw = match?.[1];
	return raw ? Number(raw) : null;
}

/**
 * The re-pick guard. Any PR on the issue's work branch — open, merged or
 * closed — means the issue has already been worked. Load-bearing: this is
 * what holds when the label swap fails, so it must not be weakened.
 */
export function findExistingPr(repo: string, workBranch: string): ExistingPr | null {
	const result = ghJsonTry<ExistingPr[]>([
		'pr',
		'list',
		'--repo',
		repo,
		'--head',
		workBranch,
		'--state',
		'all',
		'--limit',
		'10',
		'--json',
		'number,state,url',
	]);
	if (!result.ok || result.value.length === 0) return null;
	return result.value[0] ?? null;
}

export function gather(repo: string, warn: (message: string) => void): GatherResult {
	const issues = fetchCandidates(repo);
	const eligible: Candidate[] = [];
	const pruned: Pruned[] = [];

	for (const issue of issues) {
		const workBranch = workBranchForIssue(issue.number);
		const relations = fetchRelations(repo, issue, warn);
		const blockers = fetchBlockers(repo, issue);
		const existingPr = findExistingPr(repo, workBranch);

		const candidate: Candidate = {
			number: issue.number,
			title: issue.title,
			url: issue.url,
			labels: issue.labels.map((label) => label.name),
			body: issue.body ?? '',
			workBranch,
			parent: relations.parent,
			parentSource: relations.parentSource,
			children: relations.children,
			blockers,
			existingPr,
		};

		const openBlockers = blockers.filter((blocker) => blocker.state === 'open');
		if (openBlockers.length > 0) {
			pruned.push({ candidate, reason: { kind: 'open-blockers', blockers: openBlockers } });
			continue;
		}

		if (existingPr) {
			pruned.push({ candidate, reason: { kind: 'already-worked', pr: existingPr } });
			continue;
		}

		eligible.push(candidate);
	}

	return { repo, label: READY_LABEL, considered: issues.length, eligible, pruned };
}
