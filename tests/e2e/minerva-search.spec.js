// Minerva's search, which reached a backend that is not there until SifterSearch learned the
// skin's name. MinervaNeue names its own search module (skins.minerva.search), and the
// SkinPageReadyConfig handler that swaps a skin's typeahead for Pagefind knew only core's legacy
// widget and Vector's, so Minerva kept its own: every keystroke asked /rest.php/v1/search/title
// and every one of those 404d on a static export (#381). ext.sifter.minerva mounts Minerva's own
// search app on Pagefind instead, the way ext.sifter.vector already did for Vector 2022.
//
// The skin is the one place none of this was watched, which is how the REST calls went unnoticed
// while Citizen's box was being fixed, so what follows is a reader's path through it rather than
// the markup: the box, what it suggests, and where a suggestion leads.

const { test, expect } = require("@playwright/test");

const PAGE = "minerva/Installation.html";

test.beforeEach(async ({ request }) => {
	const response = await request.get(PAGE).catch(() => null);
	test.skip(!response?.ok(), "this export does not render Minerva");
});

test("Minerva's search suggests pages without asking a REST backend", async ({
	page,
}) => {
	// Narrowed to rest.php rather than to any backend URL: focusing a text field also has
	// UniversalLanguageSelector fetch its input methods (#398), which is older than this and not
	// Minerva's. This is the request #381 reported, and the one the adapter exists to prevent.
	const rest = [];
	page.on("request", (request) => {
		if (/\/rest\.php\//.test(request.url())) {
			rest.push(request.url());
		}
	});

	await page.goto(PAGE);

	// The skin renders a plain input in its header and replaces it with the Codex app once the
	// search module loads on focus, so the element typed into is not the one clicked. Waiting for
	// the mounted input is what keeps keystrokes from being dropped mid-swap.
	await page.locator("#searchInput").first().click();
	await page
		.locator(".cdx-text-input__input")
		.first()
		.waitFor({ state: "visible", timeout: 15000 });
	await page.keyboard.type("binary", { delay: 40 });

	// A title suggestion leading to the page it names, not to the results page carrying the title
	// as a query. Matched as a suffix, since the URL carries the path prefix the site is served
	// under.
	await expect(
		page.locator('.cdx-menu-item a[href$="Standalone_binary.html"]').first(),
	).toBeVisible({ timeout: 15000 });

	expect(rest, rest.join("; ")).toEqual([]);
});

test("Minerva's search offers the results page and reaches it", async ({
	page,
}) => {
	await page.goto(PAGE);

	await page.locator("#searchInput").first().click();
	await page
		.locator(".cdx-text-input__input")
		.first()
		.waitFor({ state: "visible", timeout: 15000 });
	await page.keyboard.type("binary", { delay: 40 });

	// The "search for pages containing" footer, which the app renders from the URL generator
	// SifterSearch hands it: with no wiki search to answer one, a full-text search is the
	// configured results page or nothing at all.
	const containing = page
		.locator('.cdx-menu-item a[href*="Search.html?search="]')
		.first();
	await expect(containing).toBeVisible({ timeout: 15000 });

	await containing.click();
	await expect(page.locator(".pagefind-ui__result").first()).toBeVisible({
		timeout: 15000,
	});
});
