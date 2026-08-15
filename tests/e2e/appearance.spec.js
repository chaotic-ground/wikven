// Vector's appearance menu is the one piece of skin chrome a static export can still drive itself:
// for the anonymous reader every option is a client preference, so Vector writes a cookie and swaps
// an <html> class rather than calling the options API. The menu is empty in the served HTML and
// filled by skins.vector.clientPreferences, so only a rendered page shows whether it arrived.

const { test, expect } = require("@playwright/test");

// URLs that only a live MediaWiki answers; a static export must request none.
const BACKEND = /\/load\.php|\/api\.php|\/rest\.php\/|index\.php\?/;

const clientPrefClasses = (page) =>
	page.evaluate(() =>
		[...document.documentElement.classList].filter((name) =>
			name.includes("-clientpref-"),
		),
	);

test("the appearance menu is filled in, and asks no backend to do it", async ({
	page,
}) => {
	const errors = [];
	const backend = [];
	page.on("pageerror", (error) => errors.push(error.message));
	page.on("request", (request) => {
		if (BACKEND.test(request.url())) {
			backend.push(request.url());
		}
	});

	await page.goto("index.html", { waitUntil: "networkidle" });

	// One radio per option of the three preferences Vector offers: text size, width, colour.
	const radios = page.locator("#vector-appearance input[type=radio]");
	await expect(radios).toHaveCount(8);
	await expect(
		page.locator("#skin-client-pref-skin-theme-value-night"),
	).toBeAttached();

	expect(backend, backend.join("; ")).toEqual([]);
	expect(errors, errors.join("; ")).toEqual([]);
});

test("picking dark repaints the page and outlives a reload", async ({
	page,
}) => {
	await page.goto("index.html");
	await page.locator("#skin-client-pref-skin-theme-value-night").click();

	await expect
		.poll(() => clientPrefClasses(page))
		.toContain("skin-theme-clientpref-night");
	const dark = await page.evaluate(
		() => getComputedStyle(document.body).backgroundColor,
	);
	expect(dark).not.toBe("rgb(255, 255, 255)");

	// The inline <head> script restores the choice from the cookie, before any of the bundle runs.
	await page.reload();
	await expect
		.poll(() => clientPrefClasses(page))
		.toContain("skin-theme-clientpref-night");
	await expect
		.poll(() =>
			page.evaluate(() => getComputedStyle(document.body).backgroundColor),
		)
		.toBe(dark);
});

test("width and text size are applied as chosen", async ({ page }) => {
	await page.goto("index.html");

	await page
		.locator("#skin-client-pref-vector-feature-limited-width-value-0")
		.click();
	await expect
		.poll(() => clientPrefClasses(page))
		.toContain("vector-feature-limited-width-clientpref-0");

	await page
		.locator("#skin-client-pref-vector-feature-custom-font-size-value-2")
		.click();
	await expect
		.poll(() => clientPrefClasses(page))
		.toContain("vector-feature-custom-font-size-clientpref-2");
});

test("hiding the menu leaves it reachable, and it can be put back", async ({
	page,
}) => {
	await page.goto("index.html");
	await page
		.locator("#vector-appearance .vector-pinnable-header-unpin-button")
		.click();

	// Unpinning is itself a client preference, so the reader arrives at the next page with the
	// menu already moved into the header dropdown -- the state this has to keep working in.
	await page.reload();

	// Vector lays a transparent checkbox over the dropdown's own label, so the click goes there.
	await page.locator("#vector-appearance-dropdown-checkbox").click();
	const menu = page.locator(
		"#vector-appearance-unpinned-container #vector-appearance",
	);
	await expect(menu).toBeVisible();
	await expect(menu.locator("input[type=radio]")).toHaveCount(8);

	await page
		.locator("#vector-appearance .vector-pinnable-header-pin-button")
		.click();
	await expect(
		page.locator("#vector-appearance-pinned-container #vector-appearance"),
	).toBeVisible();
});

test("the icons the JS bundle carries render", async ({ page }) => {
	await page.goto("index.html");

	// The bundle injects its own CSS, so its images are inlined as data: URIs rather than written
	// out as files. A URI that lands in the unquoted url() malformed leaves a valid-looking button
	// with a blank icon, which no layout or visibility assertion would catch.
	const mask = await page
		.locator("#vector-appearance-dropdown-label .vector-icon")
		.evaluate((element) => getComputedStyle(element).maskImage);
	expect(mask).toContain("data:image/svg+xml");
});
