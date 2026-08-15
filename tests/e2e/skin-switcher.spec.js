// The toolbox carries the skin switcher: each enabled skin's copy of the current
// page, as a link the bake writes at render time. Both halves of that href vary --
// the skin directory the page is being rendered into, and how many directories deep
// the page sits -- and a translated page ("Getting Started/ko" exports to
// Getting_Started/ko.html) is deeper than its source. Getting it wrong sends the
// reader to a path the export does not contain, which no static assertion can see:
// the pages themselves are all present and correct. These tests follow the links in
// a browser, where the resulting 404 is visible.

const { test, expect } = require("@playwright/test");

// The article the sequence runs on, in each language.
const ENGLISH_HEADING = "Getting Started";
const KOREAN_HEADING = "시작하기";

// What each skin puts the toolbox behind, where it takes a control to reveal.
// Minerva is the odd one: its toolbox is the page-actions overflow menu, so the
// entries are anchors in that menu rather than sidebar list items.
// Vector's dropdown is a checkbox the label sits under, so the label is not what a
// click lands on; Citizen's is a <details> and Minerva's toggle is its own control.
const OPENERS = {
	"vector-2022": "#vector-page-tools-dropdown-checkbox",
	citizen: "#citizen-page-more-dropdown summary",
	timeless: null,
	minerva: "#page-actions-overflow-toggle",
};

const entry = (page, skin) =>
	page
		.locator(
			`#t-wikven-skin-${skin}, .menu__item--page-actions-overflow-wikven-skin-${skin}`,
		)
		.first();

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

// The skin the current page was rendered in, and the skins it offers.
const skinsOn = async (page) => {
	const ids = await page.evaluate(() =>
		Array.from(
			document.querySelectorAll(
				'[id^="t-wikven-skin-"], [class*="page-actions-overflow-wikven-skin-"]',
			),
		).map((element) => {
			const id = element.id.replace("t-wikven-skin-", "");
			if (id) {
				return { skin: id, current: element.classList.contains("active") };
			}
			const match = element.className.match(
				/page-actions-overflow-wikven-skin-([a-z0-9-]+)/,
			);
			return { skin: match[1], current: false };
		}),
	);
	return ids;
};

const revealTools = async (page, skin) => {
	const opener = OPENERS[skin];
	if (!opener) {
		return;
	}
	const control = page.locator(opener);
	if (await control.count()) {
		await control.first().click();
	}
};

// Follow the switcher to another skin and wait out the navigation it triggers,
// returning the response so a test can assert the page was actually served.
const chooseSkin = async (page, from, to) => {
	await revealTools(page, from);
	const navigation = page.waitForResponse((response) =>
		response.request().isNavigationRequest(),
	);
	await entry(page, to).click();
	const response = await navigation;
	await page.waitForLoadState("domcontentloaded");
	return response;
};

const expectKoreanArticle = async (page) => {
	await expect(page.locator("#firstHeading")).toHaveText(KOREAN_HEADING);
	await expect(page.locator("html")).toHaveAttribute("lang", "ko");
};

// Which skins this export has, discovered from the switcher itself so the tests
// follow docs/.wikven.yml rather than restating it.
let main;
let others;

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage();
	await page.goto("index.html");
	const listed = await skinsOn(page);
	main = listed.find((s) => s.current).skin;
	others = listed.filter((s) => !s.current).map((s) => s.skin);
	await page.close();
});

test("every skin's copy lists every skin, and marks the one being read", async ({
	page,
}) => {
	await page.goto("Installation.html");
	const listed = await skinsOn(page);

	expect(listed.map((s) => s.skin).sort()).toEqual([main, ...others].sort());
	expect(listed.length).toBeGreaterThan(1);
	// The skin the page is already in is not offered as a link to itself.
	await expect(entry(page, main)).not.toHaveAttribute("href", /./);
});

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

	const response = await chooseSkin(page, main, others[0]);

	expect(response.status()).toBe(200);
	await expect(page).toHaveURL(`${others[0]}/Getting_Started/ko.html`);
	await expectKoreanArticle(page);
	expect(missing, missing.join("; ")).toEqual([]);
});

// The other direction: back to the main skin, which lives at the export root
// rather than in a directory of its own.
test("switching back to the main skin from a Korean page returns to the root copy", async ({
	page,
}) => {
	const missing = watchForMissingPages(page);

	await page.goto(`${others[0]}/Getting_Started/ko.html`);

	const response = await chooseSkin(page, others[0], main);

	expect(response.status()).toBe(200);
	await expect(page).toHaveURL("Getting_Started/ko.html");
	await expectKoreanArticle(page);
	expect(missing, missing.join("; ")).toEqual([]);
});

// And between two skins that both have a directory, where the old and the new
// skin directory are at the same depth.
test("switching between two non-main skins on a Korean page keeps the article", async ({
	page,
}) => {
	test.skip(others.length < 2, "needs two non-main skins");
	const missing = watchForMissingPages(page);

	await page.goto(`${others[0]}/Getting_Started/ko.html`);

	const response = await chooseSkin(page, others[0], others[1]);

	expect(response.status()).toBe(200);
	await expect(page).toHaveURL(`${others[1]}/Getting_Started/ko.html`);
	await expectKoreanArticle(page);
	expect(missing, missing.join("; ")).toEqual([]);
});

// And between two skins that both have a directory, where the old and the new
// skin directory are at the same depth, then back out to the root copy.
test("switching between two non-main skins on a Korean page keeps the article", async ({
	page,
}) => {
	const missing = watchForMissingPages(page);

	await page.goto("citizen/Getting_Started/ko.html");

	expect((await chooseSkin(page, "minerva")).status()).toBe(200);
	await expect(page).toHaveURL("minerva/Getting_Started/ko.html");
	await expectKoreanArticle(page);
	await expect(skinSelect(page)).toHaveValue("minerva");

	expect((await chooseSkin(page, "vector-2022")).status()).toBe(200);
	await expect(page).toHaveURL("Getting_Started/ko.html");
	await expectKoreanArticle(page);
	expect(missing, missing.join("; ")).toEqual([]);
});

// A source page sits one directory higher than its translations; it worked
// before and has to keep working.
test("switching the skin on a source page keeps the article", async ({
	page,
}) => {
	const missing = watchForMissingPages(page);

	await page.goto("Getting_Started.html");

	expect((await chooseSkin(page, main, others[0])).status()).toBe(200);
	await expect(page).toHaveURL(`${others[0]}/Getting_Started.html`);
	await expect(page.locator("#firstHeading")).toHaveText(ENGLISH_HEADING);

	expect((await chooseSkin(page, others[0], main)).status()).toBe(200);
	await expect(page).toHaveURL("Getting_Started.html");
	await expect(page.locator("#firstHeading")).toHaveText(ENGLISH_HEADING);
	expect(missing, missing.join("; ")).toEqual([]);
});
