import "./settings.css";

function changeTabs(target: HTMLElement) {
	const nav = target.parentNode;

	const newAtiveTabId = target.getAttribute("aria-controls");

	// Remove all current selected tabs
	Array.from(nav.children).forEach((t) =>
		t.setAttribute(
			"aria-selected",
			t.getAttribute("aria-controls") === newAtiveTabId ? "true" : "false"
		)
	);

	// Hide all tab panels except the new active one. Queried from the settings
	// screen rather than from the settings form: the probe panel is a form of
	// its own, beside that one, because a form cannot be nested in another.
	document
		.querySelectorAll('.njr-settings [role="tabpanel"]')
		.forEach((p) =>
			p.setAttribute("aria-hidden", p.id !== newAtiveTabId ? "true" : "false")
		);

	window.location.hash = `#${target.getAttribute('id')}`;
}

function init() {
	const tabs = document.querySelectorAll('[role="tab"]');
	const tabList = document.querySelector('[role="tablist"]');

	// Add a click event handler to each tab
	tabs.forEach((tab) => {
		tab.addEventListener("click", (e: MouseEvent) => changeTabs(e.target as HTMLElement));
	});

	// Enable arrow navigation between tabs in the tab list
	let tabFocus = 0;

	tabList.addEventListener("keydown", (e: KeyboardEvent) => {
		// Move right
		if (e.key === "ArrowRight" || e.key === "ArrowLeft") {
			tabs[tabFocus].setAttribute("tabindex", "-1");
			if (e.key === "ArrowRight") {
				tabFocus++;
				// If we're at the end, go to the start
				if (tabFocus >= tabs.length) {
					tabFocus = 0;
				}
				// Move left
			} else if (e.key === "ArrowLeft") {
				tabFocus--;
				// If we're at the start, move to the end
				if (tabFocus < 0) {
					tabFocus = tabs.length - 1;
				}
			}

			const newActiveTab = tabs[tabFocus] as HTMLButtonElement;

			newActiveTab.setAttribute("tabindex", "0");
			newActiveTab.focus();
		}
	});

	if ( !!window.location.hash ) {
		const tabToFocus = document.querySelector(window.location.hash) as HTMLElement;
		if (tabToFocus) changeTabs(tabToFocus);
	}
}

window.addEventListener("load", function () {
	init();
});
