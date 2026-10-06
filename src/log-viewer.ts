/**
 * The log viewer, on the settings screen's Debug tab.
 *
 * The server renders it with the page; this refreshes only its body, over
 * `admin-ajax.php`, with the HTML the server composed for it — the page and the
 * refresh go through one PHP function, so they cannot disagree, and nothing
 * here escapes or formats a line.
 *
 * Live refresh polls the same action every five seconds. It is off on every
 * page load and saved nowhere, because each poll boots WordPress: it runs only
 * while somebody has just asked for it. One request at a time — the next poll
 * is scheduled when the previous one answers — paused while the tab is hidden,
 * and stopped for good, with the reason shown, when logging is off, the nonce
 * is rejected, or a request fails. It never retries on its own.
 */

export {};

const LIVE_INTERVAL = 5000;

/** How close to the bottom, in pixels, still counts as at the bottom. */
const BOTTOM_TOLERANCE = 4;

type Messages = {
	liveOff: string;
	liveExpired: string;
	liveFailed: string;
	expired: string;
	failed: string;
};

type Answer = {
	success?: boolean;
	data?: { enabled?: boolean; html?: string; reason?: string };
};

let reveal: () => void = () => {};

/**
 * Scroll the viewer to its end if it is following the log. Called when its tab
 * is shown: a box that was hidden when it loaded or refreshed could not be
 * scrolled then.
 */
export function revealLogViewer() {
	reveal();
}

export function initLogViewer() {
	const viewer = document.querySelector<HTMLElement>(".njr-log-viewer");
	if (!viewer) return;

	const body = viewer.querySelector<HTMLElement>(".njr-log-viewer__body");
	const refreshButton = viewer.querySelector<HTMLButtonElement>(".njr-log-viewer__refresh");
	const liveToggle = viewer.querySelector<HTMLInputElement>(".njr-log-viewer__live");
	const liveStatus = viewer.querySelector<HTMLElement>(".njr-log-viewer__live-status");
	if (!body || !refreshButton || !liveToggle || !liveStatus) return;

	const url = viewer.dataset.url || "";
	const action = viewer.dataset.action || "";
	const nonce = viewer.dataset.nonce || "";
	const messages = JSON.parse(viewer.dataset.messages || "{}") as Messages;

	/** Whether the operator is at the end of the log, rather than reading above it. */
	let following = true;
	let live = false;
	let inFlight = false;
	let timer: number | null = null;

	const box = () => body.querySelector<HTMLElement>(".njr-log-viewer__log");

	const toBottom = () => {
		const log = box();
		if (log) log.scrollTop = log.scrollHeight;
	};

	// Scroll events do not bubble, so they are caught on the way down: the box
	// is replaced by every refresh, and this listener outlives it.
	body.addEventListener(
		"scroll",
		() => {
			const log = box();
			// A hidden box has no height, and says nothing about the reader.
			if (!log || log.clientHeight === 0) return;
			following = log.scrollHeight - log.scrollTop - log.clientHeight <= BOTTOM_TOLERANCE;
		},
		true
	);

	/**
	 * Swap the body for the server's, then either follow the end of the log or
	 * keep the reader where they were.
	 */
	const replace = (html: string, follow: boolean) => {
		const previous = box();
		const scrollTop = previous ? previous.scrollTop : 0;

		body.innerHTML = html;

		const log = box();
		if (!log) return;

		if (follow) {
			log.scrollTop = log.scrollHeight;
			following = true;
		} else {
			log.scrollTop = scrollTop;
		}
	};

	const clearTimer = () => {
		if (timer === null) return;
		window.clearTimeout(timer);
		timer = null;
	};

	const stopLive = (reason: string) => {
		live = false;
		liveToggle.checked = false;
		clearTimer();
		liveStatus.textContent = reason;
	};

	const schedule = () => {
		clearTimer();
		// Hidden, it waits for `visibilitychange` instead.
		if (!live || document.hidden) return;
		timer = window.setTimeout(() => {
			timer = null;
			request();
		}, LIVE_INTERVAL);
	};

	const fail = (reason: string | undefined) => {
		const expired = reason === "nonce";
		if (live) stopLive(expired ? messages.liveExpired : messages.liveFailed);
		else liveStatus.textContent = expired ? messages.expired : messages.failed;
	};

	const request = () => {
		if (inFlight) return;
		inFlight = true;
		body.setAttribute("aria-busy", "true");

		// A manual refresh with live refresh off always shows the end of the
		// log. With it on, either kind keeps a reader who scrolled up in place.
		const keepPlace = live;

		const form = new FormData();
		form.append("action", action);
		form.append("nonce", nonce);

		fetch(url, { method: "POST", credentials: "same-origin", body: form })
			.then((response) => response.json() as Promise<Answer>)
			.then(
				(answer) => {
					inFlight = false;
					body.removeAttribute("aria-busy");

					if (!answer || answer.success !== true || !answer.data) {
						fail(answer && answer.data ? answer.data.reason : undefined);
						return;
					}

					replace(answer.data.html || "", keepPlace ? following : true);

					if (!answer.data.enabled) {
						if (live) stopLive(messages.liveOff);
						return;
					}

					if (live) schedule();
					else liveStatus.textContent = "";
				},
				() => {
					// No answer, or one that is not JSON: admin-ajax.php's own
					// `0` for a session that has ended, a fatal, a proxy's page.
					inFlight = false;
					body.removeAttribute("aria-busy");
					fail(undefined);
				}
			);
	};

	refreshButton.addEventListener("click", () => request());

	// A browser restoring the form on back/forward must not turn it on.
	liveToggle.checked = false;
	liveToggle.addEventListener("change", () => {
		if (liveToggle.checked) {
			live = true;
			liveStatus.textContent = "";
			request();
		} else {
			live = false;
			clearTimer();
			liveStatus.textContent = "";
		}
	});

	document.addEventListener("visibilitychange", () => {
		if (!live) return;
		if (document.hidden) clearTimer();
		else if (!inFlight && timer === null) request();
	});

	reveal = () => {
		if (following) toBottom();
	};

	toBottom();
}
