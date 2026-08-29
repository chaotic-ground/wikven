// Which skin to read a page in is a choice about how the page looks, so Vector offers it in the
// appearance menu, beside the theme, the text size and the content width -- not under "Tools",
// which is where the sidebar section it is rendered in lands (#405).
//
// The move is the client's: Vector's appearance menu is empty in the served HTML and exposes no way
// to add an entry to it, so ext.Wikven.vectorSkins moves the rendered section into it. Nothing
// static shows whether that arrived -- the section is present in the HTML either way, under Tools
// -- so this follows it in a browser, and follows what it left behind.

const { test, expect } = require("@playwright/test");

const PAGE = "Installation.html";
const HEADING = "Installation";

// The menu, and the skin list once it is inside it. The list keeps the portlet the bake rendered,
// heading and all, so the section names itself where it lands.
const MENU = "#vector-appearance";
const LIST = `${MENU} .mw-portlet-wikven-skins`;
// The box Vector draws around its page-tools menu, which is where the rendered section lands, and
// the control that opens it -- a transparent checkbox laid over the menu's own label, so a click
// aimed at the label lands on the checkbox.
const TOOLS = ".vector-page-tools-landmark";
const TOOLS_OPENER = "#vector-page-tools-dropdown-checkbox";

// Where each label's text actually starts, which is what a reader sees lining up (or not). A
// range over the contents rather than the element's own box: the element is laid out to the
// menu's width, so its box is the same wherever the text inside it is put.
const labelLefts = (page, selector) =>
	page.locator(selector).evaluateAll((labels) =>
		labels.map((label) => {
			const range = document.createRange();
			range.selectNodeContents(label);
			return range.getBoundingClientRect().left;
		}),
	);

// Vector renders the export's main skin at the root; a page there that no export renders in Vector
// would leave every assertion below vacuous.
test.beforeEach(async ({ page }) => {
	await page.goto(PAGE);
	await expect(page.locator("#firstHeading")).toHaveText(HEADING);
	test.skip(
		!(await page.locator(MENU).count()),
		"this export does not render Vector",
	);
	test.skip(
		!(await page.locator(".mw-portlet-wikven-skins").count()),
		"a single-skin export lists no skins",
	);
});

test("the skin list is in the appearance menu, and every skin is on it", async ({
	page,
}) => {
	const list = page.locator(LIST);
	await expect(list).toBeVisible();

	// Every skin the export renders, which is what the section held under Tools; asserted against
	// the entries in the document rather than against a count, so this follows docs/.wikven.yaml.
	const entries = await page.locator('[id^="t-wikven-skin-"]').count();
	expect(entries).toBeGreaterThan(1);
	await expect(list.locator(".mw-list-item")).toHaveCount(entries);

	// The skin being read is marked, and is not offered as a link to itself. The anchor inside the
	// entry, not the entry: the list item never carries an href, so asserting on it would pass
	// however the entry is rendered.
	await expect(list.locator(".mw-list-item.active")).toHaveCount(1);
	await expect(list.locator(".mw-list-item.active a")).toHaveCount(0);
});

test("the skins are laid out one to a row, like the choices above them", async ({
	page,
}) => {
	// The list is styled for the page-tools menu, which lays its entries out along a line. Moved
	// into a sidebar-width menu unchanged, the skin names ran together into one word
	// ("CitizenMinervaNeue") beside the current one. Presence is not enough to catch that, so this
	// measures where the entries actually land.
	const rows = await page
		.locator(`${LIST} .mw-list-item`)
		.evaluateAll((items) =>
			items.map((item) => {
				const { top, bottom, left } = item.getBoundingClientRect();
				return { top, bottom, left };
			}),
		);
	expect(rows.length).toBeGreaterThan(1);

	for (const [index, row] of rows.entries()) {
		if (index === 0) {
			continue;
		}
		// Below the entry before it, not beside it. Sub-pixel rounding is the tolerance.
		expect(
			row.top,
			`entry ${index} shares a line with the one before it`,
		).toBeGreaterThanOrEqual(rows[index - 1].bottom - 0.5);
	}

	// And down a common edge. Measured on the text rather than on the entry, because the entry
	// fills the menu's width whatever the label inside it does: centred names sit on a row each
	// and still line their boxes up, so the boxes say nothing about what a reader sees.
	const lefts = await labelLefts(page, `${LIST} .mw-list-item > *`);
	expect(lefts).toHaveLength(rows.length);
	for (const [index, left] of lefts.entries()) {
		expect(left, `entry ${index}'s name does not line up`).toBeCloseTo(
			lefts[0],
			0,
		);
	}
});

test("what is left of the Tools box goes with it", async ({ page }) => {
	// The list left the page-tools menu, rather than merely being copied into the appearance menu:
	// two switchers disagreeing about which skin is current is its own bug.
	await expect(page.locator(`${TOOLS} .mw-portlet-wikven-skins`)).toHaveCount(
		0,
	);

	// All that menu held was the toolbox Hider empties and this list, so the box Vector draws
	// around them has nothing left to frame.
	await expect(page.locator(TOOLS).first()).toBeHidden();
});

test("following an entry from the menu switches the skin, keeping the article", async ({
	page,
}) => {
	// The entry names the skin it leads to, which is the directory that skin's copy of the page is
	// exported into -- so the entry says where following it should land.
	const item = page.locator(`${LIST} .mw-list-item:not(.active)`).first();
	const skin = (await item.getAttribute("id")).replace("t-wikven-skin-", "");
	const link = item.locator("a");
	await expect(link).toBeVisible();

	const navigation = page.waitForResponse((response) =>
		response.request().isNavigationRequest(),
	);
	await link.click();
	const response = await navigation;

	// The href the bake wrote, reparented for this page's own depth: the move must not have
	// disturbed it, and the page it names must be one the export actually contains.
	expect(response.status()).toBe(200);
	await page.waitForLoadState("domcontentloaded");
	await expect(page.locator("#firstHeading")).toHaveText(HEADING);
	expect(page.url()).toMatch(new RegExp(`/${skin}/${PAGE}$`));
});

test("the list travels with the menu when it is hidden and put back", async ({
	page,
}) => {
	// The list is inside the pinnable element rather than beside it in the container, so Vector's
	// own move takes it along. Unpinning is a client preference, so the reader arrives at the next
	// page with the menu already in the header dropdown -- the state this has to keep working in.
	await page.locator(`${MENU} .vector-pinnable-header-unpin-button`).click();
	await page.reload();

	await page.locator("#vector-appearance-dropdown-checkbox").click();
	const unpinned = `#vector-appearance-unpinned-container ${LIST}`;
	await expect(page.locator(unpinned)).toBeVisible();

	// The dropdown is a second piece of chrome with styling of its own, and it is the one that had
	// the names strung down the middle of the menu. So the rows are asserted here too, rather than
	// only in the pinned menu beside the article. What the browser worked out for each entry rides
	// along in the failure, because "does not line up" alone does not say which of the ways a row
	// can be pushed off its edge did it.
	const rows = await page
		.locator(`${unpinned} .mw-list-item`)
		.evaluateAll((items) =>
			items.map((item) => {
				const label = item.firstElementChild;
				const range = document.createRange();
				range.selectNodeContents(label);
				const round = (n) => Math.round(n * 10) / 10;
				return {
					name: label.textContent.trim(),
					textLeft: round(range.getBoundingClientRect().left),
					item: `${round(item.getBoundingClientRect().left)}+${round(item.getBoundingClientRect().width)}`,
					label: `${round(label.getBoundingClientRect().left)}+${round(label.getBoundingClientRect().width)}`,
					align: `${getComputedStyle(item).textAlign}/${getComputedStyle(label).textAlign}`,
					display: `${getComputedStyle(item).display}/${getComputedStyle(label).display}`,
					list: `${getComputedStyle(item.parentElement).display} ${getComputedStyle(item.parentElement).alignItems}`,
				};
			}),
		);
	expect(rows.length).toBeGreaterThan(1);
	for (const row of rows) {
		expect(row.textLeft, JSON.stringify(rows, null, 1)).toBeCloseTo(
			rows[0].textLeft,
			0,
		);
	}

	await page.locator(`${MENU} .vector-pinnable-header-pin-button`).click();
	await expect(
		page.locator(`#vector-appearance-pinned-container ${LIST}`),
	).toBeVisible();
});

test("without JavaScript the Tools menu still lists the skins", async ({
	browser,
	baseURL,
}) => {
	// Nothing moves the list without JavaScript, which is why the module moves the rendered section
	// rather than replacing it: the server-rendered links stay the fallback, in the box that is
	// hidden only once they have left it.
	const context = await browser.newContext({
		javaScriptEnabled: false,
		baseURL,
	});
	const page = await context.newPage();
	await page.goto(PAGE);

	await expect(page.locator(LIST)).toHaveCount(0);
	await page.locator(TOOLS_OPENER).click();

	const list = page.locator(".mw-portlet-wikven-skins");
	await expect(list).toBeVisible();
	await expect(list.locator(".mw-list-item.active")).toBeVisible();
	await expect(
		list.locator(".mw-list-item:not(.active) a").first(),
	).toHaveAttribute("href", /Installation\.html$/);

	await context.close();
});
