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

	// This asserts the absence of a request, so the wait is defined by the traffic itself -- exactly
	// the property under test here.
	await page.goto("Installation.html", { waitUntil: "networkidle" });

	// A page can be quiet on load and still reach for a backend on the first thing a reader does.
	// ULS binds its input methods to text fields and fetches them on first focus, so the click is
	// part of the claim rather than a separate one.
	await page.locator("#searchInput").first().click();
	await page.waitForLoadState("networkidle");

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

	// Focusing the raw search box mounts the Codex typeahead and focuses its input; wait for a
	// mounted input (there are two on the page) before typing so no keystrokes are dropped mid-mount.
	await page.locator("#searchInput").click();
	await page
		.locator(".cdx-text-input__input")
		.first()
		.waitFor({ state: "visible", timeout: 15000 });
	await page.keyboard.type("binary", { delay: 40 });

	// A title suggestion, plus the "containing..." row aimed at the results page.
	await expect(
		page.locator('.cdx-menu-item a[href*="Standalone_binary"]').first(),
	).toBeVisible({ timeout: 15000 });
	await expect(
		page.locator('.cdx-menu-item a[href*="Search.html?search="]').first(),
	).toBeVisible();
});

// The footer badges are the one place a built site says what built it, and both of them name a
// file the install serves rather than one the export holds. storeImages copies each into the asset
// directory and points the page at the copy; what proves it worked is the picture having pixels.
//
// A <picture> is what makes this worth a browser: the wide badge is named in a <source srcset>,
// which the viewport (Desktop Chrome, 1280px) picks over the compact <img src>, so currentSrc is
// the srcset reference and nothing else reads it (#775).
for (const badge of ["Built with wikven", "Powered by MediaWiki"]) {
	test(`the footer badge "${badge}" is a picture the export holds`, async ({
		page,
	}) => {
		await page.goto("Installation.html");

		const image = page.locator(`img[alt="${badge}"]`);
		await expect(image).toHaveCount(1);
		// Footer icons are loading="lazy", so one below the fold is never fetched until it is looked
		// at: without this the assertion below would read a picture nobody asked the browser for.
		await image.scrollIntoViewIfNeeded();

		// Drawn rather than broken: a 404 leaves an <img> with no intrinsic width at all. Polled
		// because the fetch starts with the scroll above and finishes whenever it finishes.
		await expect
			.poll(() => image.evaluate((node) => node.naturalWidth), {
				message: `${badge} never loaded`,
			})
			.toBeGreaterThan(0);

		// And what it drew is a file beside the page, not a path back into the MediaWiki install,
		// which an export does not contain.
		const currentSrc = await image.evaluate((node) => node.currentSrc);
		expect(currentSrc, currentSrc).not.toMatch(
			/\/(?:resources|extensions|skins)\//,
		);
	});
}
