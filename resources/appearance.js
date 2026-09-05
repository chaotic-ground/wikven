// The skin list on the generated settings page. The display choices beside it are MobileFrontend's
// own, rendered by the module Adder queues there; this is the one part that is wikven's, because
// each skin's copy of a page is its own file and choosing one is a navigation rather than a
// preference.
//
// Minerva's copy of the list is written by the build, which knows each page's own links. The other
// skins carry theirs in the chrome, so it is taken from there rather than worked out again here.
//
// The static bundle runs every module on every page, so this leaves where there is no list to fill.
(() => {
	// Both queries below need body content. The bundle is a plain <script src> in <head> -- on the
	// generated settings page it is around 3300 bytes in, where the head ends past 6000 and the
	// entries this reads are past 21000 -- so a module running as it is implemented can be looking
	// at a document whose body has not been parsed. Running once with no wait meant that when it
	// lost, container was null, the fill was skipped, and nothing ever tried again: a reader got a
	// heading, a description and nothing under them, with no console error to say so.
	const fill = () => {
		const container = document.querySelector("#wikven-appearance-skins");
		if (!container || container.children.length) {
			return;
		}

		const entries = document.querySelectorAll('[id^="t-wikven-skin-"]');
		if (!entries.length) {
			return;
		}

		const list = document.createElement("ul");
		list.className = "wikven-skin-list";

		for (const entry of entries) {
			const item = document.createElement("li");
			item.className = entry.className.includes("active")
				? "wikven-skin-item active"
				: "wikven-skin-item";

			const link = entry.querySelector("a");
			const label = document.createElement(link ? "a" : "span");
			if (link) {
				label.href = link.getAttribute("href");
			}
			label.textContent = entry.textContent.trim();

			item.append(label);
			list.append(item);
		}

		container.append(list);
	};

	// The same wait the other modules in this bundle make, and for the same reason. It also keeps
	// this ahead of citizen-skins.js, which reads the same entries and then removes the toolbox they
	// live in: both defer the same way, so the bundle's order still decides, as it does today.
	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", fill);
	} else {
		fill();
	}
})();
