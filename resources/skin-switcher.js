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

	// Map the current page's URL to the same page under another skin. The skin
	// dir is the only segment that differs: the main skin has none, others sit in
	// a "<skin>/" subdirectory right under the shared base.
	const targetUrl = (target) => {
		const path = location.pathname;
		const slash = path.lastIndexOf("/");
		const file = path.slice(slash + 1);
		let base = path.slice(0, slash + 1);
		if (current !== main && base.endsWith(`/${current}/`)) {
			base = base.slice(0, base.length - current.length - 1);
		}
		const prefix = target === main ? "" : `${target}/`;
		return base + prefix + file + location.search + location.hash;
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
