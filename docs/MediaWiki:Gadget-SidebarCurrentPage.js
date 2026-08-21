/**
 * Mark the sidebar entry for the page the reader is already on, and stop it
 * being a link.
 *
 * MediaWiki does not do this itself, and Special:MyLanguage is not the reason:
 * Skin::createSidebarItem() builds every sidebar item with "active" => false,
 * with nothing to set it otherwise, so no entry is ever the current one. The
 * tabs and the personal tools do compare the item's URL with the page's --
 * 'active' => ( $href == $pageurl ) -- and the sidebar alone skips that. An
 * entry written as a plain [[Page]] would go unmarked the same way.
 *
 * The href is dropped rather than the <a> swapped for a <span>, which is what
 * the skin list does where it marks the skin the page is already rendered in.
 * An <a> without an href is already not a link -- not clickable, not focusable,
 * and outside a:link, so it loses the link colour on its own -- and leaving the
 * element as it is keeps whatever each skin styles it with. Minerva lays its
 * rows out from a rule on the anchor, which a <span> would fall out of.
 *
 * aria-current="page" is what says "this one" to a reader who is not looking at
 * it. MediaWiki:Common.css marks it for one who is.
 *
 * ResourceLoader parses a wiki-page script as ES2016 before it serves it, and
 * replaces a module it cannot parse with a console error -- the script then
 * never runs, and nothing else reports it. Keep to that vintage: no optional
 * catch binding, no optional chaining, no nullish coalescing.
 */
(() => {
	// The documentation groups alone. Every group in MediaWiki:Sidebar is named
	// sidebar-<something>, and the name becomes the portlet's class, so a group
	// added later is covered without touching this. wikven-nav-item is the same
	// set in Minerva, whose menu fillMinervaMenu.php writes from the sidebar.
	//
	// Deliberately not every link on the page: the tabs above the article point
	// at the page you are on because that is what they are for -- "Read" is one
	// -- and a fragment link goes somewhere even on its own page.
	const SELECTOR =
		'[class*="mw-portlet-sidebar-"] a[href], .wikven-nav-item a[href]';

	// Compared as paths, not as written. The sidebar's links are relative and
	// their depth differs by where the page sits, and a translated page reaches
	// its own entry through Special:MyLanguage, which the build resolves to the
	// translation's own file. Both sides come out of the same URL parser, so a
	// percent-escaped title matches a percent-escaped title.
	const pathOf = (url) => {
		try {
			return new URL(url, location.href).pathname;
		} catch (_e) {
			// Not a URL this browser will read; leave the link alone.
			return null;
		}
	};

	const markCurrent = () => {
		const here = location.pathname;
		for (const link of document.querySelectorAll(SELECTOR)) {
			if (link.hash || pathOf(link.href) !== here) {
				continue;
			}
			link.removeAttribute("href");
			link.setAttribute("aria-current", "page");
		}
	};

	// The sidebar is chrome, not content: it is in the document from the start
	// and nothing re-renders it, so this runs once. Vector moves the menu
	// between its pinned and unpinned containers, which carries the same
	// elements and so the same attributes with it.
	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", markCurrent);
	} else {
		markCurrent();
	}
})();
