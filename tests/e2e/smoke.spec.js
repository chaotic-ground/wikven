// Browser smoke tests for the baked static site. These catch regressions that
// only surface once the page renders and its JS runs, which the static-HTML
// assertions in the workflow cannot see (e.g. a search widget mounting on every
// page, or the export quietly fetching from a server that is not there).

const { test, expect } = require("@playwright/test");

// URLs that only a live MediaWiki answers; a static export must request none.
const BACKEND = /\/load\.php|\/api\.php|\/rest\.php\/|index\.php\?/;

test("a content page shows one search box and no results widget", async ({
	page,
}) => {
	const errors = [];
	page.on("pageerror", (error) => errors.push(error.message));

	await page.goto("Installation.html");

	// The native search box is present, but the full Pagefind results widget is
	// not: it must mount only on the results page, not on every page.
	await expect(page.locator("#searchInput").first()).toBeVisible();
	await expect(page.locator(".pagefind-ui__form")).toHaveCount(0);

	expect(errors, errors.join("; ")).toEqual([]);
});

test("a content page fetches nothing from a live backend", async ({ page }) => {
	const backend = [];
	page.on("request", (request) => {
		if (BACKEND.test(request.url())) {
			backend.push(request.url());
		}
	});

	await page.goto("Installation.html", { waitUntil: "load" });
	await page.waitForTimeout(1000);

	expect(backend, backend.join("; ")).toEqual([]);
});

test("the results page mounts the widget and returns results", async ({
	page,
}) => {
	await page.goto("Search.html?search=wikven");

	await expect(page.locator(".pagefind-ui__form")).toHaveCount(1);
	await expect(page.locator(".pagefind-ui__result").first()).toBeVisible({
		timeout: 15000,
	});
});

test("the search box suggests pages as you type", async ({ page }) => {
	await page.goto("index.html");

	// Focusing mounts the typeahead app; characters typed while it mounts carry
	// over into the mounted input, so no explicit wait is needed.
	await page.locator("#searchInput").click();
	await page.keyboard.type("binary", { delay: 40 });

	// A title suggestion, plus the "containing..." row aimed at the results page.
	await expect(
		page.locator('.cdx-menu-item a[href*="Standalone_binary"]').first(),
	).toBeVisible({ timeout: 15000 });
	await expect(
		page.locator('.cdx-menu-item a[href*="Search.html?search="]').first(),
	).toBeVisible();
});
