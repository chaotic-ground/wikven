// The appearance panel fillMinervaMenu.php writes into Minerva's main menu. The markup is rendered
// at bake time; this wires it to the preference, which is where the whole thing has to live: a
// static host stores nothing, and mw.user.clientPrefs keeps the choice in the reader's own browser.
//
// The static bundle runs every module on every page, so this leaves where there is no panel.
const controls = document.querySelectorAll(".wikven-appearance-option input");

if (controls.length && mw.user?.clientPrefs) {
	// Minerva puts skin-theme-clientpref-day on <html> for this bake (see Hooks\Adder), and
	// clientPrefs replaces that class in place, so the page follows without a reload.
	const current = mw.user.clientPrefs.get("skin-theme");

	for (const control of controls) {
		control.checked = control.value === current;
		control.addEventListener("change", () => {
			mw.user.clientPrefs.set("skin-theme", control.value);
		});
	}
}
