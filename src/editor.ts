/**
 * Block editor purge notice.
 *
 * Core hides classic `admin_notices` output on block editor screens, so the
 * purge sendback has nothing to say there. This dispatches the same notice to
 * `core/notices`, where the editor renders it, and drops the sendback query
 * arg so a reload does not repeat it.
 */

export {};

const PURGED_ARG = "nextjs-revalidate-purged";
const NOTICE_ID = "nextjs-revalidate-purged-notice";

function dropPurgedArg() {
	const url = new URL(window.location.href);
	if (!url.searchParams.has(PURGED_ARG)) return;

	url.searchParams.delete(PURGED_ARG);
	window.history.replaceState({}, "", url.toString());
}

function init() {
	const notice = window.nextjs_revalidate_notice;
	if (!notice?.message) return;

	const notices = window.wp?.data?.dispatch("core/notices");
	if (!notices) return;

	notices.createNotice(notice.status, notice.message, {
		id: NOTICE_ID,
		isDismissible: true,
	});

	dropPurgedArg();
}

window.addEventListener("load", function () {
	init();
});
