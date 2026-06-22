/**
 * Remember which tab a reader picks and apply it to every tabber, including on
 * later pages. The install steps are shown in a Docker/Binary <tabber>; once a
 * reader picks one, that choice should follow them across the site.
 *
 * The choice is stored by tab label, so tabbers that share a label (Docker,
 * Binary, ...) stay in sync. TabberNeue dispatches a "tabber:tabchange" event
 * and activates a tab on a plain click of its header link; this uses only those
 * two and touches no TabberNeue internals.
 */
(() => {
	const STORAGE_KEY = "wikven-tabber-choice";
	// Set while we drive a tab change ourselves, so the change handler below does
	// not treat it as a reader's selection.
	let applying = false;

	const readChoice = () => {
		try {
			return localStorage.getItem(STORAGE_KEY);
		} catch {
			// Storage can be unavailable (private mode, blocked cookies).
			return null;
		}
	};

	const writeChoice = (label) => {
		try {
			localStorage.setItem(STORAGE_KEY, label);
		} catch {
			// Best effort: without storage the choice just does not persist.
		}
	};

	const tabLabel = (tab) => (tab.textContent || "").trim();

	// Activate a tab the way a click would, minus the header link's default jump
	// to the panel anchor (which would scroll the page). TabberNeue binds its
	// click handler lazily, when a tabber first scrolls into view, so an early
	// call is a harmless no-op and the caller retries until it takes.
	const selectTab = (tab) => {
		const href = tab.getAttribute("href");
		if (href !== null) {
			tab.removeAttribute("href");
		}
		applying = true;
		tab.click();
		applying = false;
		if (href !== null) {
			tab.setAttribute("href", href);
		}
		return tab.getAttribute("aria-selected") === "true";
	};

	const applyChoice = (label, attempt) => {
		if (!label) {
			return;
		}
		let pending = false;
		for (const tab of document.querySelectorAll(".tabber__tab")) {
			if (
				tabLabel(tab) !== label ||
				tab.getAttribute("aria-selected") === "true"
			) {
				continue;
			}
			if (!selectTab(tab)) {
				pending = true;
			}
		}
		// A wanted tab has not switched yet: its tabber is not live. Retry for a
		// short while (about half a second at 60fps), then give up.
		if (pending && attempt < 30) {
			requestAnimationFrame(() => applyChoice(label, attempt + 1));
		}
	};

	// Save and propagate the reader's own selection.
	document.documentElement.addEventListener("tabber:tabchange", (e) => {
		if (applying) {
			return;
		}
		const source = e.detail?.source;
		if (source !== "user-click" && source !== "user-keyboard") {
			return;
		}
		const tab = e.target.querySelector(
			":scope > .tabber__header > .tabber__tabs > .tabber__tab[aria-selected='true']",
		);
		if (!tab) {
			return;
		}
		const label = tabLabel(tab);
		writeChoice(label);
		applyChoice(label, 0);
	});

	// Line every tabber up with the stored choice on each (re)render.
	mw.hook("wikipage.content").add(() => applyChoice(readChoice(), 0));
})();
