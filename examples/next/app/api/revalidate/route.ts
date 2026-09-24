/**
 * A reference route for Next.js Revalidate 2.x — `app/api/revalidate/route.ts`.
 *
 * The plugin tells this route which WordPress content changed; which cache tags
 * that expires is this app's decision, never the plugin's (ADR 0033). This file
 * is one answer to that decision, for an app tagging its entries with the
 * scheme below. Copy it, then make `tagsFor()` say what *your* app tags.
 *
 * The example scheme — what an entry carries each tag for:
 *
 * | Tag               | Carried by                                                        |
 * | ----------------- | ----------------------------------------------------------------- |
 * | `node:{id}`       | a single post's own data, keyed by its WordPress ID               |
 * | `type:{type}`     | listings and archives of one post type, or of one taxonomy's terms |
 * | `nodes`           | every single-node entry, whatever its ID                          |
 * | `uris`            | the resolution of a URI to what is there — routing and redirects  |
 * | `menu:{location}` | the menu rendered at one theme location                           |
 * | `options`         | site-wide data: settings, and the FSE template snapshot           |
 * | `content`         | every entry built from WordPress content, all of the above        |
 *
 * The contract itself is the README's "The front-end contract" section. The
 * types below are that contract, field for field.
 */

import { timingSafeEqual } from "node:crypto";
import { revalidateTag } from "next/cache";

// The contract
// ====

/** The only version this route understands. Raised only for a breaking change. */
export const CONTRACT_VERSION = 2;

/** A path from the domain root, as WPGraphQL's `uri`: `/hello-world/`. */
export type Uri = string;

/** One side of a post change: where the front-end shows the post. */
export interface PostSide {
	uri: Uri;
}

/**
 * A post, as the front-end sees it before and after.
 *
 * `null` means the post is not on the front-end on that side: `before` is null
 * for a publish, `after` for a post that left the front-end — unpublished,
 * trashed or deleted alike. Never both null.
 */
export interface PostChange {
	subject: "post";
	id: number;
	type: string;
	before: PostSide | null;
	after: PostSide | null;
}

/** A redirect's source path — one change per path a redirect change affects. */
export interface RedirectChange {
	subject: "redirect";
	uri: Uri;
}

/** A path somebody named, without saying what is held there. */
export interface PathChange {
	subject: "path";
	uri: Uri;
}

/** A menu, and the theme locations it is assigned to — possibly none. */
export interface MenuChange {
	subject: "menu";
	id: number;
	locations: string[];
}

/** The FSE templates, as one snapshot. Never names which template changed. */
export interface TemplatesChange {
	subject: "templates";
}

/** Revalidate all of the whole site: no fields. */
export interface SiteAllChange {
	subject: "all";
	type?: undefined;
}

/**
 * Revalidate all of one post type, with the revalidatable taxonomies
 * registered for it — possibly none.
 */
export interface TypeAllChange {
	subject: "all";
	type: string;
	taxonomies: string[];
}

export type AllChange = SiteAllChange | TypeAllChange;

/**
 * Every subject v2.0 sends. A later 2.x may send a subject or a field this
 * union does not name (rule 1): ignore it, as `tagsFor()` does.
 */
export type Change =
	| PostChange
	| RedirectChange
	| PathChange
	| MenuChange
	| TemplatesChange
	| AllChange;

/** The body of the plugin's `POST`. */
export interface RevalidateRequest {
	version: typeof CONTRACT_VERSION;
	changes: Change[];
}

// The route
// ====

/**
 * The secret this app shares with the plugin — the plugin's "Revalidate
 * secret" setting.
 */
const SECRET = process.env.REVALIDATE_SECRET ?? "";

export async function POST(request: Request): Promise<Response> {
	if (!authorised(request.headers.get("authorization"))) {
		return answer(401, { error: "The secret does not match." });
	}

	let body: unknown;
	try {
		body = await request.json();
	} catch {
		return answer(400, { error: "The body is not JSON." });
	}

	if (!isObject(body) || !Array.isArray(body.changes)) {
		return answer(400, { error: "The body carries no changes." });
	}

	// Rule 2: a version this route does not know is a breaking change it has
	// not been taught. Answered with a 4xx, so the plugin records a failure and
	// its degraded notice says so, rather than expiring the wrong things.
	if (body.version !== CONTRACT_VERSION) {
		return answer(400, { error: `Unsupported contract version: ${String(body.version)}.` });
	}

	const payload = body as unknown as RevalidateRequest;

	const tags = new Set<string>();
	for (const change of payload.changes) {
		if (!isObject(change)) continue;
		for (const tag of tagsFor(change)) tags.add(tag);
	}

	// Mark stale, and rebuild on the next visit: `'max'` serves the stale entry
	// once while the fresh one is built in the background.
	for (const tag of tags) revalidateTag(tag, "max");

	// Any 2xx is a success to the plugin; there is nothing to say back.
	return new Response(null, { status: 204 });
}

// The mapping
// ====

/**
 * The tags one change expires, in the example scheme above.
 *
 * Every subject v2.0 sends has a case; anything else is ignored (rule 1).
 */
export function tagsFor(change: Change): string[] {
	switch (change.subject) {
		case "post":
			return postTags(change);

		// The redirect is resolved with the URI it redirects from.
		case "redirect":
			return ["uris"];

		// Somebody named a path. What this app holds for it is reached through
		// the URI's resolution; an app keying entries by path would also expire
		// `path:${change.uri}` here.
		case "path":
			return ["uris"];

		// A menu assigned to no location is rendered by nothing this scheme
		// tags. An app rendering menus by ID would add `menu:id:${change.id}`.
		case "menu":
			return change.locations.map((location) => `menu:${location}`);

		case "templates":
			return ["options"];

		case "all":
			return allTags(change);

		default:
			return [];
	}
}

/**
 * A post: its own entry and its type's listings always — an edit can change
 * the title or excerpt a listing shows — and the URI resolution whenever the
 * two sides disagree about where the post is. That comparison is what tells a
 * publish, an unpublish, a trash, a delete and a slug change apart from an
 * edit, without the plugin naming an event.
 */
function postTags(change: PostChange): string[] {
	const tags = [`node:${change.id}`, `type:${change.type}`];

	const before = change.before?.uri ?? null;
	const after = change.after?.uri ?? null;

	// Published (no before), left the front-end (no after), or moved: the old
	// URI must stop resolving to this post, the new one must start.
	if (before !== after) tags.push("uris");

	return tags;
}

/**
 * Revalidate all. Of the whole site, everything; of one post type, its
 * listings, the listings of its taxonomies' terms, and every single-node
 * entry, since this scheme cannot name the IDs of one type's posts.
 */
function allTags(change: AllChange): string[] {
	if (change.type === undefined) return ["content"];

	return [
		`type:${change.type}`,
		...change.taxonomies.map((taxonomy) => `type:${taxonomy}`),
		"nodes",
	];
}

// Helpers
// ====

/**
 * Whether the `Authorization` header carries the secret, compared in constant
 * time. A site with no secret configured here authorises nothing.
 */
function authorised(header: string | null): boolean {
	if (SECRET === "" || header === null) return false;

	const given = Buffer.from(header);
	const expected = Buffer.from(`Bearer ${SECRET}`);

	return given.length === expected.length && timingSafeEqual(given, expected);
}

function isObject(value: unknown): value is Record<string, unknown> {
	return typeof value === "object" && value !== null && !Array.isArray(value);
}

function answer(status: number, body: Record<string, unknown>): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: { "Content-Type": "application/json" },
	});
}
