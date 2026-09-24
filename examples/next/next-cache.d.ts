/**
 * The one export of `next/cache` the reference route calls, with the signature
 * Next.js 16 gives it — declared here so the route type-checks in this
 * repository, which does not install Next.js. An app copying the route has the
 * real declaration from its own `next` and does not need this file.
 */
declare module "next/cache" {
	export function revalidateTag(tag: string, profile: string | { expire?: number }): void;
}
