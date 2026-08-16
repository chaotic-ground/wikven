// The skin list on the generated settings page. The display choices beside it are MobileFrontend's
// own, rendered by the module Adder queues there; this is the one part that is wikven's, because
// each skin's copy of a page is its own file and choosing one is a navigation rather than a
// preference.
//
// Minerva's copy of the list is written by the build, which knows each page's own links. The other
// skins carry theirs in the chrome, so it is taken from there rather than worked out again here.
//
// The static bundle runs every module on every page, so this leaves where there is no list to fill.
const container = document.querySelector("#wikven-appearance-skins");

if (container && !container.children.length) {
	const entries = document.querySelectorAll('[id^="t-wikven-skin-"]');

	if (entries.length) {
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
	}
}
