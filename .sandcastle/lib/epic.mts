/**
 * Epic branches.
 *
 * An issue with sub-issues gets a branch of its own: its implementation work
 * lands there, its children are cut from it and squash-merged back into it,
 * and it is that branch — not any child's — that gets the PR into `main`.
 *
 * Three rules decide where that branch comes from:
 *
 * - **The name is deterministic.** `sandcastle/epic-<N>`, from the issue
 *   number, never a title slug — a slug cannot be recomputed after a retitle,
 *   and every guard in the harness keys off the name.
 * - **Prefer a branch the issue already has.** GitHub's own linked-branch list
 *   is read first; a branch already linked and already on origin is reused as
 *   it stands. Only when there is none does the harness create one, and
 *   `createLinkedBranch` creates *and* links it in a single call.
 * - **The prefix is not negotiable.** A linked branch outside `sandcastle/`
 *   cannot be the epic branch: `pushBranch()` would refuse it and the plan
 *   validator would abort the run over it. That is a human's branch for the
 *   same issue, and the harness says so out loud rather than adopting it or
 *   failing three layers down.
 */
import { BRANCH_PREFIX, epicBranchForIssue } from './config.mts';
import { gh, ghJson } from './gh.mts';
import { git, originBranchExists } from './git.mts';

/** Where an epic branch came from. */
export type EpicSource =
	/** Already linked to the issue under the deterministic name. */
	| 'linked'
	/** On origin already, but not linked — an earlier run that got that far. */
	| 'existing'
	/** Created and linked in one `createLinkedBranch` call. */
	| 'created';

export type EpicBranch = { issue: number; branch: string; source: EpicSource };

/** What GitHub knows about an epic issue, as far as branches are concerned. */
export type EpicRefs = {
	/** GraphQL node id of the issue — `createLinkedBranch` needs it. */
	issueId: string;
	/** GraphQL node id of the repository — likewise. */
	repositoryId: string;
	/** Names of every branch already linked to the issue, in GitHub's order. */
	linked: string[];
};

type RelayResponse = {
	data: {
		repository: {
			id: string;
			issue: {
				id: string;
				linkedBranches: { nodes: { ref: { name: string } | null }[] };
			} | null;
		} | null;
	};
};

/**
 * The GraphQL read. `linkedBranches` is the canonical, UI-visible answer to
 * "does this issue already have a branch", which is why it is preferred over
 * guessing from refs on origin.
 */
export function epicRefsQuery(repo: string, issue: number): string[] {
	const [owner, name] = repo.split('/');
	const query =
		`query{repository(owner:"${owner}",name:"${name}"){id ` +
		`issue(number:${issue}){id linkedBranches(first:20){nodes{ref{name}}}}}}`;
	return ['api', 'graphql', '-f', `query=${query}`];
}

export function parseEpicRefs(payload: RelayResponse, issue: number): EpicRefs {
	const repository = payload.data.repository;
	if (!repository?.issue) throw new Error(`#${issue}: no such issue, or its branch data is unreadable`);

	return {
		issueId: repository.issue.id,
		repositoryId: repository.id,
		linked: repository.issue.linkedBranches.nodes.flatMap((node) => (node.ref ? [node.ref.name] : [])),
	};
}

/**
 * The GraphQL write, which creates the branch on origin *and* links it to the
 * issue. The prefix guard is here as well as in `pushBranch()` — this call
 * reaches origin without going through it, so it carries its own copy of the
 * only rule that matters about a branch name.
 */
export function createLinkedBranchArgs(refs: EpicRefs, name: string, oid: string): string[] {
	if (!name.startsWith(BRANCH_PREFIX)) {
		throw new Error(`refusing to create ${name}: only ${BRANCH_PREFIX}* branches may be created on origin`);
	}

	const mutation =
		'mutation($issueId:ID!,$oid:GitObjectID!,$name:String!,$repositoryId:ID!){' +
		'createLinkedBranch(input:{issueId:$issueId,oid:$oid,name:$name,repositoryId:$repositoryId})' +
		'{linkedBranch{ref{name}}}}';

	return [
		'api',
		'graphql',
		'-f',
		`query=${mutation}`,
		'-f',
		`issueId=${refs.issueId}`,
		'-f',
		`repositoryId=${refs.repositoryId}`,
		'-f',
		`name=${name}`,
		'-f',
		`oid=${oid}`,
	];
}

/* -- The GitHub seam ------------------------------------------------------- */

export type EpicDeps = {
	/** Linked-branch names and node ids for one epic issue. */
	refs: (issue: number) => EpicRefs;
	/** Whether origin already carries a branch of this name. */
	onOrigin: (branch: string) => boolean;
	/** Commit the branch is cut from — the base branch's tip on origin. */
	baseOid: () => string;
	/** Creates the branch on origin and links it to the issue, in one call. */
	createLinked: (refs: EpicRefs, name: string, oid: string) => void;
	log: (message: string) => void;
};

/**
 * Get the epic branch for an issue, creating and linking one if it has none.
 *
 * Reused as-is when it is already there; a linked branch under a different
 * name is reported and left alone. Idempotent — a second call on the same
 * issue reuses what the first one made.
 */
export function ensureEpicBranch(deps: EpicDeps, issue: number): EpicBranch {
	const branch = epicBranchForIssue(issue);
	const refs = deps.refs(issue);

	if (refs.linked.includes(branch)) {
		deps.log(`#${issue}: epic branch ${branch} is already linked to the issue`);
		return { issue, branch, source: 'linked' };
	}

	// A branch under a name the harness cannot push is not usable as an epic
	// branch, and adopting it would abort the run at the plan validator with a
	// far less obvious message. Named here, once, and then set aside.
	const foreign = refs.linked.filter((name) => !name.startsWith(BRANCH_PREFIX));
	if (foreign.length > 0) {
		deps.log(
			`#${issue}: linked branch(es) ${foreign.join(', ')} are outside ${BRANCH_PREFIX} and cannot be pushed by the ` +
				`harness — using ${branch} instead. Any work on those branches is not part of this epic.`
		);
	}

	// Linking is what `createLinkedBranch` is for, and it fails outright on a
	// name origin already has. An unlinked branch under the right name is an
	// earlier run that got this far; take it.
	if (deps.onOrigin(branch)) {
		deps.log(`#${issue}: epic branch ${branch} is already on origin (unlinked); reusing it`);
		return { issue, branch, source: 'existing' };
	}

	const oid = deps.baseOid();
	deps.createLinked(refs, branch, oid);
	deps.log(`#${issue}: created and linked epic branch ${branch} at ${oid.slice(0, 7)}`);
	return { issue, branch, source: 'created' };
}

/** Ensure every epic in the batch has its branch. Sequential — these are cheap API calls. */
export function ensureEpicBranches(deps: EpicDeps, issues: readonly number[]): EpicBranch[] {
	return issues.map((issue) => ensureEpicBranch(deps, issue));
}

export function realEpicDeps(cwd: string, repo: string, baseBranch: string, log: (message: string) => void): EpicDeps {
	return {
		log,
		refs: (issue) => parseEpicRefs(ghJson<RelayResponse>(epicRefsQuery(repo, issue)), issue),
		onOrigin: (branch) => originBranchExists(cwd, branch),
		// The base branch's tip *on origin*, not the local ref: the branch is
		// created server-side, and a local ref ahead of origin names a commit
		// GitHub has never seen.
		baseOid: () => git(cwd, ['rev-parse', `refs/remotes/origin/${baseBranch}`]),
		createLinked: (refs, name, oid) => {
			gh(createLinkedBranchArgs(refs, name, oid));
		},
	};
}
