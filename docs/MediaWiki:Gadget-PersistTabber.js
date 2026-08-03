/**
 * Remember which tab a reader picks and apply it to every tabber, including on
 * later pages. The install steps are shown in a Docker/Binary <tabber>; once a
 * reader picks one, that choice should follow them across the site and across
 * every tabber on the page.
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

	// The header tab a tabber carries for the given label, or null when it has
	// no such tab.
	const tabForLabel = (tabber, label) => {
		for (const tab of tabber.querySelectorAll(
			":scope > .tabber__header > .tabber__tabs > .tabber__tab",
		)) {
			if (tabLabel(tab) === label) {
				return tab;
			}
		}
		return null;
	};

	// Activate a tab the way a click would, minus the header link's default jump
	// to the panel anchor (which would scroll the page).
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

	// Line one tabber up with the chosen label. TabberNeue binds a tabber's click
	// handler lazily, when it first scrolls into view, so a call can land a frame
	// or two before the handler is ready; retry over a few frames until it takes.
	const applyToTabber = (tabber, label, attempt) => {
		if (!label) {
			return;
		}
		const tab = tabForLabel(tabber, label);
		if (!tab || tab.getAttribute("aria-selected") === "true") {
			return;
		}
		if (!selectTab(tab) && attempt < 30) {
			requestAnimationFrame(() => applyToTabber(tabber, label, attempt + 1));
		}
	};

	// Line every tabber on the page up with the chosen label.
	const applyChoice = (label) => {
		for (const tabber of document.querySelectorAll(".tabber")) {
			applyToTabber(tabber, label, 0);
		}
	};

	// A tabber is clickable only once it has scrolled into view, so a live
	// selection reaches only the tabbers on screen at that moment. Watch every
	// tabber and line it up with the stored choice as it becomes visible, so one
	// that was off screen when the reader picked a tab catches up on reveal.
	const visibility = new IntersectionObserver((entries) => {
		for (const entry of entries) {
			if (entry.isIntersecting) {
				applyToTabber(entry.target, readChoice(), 0);
			}
		}
	});

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
		applyChoice(label);
	});

	// On each (re)render, watch every tabber for visibility and line the ones
	// already on screen up with the stored choice.
	mw.hook("wikipage.content").add(() => {
		for (const tabber of document.querySelectorAll(".tabber")) {
			visibility.observe(tabber);
		}
		applyChoice(readChoice());
	});
})();
