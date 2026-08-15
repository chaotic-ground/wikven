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

test("the current skin lines up with the other entries in the toolbox", async ({
	page,
}) => {
	await page.goto(PAGE);
	// Citizen clones the page-actions bar into its sticky header, so ids appear twice; the copy
	// in the page header is the one on screen at the top of the page.
	await page.locator("#citizen-page-more-dropdown summary").first().click();

	const current = "#p-tb .mw-list-item.active > span";
	const link = "#p-tb .mw-list-item:not(.active) a > span";
	await expect(page.locator(current).first()).toBeVisible();

	// Citizen hangs a menu item's box off the <a>, and the current skin is text rather than a
	// link, so without a box of its own its name sits flush against the card edge.
	expect(await labelLeft(page, current)).toBeCloseTo(
		await labelLeft(page, link),
		1,
	);
});
