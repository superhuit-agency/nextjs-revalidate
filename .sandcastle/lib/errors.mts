/**
 * Every phase turns a thrown thing into an outcome rather than letting it take
 * the batch down, and every one of them needs the same sentence out of it.
 * `catch` binds `unknown`, so this is the one place that narrowing happens.
 */
export function messageOf(error: unknown): string {
	return error instanceof Error ? error.message : String(error);
}
