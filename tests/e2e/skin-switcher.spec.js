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

// What each skin puts the switcher behind, where it takes a control to reveal. Vector needs none:
// its list moves into the appearance menu (ext.Wikven.vectorSkins), which is pinned open beside the
// article. Citizen is not here either: its list moves into the preferences panel, and is followed
// by choosePreference().
const OPENERS = {
	"vector-2022": null,
	timeless: null,
};

// Skins that offer no switcher on an article page, so a journey can end on them but not start
// there. Minerva takes no sidebar section that reaches a menu of its own -- its toolbox is the page
// actions -- so its list is on the settings page, which minerva-settings.spec.js follows.
const NO_PAGE_SWITCHER = ["minerva"];

const entry = (page, skin) => page.locator(`#t-wikven-skin-${skin}`).first();

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
const skinsOn = (page) =>
	page.evaluate(() =>
		Array.from(document.querySelectorAll('[id^="t-wikven-skin-"]')).map(
			(element) => ({
				skin: element.id.replace("t-wikven-skin-", ""),
				current: element.classList.contains("active"),
			}),
		),
	);

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
// The sidebar skins wrap the anchor in a list item wider than itself, so a click
// on the entry can land beside the link; Minerva's entry is the anchor already.
// Citizen's chooser is a Codex select in the preferences panel. Its options carry each
// skin's display name in the page's own language, so they are picked by position, which
// is the order of the entries the chooser was built from. That the right name is on the
// right option is citizen.spec.js's assertion, not this file's; here it is the journey.
const choosePreference = async (page, to) => {
	await page.locator(".citizen-preferences-dropdown summary").click();
	const field = page.locator(".cdx-field").filter({ hasText: "Skin" });
	await field.waitFor({ timeout: 15000 });
	await field.locator(".cdx-select-vue__handle").click();
	return field.locator(".cdx-menu-item").nth(order.indexOf(to));
};

const chooseSkin = async (page, from, to) => {
	if (from === "citizen") {
		const option = await choosePreference(page, to);
		const navigation = page.waitForResponse((response) =>
			response.request().isNavigationRequest(),
		);
		await option.click();
		const response = await navigation;
		await page.waitForLoadState("domcontentloaded");
		return response;
	}

	await revealTools(page, from);
	const item = entry(page, to);
	const anchor = item.locator("a");
	const target = (await anchor.count()) ? anchor.first() : item;
	const navigation = page.waitForResponse((response) =>
		response.request().isNavigationRequest(),
	);
	await target.click();
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
// The non-main skins a journey can start from, which is what the tests below switch away from.
let departures;
// The order the skins are listed in, which every skin's copy of the list shares.
let order;

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage();
	await page.goto("index.html");
	const listed = await skinsOn(page);
	main = listed.find((s) => s.current)?.skin;
	others = listed.filter((s) => !s.current).map((s) => s.skin);
	departures = others.filter((skin) => !NO_PAGE_SWITCHER.includes(skin));
	order = listed.map((s) => s.skin);
	await page.close();
});

// One skin means no switcher to follow, so there is nothing here to assert.
test.beforeEach(() => {
	test.skip(!main, "a single-skin export lists no skins");
});

test("every skin's copy lists every skin, and marks the one being read", async ({
	page,
}) => {
	await page.goto("Installation.html");
	const listed = await skinsOn(page);

	expect(listed.map((s) => s.skin).sort()).toEqual([main, ...others].sort());
	expect(listed.length).toBeGreaterThan(1);
	// The skin the page is already in is not offered as a link to itself. The anchor
	// inside the entry, not the entry: the list item never carries an href, so
	// asserting on it would pass however the entry is rendered. (Minerva is the one
	// skin whose current entry does link to itself, but it is never the main skin.)
	await expect(entry(page, main).locator("a")).toHaveCount(0);
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

	await page.goto(`${departures[0]}/Getting_Started/ko.html`);

	const response = await chooseSkin(page, departures[0], main);

	expect(response.status()).toBe(200);
	await expect(page).toHaveURL("Getting_Started/ko.html");
	await expectKoreanArticle(page);
	expect(missing, missing.join("; ")).toEqual([]);
});

// And between two skins that both have a directory, where the old and the new
// skin directory are at the same depth. The way back out to the root copy is the
// test above; what is new here is the hop that changes no depth at all.
test("switching between two non-main skins on a Korean page keeps the article", async ({
	page,
}) => {
	test.skip(others.length < 2, "needs two non-main skins");
	const missing = watchForMissingPages(page);

	const from = departures[0];
	const to = others.find((skin) => skin !== from);
	await page.goto(`${from}/Getting_Started/ko.html`);

	expect((await chooseSkin(page, from, to)).status()).toBe(200);
	await expect(page).toHaveURL(`${to}/Getting_Started/ko.html`);
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

	expect((await chooseSkin(page, main, departures[0])).status()).toBe(200);
	await expect(page).toHaveURL(`${departures[0]}/Getting_Started.html`);
	await expect(page.locator("#firstHeading")).toHaveText(ENGLISH_HEADING);

	expect((await chooseSkin(page, departures[0], main)).status()).toBe(200);
	await expect(page).toHaveURL("Getting_Started.html");
	await expect(page.locator("#firstHeading")).toHaveText(ENGLISH_HEADING);
	expect(missing, missing.join("; ")).toEqual([]);
});

// The generated settings page carries a skins section in every skin, and a section with a heading
// and a description and nothing under it reads as a broken page. Minerva's list is written by the
// build; the others are filled from the chrome by ext.Wikven.appearance.
test("the settings page lists the skins, whichever skin renders it", async ({
	page,
}) => {
	for (const skin of [main, ...others]) {
		const path = skin === main ? "Settings.html" : `${skin}/Settings.html`;
		await page.goto(path);
		await expect(
			page.locator(".wikven-skin-item"),
			`${skin} leaves the skins section empty`,
		).toHaveCount(order.length);
	}
});
