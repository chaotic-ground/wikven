/**
 * Offer the export's other skins in Citizen's preferences panel.
 *
 * Which skin to read in is a choice about how the page looks, so it belongs beside the theme, the
 * text size and the content width rather than behind "More actions". Citizen's panel is a public
 * extension point for exactly this (citizen.preferences.register / .changed, documented and stable
 * since 3.14.0, under the skin's deprecation policy).
 *
 * The options are read out of the toolbox entries wikven renders server-side (Hooks\Adder) instead
 * of being built here. Those carry hrefs the bake has already reparented for the page's own depth,
 * so this file needs no notion of where in the export it is running -- there is no URL arithmetic
 * to keep correct. They are removed once read, which also leaves a reader with no JavaScript the
 * plain list of links, still working, rather than nothing.
 */
(() => {
	const FEATURE = "wikven-skin";
	const CLASS_PREFIX = `${FEATURE}-clientpref-`;

	// Citizen stores a preference value under /^[a-zA-Z0-9]+$/ (clientPrefs.polyfill.js), which a
	// skin name such as "vector-2022" is not; the entry it came from carries the real target.
	const prefValue = (skin) => skin.replace(/[^a-zA-Z0-9]/g, "");

	// Citizen renders the page actions a second time in its sticky header, so ids repeat.
	const collect = () => {
		const seen = new Set();
		const skins = [];
		for (const item of document.querySelectorAll(
			'#p-tb .mw-list-item[id^="t-wikven-skin-"]',
		)) {
			const skin = item.id.replace("t-wikven-skin-", "");
			if (seen.has(skin)) {
				continue;
			}
			seen.add(skin);
			const link = item.querySelector("a");
			skins.push({
				value: prefValue(skin),
				label: item.textContent.trim(),
				href: link ? link.getAttribute("href") : null,
				current: item.classList.contains("active"),
			});
		}
		return skins;
	};

	// The panel reads its selected value off the <html> class, and the inline client-preferences
	// script restores whatever was stored on the page before this one. The skin being rendered is
	// the truth, and a second class of the same feature would read as ambiguous and be ignored.
	const markCurrent = (value) => {
		const classes = document.documentElement.classList;
		for (const name of Array.from(classes)) {
			if (name.startsWith(CLASS_PREFIX)) {
				classes.remove(name);
			}
		}
		classes.add(CLASS_PREFIX + value);
	};

	// What is left of the "More actions" card once the skin list moves is Citizen's own empty
	// p-cactions, so the card goes too -- unless something else on the site put an entry there.
	const removeToolbox = () => {
		for (const portlet of document.querySelectorAll("#p-tb")) {
			portlet.remove();
		}
		for (const dropdown of document.querySelectorAll(
			"#citizen-page-more-dropdown",
		)) {
			if (!dropdown.querySelector(".mw-list-item")) {
				dropdown.remove();
			}
		}
	};

	const start = () => {
		const skins = collect();
		const current = skins.find((skin) => skin.current);
		if (skins.length < 2 || !current) {
			return;
		}
		markCurrent(current.value);

		mw.hook("citizen.preferences.register").add((register) => {
			register({
				preferences: {
					[FEATURE]: {
						section: "appearance",
						type: "select",
						options: skins.map(({ value, label }) => ({ value, label })),
						label: mw.msg("wikven-skin"),
						description: mw.msg("wikven-skin-description"),
					},
				},
			});
		});

		mw.hook("citizen.preferences.changed").add((feature, value) => {
			if (feature !== FEATURE) {
				return;
			}
			const chosen = skins.find((skin) => skin.value === value);
			// The current skin's entry is text rather than a link, so it has nowhere to go.
			if (chosen?.href) {
				window.location.href = chosen.href;
			}
		});

		removeToolbox();
	};

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", start);
	} else {
		start();
	}
})();
