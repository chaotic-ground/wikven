// Citizen is supported like the bundled skins, so the guarantees the other skins are held to
// have to hold in it too: a search that works, no request to a backend that is not there, and
// chrome that is either functional or gone. It is also the one skin fetched from its upstream at
// build time, so these break on a Citizen release that moves what wikven leans on, which is the
// point of asserting them in a browser rather than trusting the markup.

const { test, expect } = require("@playwright/test");

// URLs that only a live MediaWiki answers; a static export must request none.
const BACKEND = /\/load\.php|\/api\.php|\/rest\.php\/|index\.php\?/;

const PAGE = "citizen/Installation.html";

// Where the text of a menu entry actually starts, which is what a reader sees lining up (or not).
const labelLeft = (page, selector) =>
	page.evaluate((sel) => {
		const span = document.querySelector(sel);
		const range = document.createRange();
		range.selectNodeContents(span);
		return range.getBoundingClientRect().left;
	}, selector);

test.beforeEach(async ({ request }) => {
	const response = await request.get(PAGE).catch(() => null);
	test.skip(!response?.ok(), "this export does not render Citizen");
});

test("Citizen's search box reaches the static results page", async ({
	page,
}) => {
	await page.goto(PAGE);

	// The skin's own search is a command palette with no backend here; what stays is the plain
	// form underneath it, retargeted at the results page.
	await page.locator(".citizen-search summary").click();
	await page.locator("#searchInput").fill("binary");
	await page.locator("#searchInput").press("Enter");

	await expect(page).toHaveURL(/Search\.html\?.*search=binary/);
	await expect(page.locator(".pagefind-ui__result").first()).toBeVisible({
		timeout: 15000,
	});
});

test("Citizen's search box suggests pages as you type", async ({ page }) => {
	await page.goto(PAGE);

	// The skin turns core's search wiring off for its own command palette, and wikven turns it
	// back on (Hooks\Main) now that the palette is gone: this is what that buys.
	await page.locator(".citizen-search summary").click();
	await page.locator("#searchInput").click();
	await page.keyboard.type("binary", { delay: 40 });

	// The row a reader sees, and where following it takes them. Core's widget builds every
	// suggestion's href out of the search form, which ext.sifter.retarget has aimed at the results
	// page, so left alone a suggestion arrived there carrying its own title as the query rather
	// than at the page it names (#381). SifterSearch rewrites the title rows to the page Pagefind
	// found, which is the hop Vector's typeahead never made. Asserted as a suffix, since the URL
	// carries the path prefix the site is served under.
	const suggestion = page
		.locator(".suggestions-results a", { hasText: "Standalone binary" })
		.first();
	await expect(suggestion).toBeVisible({ timeout: 15000 });
	await expect(suggestion).toHaveAttribute("href", /Standalone_binary\.html$/);

	// The panel is core's jquery.suggestions, whose own colours are hardcoded light; restated in
	// Citizen's custom properties, it has to follow the skin rather than stay white in the dark.
	const surface = await page.evaluate(() => {
		const results = document.querySelector(".suggestions-results");
		return getComputedStyle(results).backgroundColor;
	});
	expect(
		surface,
		"the suggestion panel keeps jquery.suggestions' own white",
	).not.toBe("rgb(255, 255, 255)");
});

test("Citizen's search shortcuts open the search the export has", async ({
	page,
}) => {
	// The skin binds "/" and Ctrl+K to its command palette in skin.js whether or not the trigger
	// they open survived the bake, so left alone they fetch a module the export does not ship. What
	// this asserts is both halves: the form opens, and neither module is asked for.
	//
	// Narrowed to those two rather than to any backend request, because focusing a text field also
	// has UniversalLanguageSelector fetch its input methods (#398), which is older than this and not
	// Citizen's. "a Citizen page gets everything it asks for" below still holds the wider line.
	const PALETTE = /skins\.citizen\.commandPalette|mediawiki\.notification/;
	const backend = [];
	page.on("request", (request) => {
		if (BACKEND.test(request.url()) && PALETTE.test(request.url())) {
			backend.push(request.url());
		}
	});

	await page.goto(PAGE);

	// Which listener the keystroke reaches first is up to mw.loader, so what keeps the request from
	// being made is the palette being marked failed rather than merely registered.
	expect(
		await page.evaluate(() =>
			mw.loader.getState("skins.citizen.commandPalette"),
		),
	).toBe("error");

	await page.keyboard.press("/");
	await expect(page.locator("#citizen-search-details")).toHaveJSProperty(
		"open",
		true,
	);
	await expect(page.locator("#searchInput")).toBeFocused();

	// Escape is the skin's own dismissal (dropdown.js), which opening the <details> rather than
	// unclipping the card by hand is what buys.
	await page.keyboard.press("Escape");
	await expect(page.locator("#citizen-search-details")).toHaveJSProperty(
		"open",
		false,
	);

	// The shortcuts are inert while focus is in a field, in the export as in the skin, so the
	// second one is only reachable once focus has left the search box.
	await page.locator("#firstHeading").click();
	await page.keyboard.press("ControlOrMeta+k");
	await expect(page.locator("#searchInput")).toBeFocused();

	expect(backend, backend.join("; ")).toEqual([]);
});

test("Citizen's preferences panel loads and switches the theme", async ({
	page,
}) => {
	await page.goto(PAGE);

	await page.locator(".citizen-preferences-dropdown summary").click();

	// The panel is lazy-loaded; unbundled it renders its own "Couldn't load preferences" instead.
	await expect(page.getByRole("radio", { name: "Dark" })).toBeVisible({
		timeout: 15000,
	});
	await expect(page.locator(".citizen-preferences-error")).toBeHidden();

	await page.getByRole("radio", { name: "Dark" }).click();
	await expect(page.locator("html")).toHaveClass(/skin-theme-clientpref-night/);

	// The choice is stored client-side, so it has to survive leaving the page.
	await page.goto("citizen/Deploying.html");
	await expect(page.locator("html")).toHaveClass(/skin-theme-clientpref-night/);
});

test("Citizen's last-modified button leads to what changed", async ({
	page,
}) => {
	await page.goto(PAGE);

	// The skin asks for the latest diff, which an export holding one revision of a page cannot
	// show; the repository can, and that is where the history link already goes. Left alone it
	// resolves to the page it is already on.
	const lastmod = page.locator("#citizen-lastmod-relative").first();
	await expect(lastmod).toBeVisible();
	const href = await lastmod.getAttribute("href");
	expect(href).not.toMatch(/Installation\.html$/);
	expect(href).toBe(
		await page.locator("#ca-history a").first().getAttribute("href"),
	);
});

test("a Citizen page gets everything it asks for, and asks no backend", async ({
	page,
}) => {
	const backend = [];
	const missing = [];
	const errors = [];
	page.on("request", (request) => {
		if (BACKEND.test(request.url())) {
			backend.push(request.url());
		}
	});
	// Citizen ships its own typeface and points @font-face at a path only a live MediaWiki serves,
	// so a page that renders fine can still be missing what it asked for.
	page.on("response", (response) => {
		if (response.status() >= 400) {
			missing.push(`${response.status()} ${response.url()}`);
		}
	});
	// The service worker Citizen registers is fetched by the browser rather than the page, so it
	// reaches neither handler above; its 404 surfaces here instead.
	page.on("pageerror", (error) => errors.push(error.message));

	// This asserts the absence of a request, so the wait is defined by the traffic itself.
	await page.goto(PAGE, { waitUntil: "networkidle" });

	expect(backend, backend.join("; ")).toEqual([]);
	expect(missing, missing.join("; ")).toEqual([]);
	expect(errors, errors.join("; ")).toEqual([]);
});

test("the skin chooser sits in the preferences panel and takes the reader there", async ({
	page,
}) => {
	// A translated subpage, two directories down, so the link it follows has had to be
	// reparented for the page's own depth. Its skin names are read from the main skin's copy,
	// which still renders them as links, rather than restated in whatever language it is in.
	await page.goto("Getting_Started/ko.html");
	const names = Object.fromEntries(
		await page.evaluate(() =>
			Array.from(document.querySelectorAll('[id^="t-wikven-skin-"]')).map(
				(element) => [
					element.id.replace("t-wikven-skin-", ""),
					element.textContent.trim(),
				],
			),
		),
	);

	await page.goto("citizen/Getting_Started/ko.html");

	// The panel is where a reader changes how the page looks, so the toolbox copy is gone --
	// and with it the "More actions" card, which held nothing else.
	await expect(page.locator("#p-tb")).toHaveCount(0);
	await expect(page.locator("#citizen-page-more-dropdown")).toHaveCount(0);

	await page.locator(".citizen-preferences-dropdown summary").click();
	const field = page.locator(".cdx-field").filter({ hasText: "Skin" });
	await field.waitFor({ timeout: 15000 });
	// The skin being read is the one shown as chosen, not whichever happens to be listed first.
	await expect(field.locator(".cdx-select-vue__handle")).toContainText(
		names.citizen,
	);

	await field.locator(".cdx-select-vue__handle").click();
	await field
		.locator(".cdx-menu-item")
		.filter({ hasText: names["vector-2022"] })
		.first()
		.click();

	await expect(page).toHaveURL("Getting_Started/ko.html");
	await expect(page.locator("#firstHeading")).toHaveText("시작하기");
});

test("without JavaScript the toolbox still lists the skins, lined up", async ({
	browser,
	baseURL,
}) => {
	// Nothing moves the list without JavaScript, which is why the chooser reads the toolbox
	// rather than replacing it: the server-rendered links stay the fallback.
	const context = await browser.newContext({
		javaScriptEnabled: false,
		baseURL,
	});
	const page = await context.newPage();
	await page.goto(PAGE);

	// Citizen clones the page-actions bar into its sticky header, so ids appear twice; the copy
	// in the page header is the one on screen at the top of the page.
	await page.locator("#citizen-page-more-dropdown summary").first().click();

	const current = "#p-tb .mw-list-item.active > span";
	const link = "#p-tb .mw-list-item:not(.active) a > span";
	await expect(page.locator(current).first()).toBeVisible();
	await expect(
		page.locator("#p-tb .mw-list-item:not(.active) a").first(),
	).toHaveAttribute("href", /Installation\.html$/);

	// Citizen hangs a menu item's box off the <a>, and the current skin is text rather than a
	// link, so without a box of its own its name sits flush against the card edge.
	expect(await labelLeft(page, current)).toBeCloseTo(
		await labelLeft(page, link),
		1,
	);
	await context.close();
});
