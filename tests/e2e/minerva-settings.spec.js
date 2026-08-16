// Minerva sends its readers to Special:MobileOptions for how the site looks, and a bake writes no
// special page. The export puts a generated page there instead and lets MobileFrontend's own script
// draw the controls into it, so what these check is that the borrowed script found what it needs:
// the modules bundled, the form to lay them out in, and the classes on <html> its choices move.

const { test, expect } = require("@playwright/test");

// URLs that only a live MediaWiki answers; a static export must request none.
const BACKEND = /\/load\.php|\/api\.php|\/rest\.php\/|index\.php\?/;

const clientPrefClasses = (page) =>
	page.evaluate(() =>
		[...document.documentElement.classList].filter((name) =>
			name.includes("-clientpref-"),
		),
	);

const paragraphSize = (page) =>
	page
		.locator(".content p")
		.first()
		.evaluate((element) => getComputedStyle(element).fontSize);

test("MobileFrontend's own settings controls are drawn, with no backend to draw them", async ({
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

	await page.goto("minerva/Settings.html", { waitUntil: "networkidle" });

	// Three text sizes and three colour themes, each as its own radio, the way the special page
	// draws them. The script renders nothing at all if a module it waits for went unbundled.
	await expect(
		page.locator("#skin-client-prefs-mf-font-size input[type=radio]"),
	).toHaveCount(3);
	await expect(
		page.locator("#skin-client-prefs-skin-theme input[type=radio]"),
	).toHaveCount(3);

	expect(backend, backend.join("; ")).toEqual([]);
	expect(errors, errors.join("; ")).toEqual([]);
});

// The stylesheet those layout rules live in names a form, and wikitext cannot carry one.
test("the controls are laid out in a form, not stacked inline", async ({
	page,
}) => {
	await page.goto("minerva/Settings.html");
	await expect(
		page.locator("form#mobile-options.mw-mf-settings"),
	).toBeAttached();

	// A control the reader reads as one row: label above, radios below, no bullet beside them.
	const marker = await page
		.locator(
			"#skin-client-prefs-mf-font-size ul, #skin-client-prefs-mf-font-size ol",
		)
		.first()
		.evaluate((element) => getComputedStyle(element).listStyleType);
	expect(marker).toBe("none");
});

// The borrowed controls are inset from the page's own text, so wikven's section beneath them has
// to be inset the same or the page reads as two columns.
test("wikven's own section lines up with MobileFrontend's", async ({
	page,
}) => {
	await page.goto("minerva/Settings.html");

	// The borrowed block is drawn by its script, so it has to arrive before anything is measured.
	await expect(page.locator("#skin-client-prefs-skin-theme")).toBeVisible();

	// Read in the one frame, so nothing the page is still settling into can come between them.
	const offsets = await page.evaluate(() => {
		const left = (selector) =>
			document.querySelector(selector).getBoundingClientRect().left;
		return {
			section: left(".wikven-setting") - left("#mf-client-preferences"),
			list: left(".wikven-skin-list") - left("#skin-client-prefs-skin-theme"),
		};
	});

	expect(offsets).toEqual({ section: 0, list: 0 });
});

// A setting that only holds on the page it was set on is no setting, and the pages a reader goes on
// to read are baked with the same class the choice moves, so this reads one of them.
test("a bigger text size holds on the pages read next", async ({ page }) => {
	await page.goto("minerva/Settings.html");
	const before = await page
		.goto("minerva/Installation.html")
		.then(() => paragraphSize(page));

	await page.goto("minerva/Settings.html");
	await page
		.locator("#skin-client-prefs-mf-font-size input[value=large]")
		.click();
	await expect
		.poll(() => clientPrefClasses(page))
		.toContain("mf-font-size-clientpref-large");

	await page.goto("minerva/Installation.html");
	await expect
		.poll(() => clientPrefClasses(page))
		.toContain("mf-font-size-clientpref-large");
	expect(parseFloat(await paragraphSize(page))).toBeGreaterThan(
		parseFloat(before),
	);
});

test("picking dark repaints the pages read next", async ({ page }) => {
	await page.goto("minerva/Settings.html");
	await page
		.locator("#skin-client-prefs-skin-theme input[value=night]")
		.click();
	await expect
		.poll(() => clientPrefClasses(page))
		.toContain("skin-theme-clientpref-night");

	await page.goto("minerva/Installation.html");
	await expect
		.poll(() => clientPrefClasses(page))
		.toContain("skin-theme-clientpref-night");
	await expect
		.poll(() =>
			page.evaluate(() => getComputedStyle(document.body).backgroundColor),
		)
		.not.toBe("rgb(255, 255, 255)");
});

// Minerva's toolbox is its page actions, so the switcher every other skin carries on every page
// is here instead, and this is the only route out of Minerva a reader has.
test("the settings page carries the switcher, and it lands on the other skin", async ({
	page,
}) => {
	await page.goto("minerva/Settings.html");

	const entries = page.locator(".wikven-skin-item");
	await expect(entries).not.toHaveCount(0);
	// The skin being read names itself without linking to itself.
	await expect(page.locator("#t-wikven-skin-minerva a")).toHaveCount(0);

	const other = page.locator(".wikven-skin-item a").first();
	const href = await other.getAttribute("href");
	const navigation = page.waitForResponse((response) =>
		response.request().isNavigationRequest(),
	);
	await other.click();
	expect((await navigation).status()).toBe(200);
	await expect(page.locator("#firstHeading")).toBeVisible();
	expect(href).toContain("Settings.html");
});
