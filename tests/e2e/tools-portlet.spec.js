// The switcher is the only one the export has, so in every skin it must be somewhere a reader can
// actually get to -- not merely present in the DOM. Each skin puts it somewhere different, and
// behind a different control. Only a rendered page shows whether opening that control reveals it.
//
// A single-skin export has nothing to switch to and hides the empty box instead
// (ext.Wikven.emptyToolbox); docs/ enables several, so that path is not exercised here.
//
// Three skins are exceptions, and have specs of their own. Citizen: with JavaScript its skin list
// moves into the preferences panel and takes the toolbox with it (citizen.spec.js covers both that
// and the plain list a reader without JavaScript is left). Vector: the same move, into its
// appearance menu (vector-skins.spec.js). Minerva: it has no page-tools menu that is not its page
// actions, so its list is on the settings page instead of on every page
// (minerva-settings.spec.js).

const { test, expect } = require("@playwright/test");

// What each skin puts the switcher behind, where it takes a control to reveal. A skin absent from
// here either carries no switcher on an article page or keeps it somewhere a spec of its own
// follows.
const OPENERS = {
	timeless: null,
};

const entry = (page, skin) => page.locator(`#t-wikven-skin-${skin}`).first();

let skins;
let main;

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage();
	await page.goto("index.html");
	skins = await page.evaluate(() =>
		Array.from(document.querySelectorAll('[id^="t-wikven-skin-"]')).map(
			(element) => ({
				skin: element.id.replace("t-wikven-skin-", ""),
				current: element.classList.contains("active"),
			}),
		),
	);
	main = skins.find((s) => s.current)?.skin;
	await page.close();
});

// One skin means an empty toolbox, hidden rather than reachable (ext.Wikven.emptyToolbox).
test.beforeEach(() => {
	test.skip(!main, "a single-skin export puts nothing in the toolbox");
});

test("the toolbox is reachable in every skin the export renders", async ({
	page,
}) => {
	for (const { skin } of skins) {
		if (!(skin in OPENERS)) {
			continue;
		}
		const path =
			skin === main ? "Installation.html" : `${skin}/Installation.html`;
		await page.goto(path);
		await expect(page.locator("#firstHeading")).toBeVisible();

		const opener = OPENERS[skin];
		if (opener) {
			await page.locator(opener).first().click();
		}

		// Some other skin's copy of this page, visible and ready to be followed.
		const other = skins.find((s) => s.skin !== skin).skin;
		await expect(
			entry(page, other),
			`${skin} hides its switcher`,
		).toBeVisible();
	}
});
