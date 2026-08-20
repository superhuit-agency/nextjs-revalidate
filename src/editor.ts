/**
 * Block editor notices.
 *
 * Core hides classic `admin_notices` output on block editor screens, so
 * anything this plugin has to say there has to be dispatched to `core/notices`
 * instead. Two things do:
 *
 *  - the purge sendback, which answers an action the reader has just taken,
 *    and drops its query arg so a reload does not repeat it;
 *  - degraded revalidation, which is a condition the reader has not been told
 *    about at all — no query arg to drop, and nothing to acknowledge, so it is
 *    not dismissible.
 */

export {};

const PURGED_ARG = "nextjs-revalidate-purged";
const PURGED_NOTICE_ID = "nextjs-revalidate-purged-notice";
const DEGRADED_NOTICE_ID = "nextjs-revalidate-degraded-notice";

type Notices = {
	createNotice: (
		status: string,
		message: string,
		options?: Record<string, unknown>
	) => void;
};

function dropPurgedArg() {
	const url = new URL(window.location.href);
	if (!url.searchParams.has(PURGED_ARG)) return;

	url.searchParams.delete(PURGED_ARG);
	window.history.replaceState({}, "", url.toString());
}

function showPurgedNotice(notices: Notices) {
	const notice = window.nextjs_revalidate_notice;
	if (!notice?.message) return;

	notices.createNotice(notice.status, notice.message, {
		id: PURGED_NOTICE_ID,
		isDismissible: true,
	});

	dropPurgedArg();
}

function showDegradedNotice(notices: Notices) {
	const notice = window.nextjs_revalidate_degraded_notice;
	if (!notice?.message) return;

	notices.createNotice(notice.status, notice.message, {
		id: DEGRADED_NOTICE_ID,
		isDismissible: false,
		actions: notice.actions ?? [],
	});
}

function init() {
	const notices = window.wp?.data?.dispatch("core/notices");
	if (!notices) return;

	showPurgedNotice(notices);
	showDegradedNotice(notices);
}

window.addEventListener("load", function () {
	init();
});
