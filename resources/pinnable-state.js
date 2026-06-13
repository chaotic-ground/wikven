/**
 * Persist Vector's "move to sidebar" (pinnable element) state on the static export.
 *
 * Vector keeps some pin states as client preferences, restored from a cookie by
 * the inline <head> script (table of contents, appearance, limited width), and
 * others as server-side-only user options (main menu, page tools). A static
 * export is anonymous and pre-rendered once, so the server-side ones reset on
 * every page load. This module fills the gap with mw.storage.
 *
 * It is driven by the declarative data attributes Vector already emits on each
 * pinnable header (data-feature-name, data-pinnable-element-id,
 * data-pinned-container-id, data-unpinned-container-id), so it covers any element
 * following the same contract. Features Vector already persists client-side
 * (their html class is "...-clientpref-...") are skipped, so we never
 * double-manage a state Vector owns.
 *
 * Vector's initPinnableElement() runs at DOMContentLoaded and positions elements
 * from the html feature class (features.isEnabled). We restore that class and the
 * element location from storage; whichever of us runs first, the move is
 * idempotent.
 */
(() => {
	const FEATURE_PREFIX = "vector-feature-";
	const STORAGE_PREFIX = "wikven:pinnable:";
	const PINNED_HEADER_CLASS = "vector-pinnable-header-pinned";
	const UNPINNED_HEADER_CLASS = "vector-pinnable-header-unpinned";
	const html = document.documentElement;

	const key = (feature) => STORAGE_PREFIX + feature;

	// A feature Vector already persists client-side carries a
	// "vector-feature-<name>-clientpref-*" html class; leave those to Vector.
	const isClientPreference = (feature) =>
		new RegExp(`(?:^| )${FEATURE_PREFIX}${feature}-clientpref-`).test(
			html.className,
		);

	const setFeatureClass = (feature, pinned) => {
		html.classList.remove(`${FEATURE_PREFIX}${feature}-enabled`);
		html.classList.remove(`${FEATURE_PREFIX}${feature}-disabled`);
		html.classList.add(
			`${FEATURE_PREFIX}${feature}-${pinned ? "enabled" : "disabled"}`,
		);
	};

	const moveElement = (header, pinned) => {
		const element = document.getElementById(
			header.getAttribute("data-pinnable-element-id"),
		);
		const target = document.getElementById(
			header.getAttribute(
				pinned ? "data-pinned-container-id" : "data-unpinned-container-id",
			),
		);
		if (element && target && element.parentNode !== target) {
			target.insertAdjacentElement("beforeend", element);
		}
	};

	const restore = () => {
		const headers = document.querySelectorAll(
			".vector-pinnable-header[data-feature-name][data-pinnable-element-id]",
		);
		for (const header of headers) {
			const feature = header.getAttribute("data-feature-name");
			if (isClientPreference(feature)) {
				continue;
			}
			const saved = mw.storage.get(key(feature));
			if (saved !== "1" && saved !== "0") {
				continue;
			}
			const pinned = saved === "1";
			setFeatureClass(feature, pinned);
			header.classList.toggle(PINNED_HEADER_CLASS, pinned);
			header.classList.toggle(UNPINNED_HEADER_CLASS, !pinned);
			moveElement(header, pinned);
		}
	};

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", restore);
	} else {
		restore();
	}

	// Record the user's choice when they click "move to sidebar" / "hide". Vector's
	// own handler performs the live DOM move; we only persist the resulting state.
	document.addEventListener("click", (event) => {
		const target = event.target;
		if (!(target instanceof Element)) {
			return;
		}
		const button = target.closest(
			".vector-pinnable-header-pin-button, .vector-pinnable-header-unpin-button",
		);
		if (!button) {
			return;
		}
		const header = button.closest(".vector-pinnable-header[data-feature-name]");
		if (!header) {
			return;
		}
		const feature = header.getAttribute("data-feature-name");
		if (isClientPreference(feature)) {
			return;
		}
		mw.storage.set(
			key(feature),
			button.classList.contains("vector-pinnable-header-pin-button")
				? "1"
				: "0",
		);
	});
})();
