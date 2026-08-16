/**
 * Offer the export's other skins in Vector's appearance menu.
 *
 * Which skin to read in is a choice about how the page looks, so it belongs beside the theme, the
 * text size and the content width rather than under "Tools" among the page actions. Vector lands it
 * in Tools as a direct consequence of how the list is rendered: Hooks\Adder gives it a sidebar
 * section of its own (OWN_SECTION_SKINS) and SkinVector22::extractPageToolsFromSidebar() splices
 * every section from the toolbox onward into the page-tools menu.
 *
 * Vector offers no server-side route to the appearance menu. The menu is empty in the served HTML
 * -- VectorComponentAppearance renders a pinnable element, a container and a header and nothing
 * else -- and its contents are built on the client out of skins.vector.js/clientPreferences.json.
 * That framework stores an enum as a class on <html>, which a link into another directory is not,
 * and there is no hook to add an entry with. So the section the bake already rendered is moved,
 * whole, into the menu.
 *
 * Moving rather than rebuilding is what leaves a reader with no JavaScript the plain list of links
 * where the bake wrote it, and it keeps the entries' hrefs, which the bake has already reparented
 * for the page's own depth -- so this file needs no notion of where in the export it is running,
 * and there is no URL arithmetic to keep correct. It also keeps the t-wikven-skin-* ids in the
 * document, which is where the generated settings page reads its own copy of the list from
 * (appearance.js).
 *
 * The menu is a pinnable element: "hide" moves #vector-appearance itself between
 * vector-appearance-pinned-container and vector-appearance-unpinned-container. Putting the section
 * inside the menu, rather than beside it in whichever container holds it, is what takes the list
 * along when the reader moves it.
 */
(() => {
	// The menu, by the id Vector gives the pinnable element itself; and the rendered section, by the
	// portlet class core builds from Adder's section name.
	const MENU = "vector-appearance";
	const SECTION = ".mw-portlet-wikven-skins";

	// Set on <html> once the move is done, so the stylesheet can hide what is left of the page-tools
	// box. It cannot be hidden unconditionally: without JavaScript the list is still in that box,
	// and hiding it would take the only switcher the export has with it.
	const MOVED_CLASS = "wikven-vector-skins-moved";

	// Vector fills the menu on the client, and how it fills it is its own business. Appending once
	// it has drawn leaves the list after the preferences and out of the way of whatever the module
	// does to the menu on its way in. If it never draws -- an export whose bundle left the module
	// out -- the list is moved anyway once this expires: a menu holding only the skins still reads
	// better than a skin list under "Tools".
	const DRAWN_TIMEOUT = 5000;

	const menu = () => document.getElementById(MENU);

	// The preferences are radios, so one of them is the menu having been filled in.
	const drawn = () => Boolean(menu()?.querySelector('input[type="radio"]'));

	const move = () => {
		const target = menu();
		const section = document.querySelector(SECTION);
		if (!target || !section) {
			return;
		}
		target.append(section);
		document.documentElement.classList.add(MOVED_CLASS);
	};

	const start = () => {
		// A single-skin export renders no section, and a Vector that no longer draws the menu leaves
		// nothing to move it into; both keep the list where the bake put it.
		if (!menu() || !document.querySelector(SECTION)) {
			return;
		}
		if (drawn()) {
			move();
			return;
		}

		const observer = new MutationObserver(() => {
			if (!drawn()) {
				return;
			}
			observer.disconnect();
			clearTimeout(timer);
			move();
		});
		observer.observe(menu(), { childList: true, subtree: true });
		const timer = setTimeout(() => {
			observer.disconnect();
			move();
		}, DRAWN_TIMEOUT);
	};

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", start);
	} else {
		start();
	}
})();
