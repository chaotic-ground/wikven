const { defineConfig, devices } = require("@playwright/test");

// CI bakes docs/ to dist/, serves it under the site's own path prefix, and passes
// the resulting base URL in WIKVEN_BASE_URL. The specs use paths relative to it.
const baseURL = process.env.WIKVEN_BASE_URL ?? "http://127.0.0.1:8080/wikven/";

module.exports = defineConfig({
	testDir: "./tests/e2e",
	reporter: "line",
	use: {
		baseURL,
		...devices["Desktop Chrome"],
	},
	// Polls this exact URL until it answers, aborts the run with the server's own output if it never
	// does, and tears the process down from the runner even when the job is cancelled. `dist` is
	// exposed under the site's own /wikven/ path prefix, as GitHub Pages serves it, so the Pagefind
	// bundle and the results page resolve. This also makes `npm run test:e2e` work as-is outside CI.
	webServer: {
		command:
			'mkdir -p pw-root && ln -sfn "$PWD/dist" pw-root/wikven && ' +
			"python3 -m http.server 8080 --directory pw-root",
		url: `${baseURL}index.html`,
		timeout: 30_000,
		reuseExistingServer: !process.env.CI,
		stdout: "pipe",
		stderr: "pipe",
	},
});
