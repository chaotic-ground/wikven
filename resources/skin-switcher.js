/**
 * Navigate between the per-skin copies of the static export.
 *
 * The build renders each enabled skin into its own output: the main skin at the
 * dist root, every other skin under dist/<skin>/. The footer holds a <select> of
 * the enabled skins (rendered by the SkinAddFooterLinks hook); this wires it so
 * choosing a skin loads the same page from that skin's copy. Internal links are
 * relative, so once the reader is inside a skin's subtree they stay there.
 *
 * The choice is not persisted: a fresh visit lands on whatever URL was opened
 * (the indexed main skin at the root). On a plain static host there is no way to
 * redirect to a stored skin without a flash, so the switch stays explicit.
 */
(() => {
	const main = mw.config.get("wgWikvenMainSkin");
	const current = mw.config.get("skin");

	// How many trailing path segments the page itself owns. Every slash in a title
	// becomes a real directory in the export ("Getting Started/ko" is written to
	// "Getting_Started/ko.html"), so the page owns one segment per slash plus the
	// file; a namespace colon makes no directory ("File:logo.svg.html" is a single
	// segment). Only the count is read, so the title's underscores and any
	// percent-encoding of non-ASCII characters do not matter. This is the signal
	// the build places the file by, and the only one to hand: a static export
	// publishes no base path (no wgScriptPath/wgArticlePath in mw.config).
	const owned = (mw.config.get("wgPageName") || "").split("/").length;

	// Map the current page's URL to the same page under another skin. The skin dir
	// is the only segment that differs: the main skin has none, others sit in a
	// "<skin>/" subdirectory directly under the shared base. That base is not
	// "everything before the file name" -- the page's own path may be several
	// segments deep -- so drop the segments the page owns to find where it ends.
	const targetUrl = (target) => {
		const segments = location.pathname.split("/");
		const page = segments.splice(Math.max(segments.length - owned, 0), owned);
		// What is left is "<base>" or "<base>/<skin>".
		if (current !== main && segments[segments.length - 1] === current) {
			segments.pop();
		}
		if (target !== main) {
			segments.push(target);
		}
		const path = segments.concat(page).join("/");
		return path + location.search + location.hash;
	};

	const init = () => {
		const selects = document.querySelectorAll(".wikven-skin-switcher select");
		for (const select of selects) {
			select.addEventListener("change", () => {
				if (select.value && select.value !== current) {
					location.assign(targetUrl(select.value));
				}
			});
		}
	};

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
