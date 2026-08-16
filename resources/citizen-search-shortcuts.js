/**
 * Give Citizen's search shortcuts something the export actually has to open.
 *
 * The skin's own search is a command palette backed by the REST API, so the bake leaves it out:
 * maintenance/rewriteScripts.php drops the trigger's id and commandPalette.js returns early without
 * it, which is what leaves the plain form underneath standing for SifterSearch to retarget. What
 * the bake cannot drop is the shortcuts. skin.js calls search.init() on the line after
 * commandPalette.init() has bailed, unconditionally, so "/", Ctrl+K and the access key still reach
 * for a palette that is not there, and fetch two modules the export does not ship.
 *
 * Two separate things are wrong, and they are fixed separately.
 *
 * The 404s are closed in the loader rather than by racing the skin for the keystroke. Listener
 * order cannot be relied on here: mw.loader executes modules from a requestIdleCallback that fires
 * after DOMContentLoaded, so the skin registers its window listener when its own module runs, and
 * whether that is before or after this one is not ours to decide. Marking the modules failed
 * instead makes the outcome the same either way -- mw.loader.using() rejects out of enqueue()
 * before it builds a request, so the skin's trigger runs its state machine and settles silently.
 *
 * The dead keystroke is fixed by binding the same shortcuts and opening the form. The chord tests
 * mirror skins.citizen.scripts/keyboardLayout.js, which is module-private and cannot be required
 * from here. Mirroring rather than simplifying is deliberate: this must claim exactly what the skin
 * would have claimed, so no key is swallowed that the skin would have left to the browser.
 */
(() => {
	// Per-skin bakes mean this only ships with Citizen, but the bundle self-executes every module it
	// holds, so say what it is for rather than leaning on that.
	if (mw.config.get("skin") !== "citizen") {
		return;
	}

	const DETAILS_ID = "citizen-search-details";
	const TRIGGER_ID = "citizen-search-summary";
	const INPUT_ID = "searchInput";

	// What a palette trigger asks for at run time: the palette, and the toast mw.notify lazy-loads
	// to report that the palette did not come. Neither is queued by any page, so neither is in the
	// bundle, and both are a 404 on a site with no load.php behind it.
	const UNSHIPPED = ["skins.citizen.commandPalette", "mediawiki.notification"];

	// modules-static.js has run by now -- it is a plain <script> in <head>, ahead of the load() that
	// schedules this -- so everything the bundle carries is at least "loaded". Anything still merely
	// registered came from the startup registry and has no code behind it here.
	for (const name of UNSHIPPED) {
		if (mw.loader.getState(name) === "registered") {
			mw.loader.state({ [name]: "error" });
		}
	}

	// Macs compose characters with Option where other platforms use AltGr, and use Control+Option
	// where they use Alt+Shift. userAgentData is Chromium-only; platform is deprecated but universal.
	const isMac = /mac|iphone|ipad/i.test(
		navigator.userAgentData?.platform || navigator.platform || "",
	);

	// Match the letter typed rather than the key position, falling back to position where no Latin
	// letter is produced at all: Cyrillic and Greek keycaps carry a Latin legend that follows the US
	// positions, and macOS rewrites every Option chord into a glyph. ASCII-only, because full
	// Unicode case folding maps U+017F and U+212A onto "s" and "k".
	const isLetterPressed = (event, letter) =>
		/^[a-z]$/i.test(event.key)
			? event.key.toLowerCase() === letter
			: event.code === `Key${letter.toUpperCase()}`;

	// Whether the event is a character composed with AltGr rather than a chord. Windows delivers
	// AltGr as Ctrl+Alt and a Mac composes the same characters with Option alone, neither of which
	// the modifier flags tell apart from a chord; the single-character test is what keeps real
	// chords (Ctrl+Alt+ArrowDown) out.
	const isAltGraphChar = (event) => {
		if (event.metaKey || !event.altKey || (event.key || "").length !== 1) {
			return false;
		}
		if (event.key === " ") {
			return false;
		}
		return (
			event.getModifierState?.("AltGraph") === true || event.ctrlKey || isMac
		);
	};

	// mediawiki.util resolves the access-key chord per platform. These are the two Citizen claims;
	// it leaves the bare Alt and bare Ctrl fallbacks alone, because Alt+F is the Windows File menu
	// and Ctrl+F is find-in-page.
	const isAccessKeyChord = (event) => {
		if (event.metaKey) {
			return false;
		}
		if (event.altKey && event.shiftKey && !event.ctrlKey) {
			return true;
		}
		return isMac && event.ctrlKey && event.altKey && !event.shiftKey;
	};

	const OPEN_SHORTCUTS = [
		// "/". A bare Ctrl means a chord, but AltGr types "/" on some layouts.
		(event) =>
			event.key === "/" &&
			!event.metaKey &&
			(!event.ctrlKey || isAltGraphChar(event)),
		// Ctrl+K, or Command+K on a Mac. A further modifier makes it someone else's chord.
		(event) =>
			(event.ctrlKey || event.metaKey) &&
			!event.altKey &&
			!event.shiftKey &&
			isLetterPressed(event, "k"),
		// "F" is the access key MediaWiki assigns to search.
		(event) => isAccessKeyChord(event) && isLetterPressed(event, "f"),
	];

	// Typing "/" into a search field must reach the field, not reopen it. The skin gates its own
	// shortcuts the same way, so a field keeps both of us out.
	const isFormField = (element) => {
		if (!(element instanceof HTMLElement)) {
			return false;
		}
		const name = element.nodeName.toLowerCase();
		const type = (element.getAttribute("type") || "").toLowerCase();
		return (
			name === "select" ||
			name === "textarea" ||
			(name === "input" &&
				type !== "submit" &&
				type !== "reset" &&
				type !== "checkbox" &&
				type !== "radio") ||
			element.isContentEditable
		);
	};

	// The card is revealed by the [open] attribute on the <details> (Search.less), and Citizen's own
	// dropdown.js is listening for that toggle: opening it this way is what hands Escape and
	// click-away dismissal back to the skin. Nothing to open where the export has no search at all,
	// and the shortcut is still swallowed, because doing nothing is the honest answer there.
	const openSearch = () => {
		const details = document.getElementById(DETAILS_ID);
		const input = document.getElementById(INPUT_ID);
		if (!details || !input) {
			return;
		}
		details.open = true;
		input.focus();
	};

	window.addEventListener(
		"keydown",
		(event) => {
			// Only where the palette has no trigger to open. With one present the skin is running its
			// own search -- a wiki with a server behind it -- and this has no business in the way.
			if (document.getElementById(TRIGGER_ID)) {
				return;
			}
			if (
				isFormField(event.target) ||
				!OPEN_SHORTCUTS.some((matches) => matches(event))
			) {
				return;
			}
			// Firefox quickfind would otherwise take "/". Stopping the rest of the listeners on this
			// target spares the skin's own a pointless round through a palette that cannot load; it is
			// not what keeps the request from being made, and does nothing when the skin ran first.
			event.preventDefault();
			event.stopImmediatePropagation();
			openSearch();
		},
		true,
	);
})();
