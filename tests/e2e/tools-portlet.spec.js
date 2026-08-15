// The toolbox is the only skin switcher the export has, so in every skin it must be
// somewhere a reader can actually get to -- not merely present in the DOM. Each skin
// puts it somewhere different, and behind a different control: Vector in the "Tools"
// dropdown, Citizen in the "More actions" card, Minerva in the page-actions overflow
// menu. Only a rendered page shows whether opening that control reveals the links.
//
// A single-skin export has nothing to put in the toolbox and hides the empty box
// instead (ext.Wikven.emptyToolbox); docs/ enables several, so that path is not
// exercised here.

const { test, expect } = require("@playwright/test");

// What each skin puts the toolbox behind, where it takes a control to reveal.
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
		const path =
			skin === main ? "Installation.html" : `${skin}/Installation.html`;
		await page.goto(path);
		await expect(page.locator("#firstHeading")).toBeVisible();

		const opener = OPENERS[skin];
		expect(
			opener,
			`no opener recorded for ${skin}; check where its toolbox renders`,
		).not.toBeUndefined();
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
