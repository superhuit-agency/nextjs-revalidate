const INTERVAL = 2; // seconds

let checkProgressInterval: number;

function getNotice() {
	return document.querySelector(".nextjs-revalidate-queue__notice");
}

function init() {
	if (!getNotice()) return;

	if (!window?.nextjs_revalidate?.url || !window?.nextjs_revalidate?.nonce)
		return;

	checkProgressInterval = window.setInterval(
		checkRevalidateAllProgress,
		1000 * INTERVAL
	);
}

function checkRevalidateAllProgress() {
	const url = new URL(window.nextjs_revalidate.url);
	url.searchParams.append("action", "nextjs-revalidate-queue-progress");
	url.searchParams.append("_ajax_nonce", window.nextjs_revalidate.nonce);

	fetch(url)
		.then((res) => res.json())
		.then(({ data }) => {
			const notice = getNotice();
			const progress = notice.querySelector(
				".nextjs-revalidate-queue__progress"
			);

			if (data.status === "running")
				progress.textContent = `${data.nbLeft} page(s) left to purge.`;
			else if (data.status === "done") {
				progress.textContent = `0 page left to purge 🎉`;
				notice.classList.remove("notice-info");
				notice.classList.add("notice-success");
				window.clearInterval(checkProgressInterval);
			}
		})
		.catch((err) => {
			console.error(err);
		});
}

window.addEventListener("load", function () {
	init();
});
