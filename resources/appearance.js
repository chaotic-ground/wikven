// The Settings page build.php generates: the reader's display choices, drawn here because wikitext
// cannot carry a radio and because the choices mean nothing without this script anyway. A static
// host stores nothing, so mw.user.clientPrefs keeps them in the reader's own browser.
//
// The static bundle runs every module on every page, so this leaves where there are no placeholders.
const THEMES = ["day", "night", "os"];

const radio = (group, value, label, checked, onChange) => {
	const item = document.createElement("span");
	item.className = "cdx-radio wikven-appearance-option";

	const input = document.createElement("input");
	input.className = "cdx-radio__input";
	input.type = "radio";
	input.name = group;
	input.id = `${group}-${value}`;
	input.value = value;
	input.checked = checked;
	input.addEventListener("change", () => onChange(value));

	const icon = document.createElement("span");
	icon.className = "cdx-radio__icon";

	const text = document.createElement("label");
	text.className = "cdx-radio__label";
	text.htmlFor = input.id;
	text.textContent = label;

	item.append(input, icon, text);
	return item;
};

const drawTheme = (container) => {
	// Minerva writes skin-theme-clientpref-day on <html> for this bake (see Hooks\Adder), and
	// clientPrefs replaces that class in place, so the page follows without a reload.
	const current = mw.user.clientPrefs.get("skin-theme");
	for (const value of THEMES) {
		container.append(
			radio(
				"wikven-skin-theme",
				value,
				mw.msg(`wikven-appearance-${value}`),
				value === current,
				(chosen) => mw.user.clientPrefs.set("skin-theme", chosen),
			),
		);
	}
};

// The switcher is a list of links rather than a preference: each skin's copy of a page is its own
// file, so choosing one is a navigation. The list comes from the page itself, which the bake wrote
// into the menu, so this page does not have to know the skins.
const drawSkins = (container) => {
	const source = document.querySelector("#p-wikven-skins");
	if (!source) {
		return;
	}
	const list = document.createElement("ul");
	for (const item of source.querySelectorAll('[id^="t-wikven-skin-"]')) {
		list.append(item.cloneNode(true));
	}
	container.append(list);
};

const theme = document.querySelector(".wikven-appearance-theme");
const skins = document.querySelector(".wikven-appearance-skins");

if (theme && mw.user?.clientPrefs) {
	drawTheme(theme);
}
if (skins) {
	drawSkins(skins);
}
