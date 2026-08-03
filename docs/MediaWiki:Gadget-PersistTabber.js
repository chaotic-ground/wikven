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
 *
 * ResourceLoader parses a wiki-page script as ES2016 before it serves it, and
 * replaces a module it cannot parse with a console error — the script then
 * never runs, and nothing else reports it. Keep to that vintage: no optional
 * catch binding, no optional chaining, no nullish coalescing.
 */
(() => {
	const STORAGE_KEY = "wikven-tabber-choice";
	const TAB_SELECTOR =
		":scope > .tabber__header > .tabber__tabs > .tabber__tab";

	const readChoice = () => {
		try {
			return localStorage.getItem(STORAGE_KEY);
		} catch (_e) {
			// Storage can be unavailable (private mode, blocked cookies).
			return null;
		}
	};

	// The reader's choice, mirrored here so everything below still works when
	// storage is unavailable and only the persistence is lost.
	let choice = readChoice();

	const setChoice = (label) => {
		choice = label;
		try {
			localStorage.setItem(STORAGE_KEY, label);
		} catch (_e) {
			// Best effort: without storage the choice just does not persist.
		}
	};

	const tabLabel = (tab) =>
		tab === null ? "" : (tab.textContent || "").trim();

	const isSelected = (tab) => tab.getAttribute("aria-selected") === "true";

	// The header tab a tabber carries for the given label, or null when it has
	// no such tab.
	const tabForLabel = (tabber, label) => {
		for (const tab of tabber.querySelectorAll(TAB_SELECTOR)) {
			if (tabLabel(tab) === label) {
				return tab;
			}
		}
		return null;
	};

	// The header tab that names a panel: TabberNeue points each panel back at
	// its own tab with aria-labelledby.
	const tabForPanel = (panel) => {
		const id = panel.getAttribute("aria-labelledby");
		return id === null ? null : document.getElementById(id);
	};

	// The tab a "tabber:tabchange" event switched to, or null.
	const changedTab = (event) => {
		const detail = event.detail;
		const panel = detail ? document.getElementById(detail.panelId) : null;
		return panel === null ? null : tabForPanel(panel);
	};

	// The label of the tab whose panel the URL fragment points into, or "" when
	// the fragment names no tab panel.
	const labelFromHash = () => {
		const fragment = location.hash.slice(1);
		if (!fragment) {
			return "";
		}
		let id = fragment;
		try {
			id = decodeURIComponent(fragment);
		} catch (_e) {
			// A malformed escape is no id of ours; try the fragment as written.
		}
		const target = document.getElementById(id);
		const panel = target === null ? null : target.closest(".tabber__panel");
		return panel === null ? "" : tabLabel(tabForPanel(panel));
	};

	// Tabs we activated ourselves, each awaiting the change TabberNeue reports
	// for it. See the capturing listener below.
	const propagated = new Set();

	// Activate a tab the way a click would, minus the header link's default jump
	// to the panel anchor (which would scroll the page).
	const selectTab = (tab) => {
		const href = tab.getAttribute("href");
		if (href !== null) {
			tab.removeAttribute("href");
		}
		propagated.add(tab);
		tab.click();
		if (href !== null) {
			tab.setAttribute("href", href);
		}
	};

	// The tabbers in the viewport. TabberNeue attaches a tabber's click handler
	// only while it is on screen, so a click on any other one goes nowhere.
	const onScreen = new Set();

	// Line one tabber up with the chosen label.
	const applyToTabber = (tabber) => {
		if (!choice || !onScreen.has(tabber)) {
			return;
		}
		const tab = tabForLabel(tabber, choice);
		if (tab !== null && !isSelected(tab)) {
			selectTab(tab);
		}
	};

	// Line every tabber the reader can see up with the chosen label; the rest
	// catch up as they scroll into view.
	const applyChoice = () => {
		for (const tabber of onScreen) {
			applyToTabber(tabber);
		}
	};

	const visibility = new IntersectionObserver((entries) => {
		for (const entry of entries) {
			const tabber = entry.target;
			if (!entry.isIntersecting) {
				onScreen.delete(tabber);
				continue;
			}
			onScreen.add(tabber);
			// TabberNeue attaches this tabber's click handler from an observer of
			// its own, in this same round of intersection callbacks and in an
			// order neither of us picks. Wait a frame, so the click lands on a
			// header that is listening.
			requestAnimationFrame(() => applyToTabber(tabber));
		}
	});

	// TabberNeue reads the click we synthesise as the reader's own and rewrites
	// the URL to that tabber's panel, throwing away the fragment the reader
	// arrived on or picked. Its hash router listens on the document element as
	// the event bubbles, so catch the events we caused on the way down and stop
	// them short of it. That also keeps the handler below from reading one of
	// them back as a selection.
	const stopPropagatedChange = (event) => {
		const tab = changedTab(event);
		if (tab !== null && propagated.has(tab)) {
			propagated.delete(tab);
			event.stopPropagation();
		}
	};
	window.addEventListener("tabber:tabchange", stopPropagatedChange, true);

	// Save and propagate the reader's own selection.
	document.documentElement.addEventListener("tabber:tabchange", (event) => {
		const detail = event.detail;
		const source = detail ? detail.source : "";
		if (source !== "user-click" && source !== "user-keyboard") {
			return;
		}
		const label = tabLabel(changedTab(event));
		if (!label || label === choice) {
			return;
		}
		setChoice(label);
		applyChoice();
	});

	// A fragment that names a tab panel (#tabber-Docker) opens that panel in its
	// own tabber, and TabberNeue follows the fragment as it changes. Read it the
	// same way, so the rest of the page follows too.
	window.addEventListener("hashchange", () => {
		const label = labelFromHash();
		if (label && label !== choice) {
			setChoice(label);
			applyChoice();
		}
	});

	// On each (re)render, adopt the fragment the reader arrived on and watch
	// every tabber; each is lined up with the choice as it becomes visible.
	mw.hook("wikipage.content").add(() => {
		const label = labelFromHash();
		if (label) {
			setChoice(label);
		}
		for (const tabber of document.querySelectorAll(".tabber")) {
			visibility.observe(tabber);
		}
	});
})();
