// The <syntaxhighlight copy> button is added by ext.pygments.view, a module the parser hook
// registers on every page carrying a highlighted block. Nothing in the static output proves it
// arrived: the wrapper class is emitted by PHP whether or not the module is in the bundle, and the
// button itself only exists once the module has run. Collecting a module the export then fails to
// serve is a failure this repo has had before, and it is invisible until a browser runs the page.

const { test, expect } = require("@playwright/test");

// Four <syntaxhighlight ... copy> blocks.
const PAGE = "Configuration.html";

test.use({ permissions: ["clipboard-read", "clipboard-write"] });

test("the copy button reaches the export and copies the block", async ({
	page,
}) => {
	await page.goto(PAGE);

	// PHP's half: the wrapper is in the HTML whether or not the module ever loads.
	const wrapper = page.locator(".mw-highlight-copy").first();
	await expect(wrapper).toBeVisible();

	// The module's half. It appends on wikipage.content, so the button's presence is the assertion
	// that buildScripts collected ext.pygments.view and rewriteScripts re-triggered it.
	const button = page.locator(".mw-highlight-copy-button").first();
	await expect(button).toBeVisible();
	const label = await button.textContent();

	const code = (await wrapper.locator("pre").first().textContent()).trim();
	await button.click();

	// The label flips only after writeText resolves; the handler returns early if it throws.
	await expect(button).not.toHaveText(label);
	await expect
		.poll(() => page.evaluate(() => navigator.clipboard.readText()))
		.toBe(code);
});
