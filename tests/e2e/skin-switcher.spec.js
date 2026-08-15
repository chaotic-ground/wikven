// The footer skin switcher rewrites the current URL to the same page under
// another skin's copy of the export. Both halves of that URL vary -- the base
// path the site is served from, and how many directories deep the page sits --
// and a translated page ("Getting Started/ko" exports to Getting_Started/ko.html)
// is deeper than its source. Getting that wrong sends the reader to a path the
// export does not contain, which no static-HTML assertion can see: the pages
// themselves are all present and correct. These tests drive the switcher in a
// browser, where the resulting 404 is visible.

const { test, expect } = require("@playwright/test");

// The article the sequence runs on, in each language.
const ENGLISH_HEADING = "Getting Started";
const KOREAN_HEADING = "시작하기";

// One per page, rendered by the SkinAddFooterLinks hook with the current skin
// preselected, so its value also names the skin the page was rendered for.
const skinSelect = (page) => page.locator(".wikven-skin-switcher select");

// Record every page the browser is sent to that the export does not have, so a
// switch to a nonexistent path fails as the 404 it is.
const watchForMissingPages = (page) => {
	const missing = [];
	page.on("response", (response) => {
		if (
			response.request().resourceType() === "document" &&
			response.status() >= 400
		) {
			missing.push(`${response.status()} ${response.url()}`);
		}
	});
	return missing;
};

// Choose a skin and wait out the navigation it triggers, returning the response
// so a test can assert the page was actually served. The <select> is in the HTML
// whether or not its module ever runs, so wait for the module rather than race
// it: a change event nobody is listening for would just look like a hang.
const chooseSkin = async (page, skin) => {
	await page.waitForFunction(
		() =>
			window.mw &&
			window.mw.loader.getState("ext.Wikven.skinSwitcher") === "ready",
	);
	const navigation = page.waitForResponse((response) =>
		response.request().isNavigationRequest(),
	);
	await skinSelect(page).selectOption(skin);
	const response = await navigation;
	await page.waitForLoadState("domcontentloaded");
	return response;
};

const expectKoreanArticle = async (page) => {
	await expect(page.locator("#firstHeading")).toHaveText(KOREAN_HEADING);
	await expect(page.locator("html")).toHaveAttribute("lang", "ko");
};

// The reported order: language first, then skin. (Skin first, then language,
// always worked -- the language links are relative, so they keep the skin.)
test("switching the skin after switching to Korean keeps the article", async ({
	page,
}) => {
	const missing = watchForMissingPages(page);

	await page.goto("Getting_Started.html");
	await expect(page.locator("#firstHeading")).toHaveText(ENGLISH_HEADING);

	await page.locator('.mw-pt-languages-list a[lang="ko"]').click();
	await expect(page).toHaveURL("Getting_Started/ko.html");
	await expectKoreanArticle(page);

	const response = await chooseSkin(page, "citizen");

	expect(response.status()).toBe(200);
	await expect(page).toHaveURL("citizen/Getting_Started/ko.html");
	await expectKoreanArticle(page);
	await expect(skinSelect(page)).toHaveValue("citizen");
	expect(missing, missing.join("; ")).toEqual([]);
});

// The other direction: back to the main skin, which lives at the export root
// rather than in a directory of its own.
test("switching back to the main skin from a Korean page returns to the root copy", async ({
	page,
}) => {
	const missing = watchForMissingPages(page);

	await page.goto("citizen/Getting_Started/ko.html");
	await expect(skinSelect(page)).toHaveValue("citizen");

	const response = await chooseSkin(page, "vector-2022");

	expect(response.status()).toBe(200);
	await expect(page).toHaveURL("Getting_Started/ko.html");
	await expectKoreanArticle(page);
	await expect(skinSelect(page)).toHaveValue("vector-2022");
	expect(missing, missing.join("; ")).toEqual([]);
});

// A source page sits one directory higher than its translations; it worked
// before and has to keep working.
test("switching the skin on a source page keeps the article", async ({
	page,
}) => {
	const missing = watchForMissingPages(page);

	await page.goto("Getting_Started.html");

	expect((await chooseSkin(page, "citizen")).status()).toBe(200);
	await expect(page).toHaveURL("citizen/Getting_Started.html");
	await expect(page.locator("#firstHeading")).toHaveText(ENGLISH_HEADING);

	expect((await chooseSkin(page, "vector-2022")).status()).toBe(200);
	await expect(page).toHaveURL("Getting_Started.html");
	await expect(page.locator("#firstHeading")).toHaveText(ENGLISH_HEADING);
	expect(missing, missing.join("; ")).toEqual([]);
});
