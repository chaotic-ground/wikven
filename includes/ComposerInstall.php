<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Where composer put the packages a site asked for, and whether that is where the build looks.
 *
 * A name in a site's extensions: or skins: list is a directory name, and WikvenSettings.php turns
 * it straight into a path under $IP. A composer package names its own directory instead, through
 * composer/installers, and the two do not have to agree: a "mediawiki-extension" is CamelCased
 * with a trailing "-extension" cut off (mediawiki/tabber-neue-extension -> extensions/TabberNeue),
 * a "mediawiki-skin" is not CamelCased at all (mediawiki/chameleon-skin -> skins/chameleon), and a
 * package declaring neither type lands in vendor/ like any other library.
 *
 * So a site listing "Chameleon" and asking for "mediawiki/chameleon-skin" got skins/chameleon,
 * which on a case-sensitive filesystem is a different directory. Every step succeeded: the package
 * installed, the log said "skipping skin 'Chameleon' (not bundled in this image)", the build
 * carried on, and the site went out without its skin.
 */
class ComposerInstall {
	/**
	 * Where composer put each package it installed, keyed by package name.
	 *
	 * Read from composer's own record rather than worked out from the package name, because the
	 * directory depends on the type the package declares as much as on what it is called, and only
	 * the package knows its type.
	 *
	 * @param string $installPath MediaWiki's install directory ($IP).
	 * @return array<string,array{version:string,path:string}> Absolute paths, by package name; a
	 *   package composer recorded without a path has an empty one.
	 */
	public static function locations(string $installPath): array {
		$record = rtrim($installPath, '/') . '/vendor/composer/installed.json';
		if (!is_readable($record)) {
			return [];
		}
		$decoded = json_decode((string)file_get_contents($record), true);
		// Composer 2 wraps the list in "packages"; older records are the list itself.
		$entries = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : $decoded;
		if (!is_array($entries)) {
			return [];
		}
		$locations = [];
		foreach ($entries as $entry) {
			if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
				continue;
			}
			// install-path is written relative to vendor/composer, which is where the record lives.
			$path = (string)( $entry['install-path'] ?? '' );
			$resolved = $path === ''
				? ''
				: (string)realpath(rtrim($installPath, '/') . "/vendor/composer/$path");
			$locations[$entry['name']] = [
				'version' => (string)( $entry['version'] ?? '?' ),
				'path' => $resolved
			];
		}
		return $locations;
	}

	/**
	 * What to tell a site whose package did not land where the build loads it from, or null.
	 *
	 * @param string $installPath MediaWiki's install directory ($IP).
	 * @param array{package:string,name:string,kind:string,dest:string} $asked The package, the name
	 *   the site listed it under, whether it is an extension or a skin, and the directory this
	 *   build will look in.
	 * @param array<string,array{version:string,path:string}> $installed From locations().
	 * @return ?string
	 */
	public static function misplaced(string $installPath, array $asked, array $installed): ?string {
		$package = $asked['package'];
		$name = $asked['name'];
		$kind = $asked['kind'];
		if (!isset($installed[$package])) {
			return (
				"$kind '$name' asked for composer package $package, which composer did not install."
				. ' Check the package name, and that the registry serves a version matching the constraint.'
			);
		}
		$landed = $installed[$package]['path'];
		$wanted = realpath($asked['dest']);
		if ($landed !== '' && $wanted !== false && $landed === $wanted) {
			return null;
		}
		$where = $landed === '' ? 'nowhere this build can see' : self::relativeTo($installPath, $landed);
		$expected = self::relativeTo($installPath, $asked['dest']);
		return (
			"$kind '$name' asked for composer package $package, which installed into $where."
			. " A name in {$kind}s: is the directory this build loads from, and it looks in $expected,"
			. ' so nothing would load it.'
			. self::suggestion($installPath, $kind, $landed)
		);
	}

	/**
	 * What the site could write instead, where there is something worth writing.
	 *
	 * Only a package composer put directly under extensions/ or skins/ has a name to offer: one
	 * that landed in vendor/ declared neither MediaWiki type, and renaming the entry would not make
	 * it a component. A package that landed in the other of the two is a component, just not the
	 * kind it was listed as, and the list it belongs in is the more useful half of that answer.
	 *
	 * @param string $installPath MediaWiki's install directory ($IP).
	 * @param string $kind 'extension' or 'skin', as the site listed it.
	 * @param string $landed Where composer put it, or '' where composer recorded no path.
	 */
	private static function suggestion(string $installPath, string $kind, string $landed): string {
		if ($landed === '' || dirname($landed, 2) !== rtrim($installPath, '/')) {
			return '';
		}
		$under = basename(dirname($landed));
		if ($under === "{$kind}s") {
			return " List it as '" . basename($landed) . "' instead.";
		}
		if ($under === 'extensions' || $under === 'skins') {
			return ' It is a ' . rtrim($under, 's') . ", so list '" . basename($landed) . "' under $under: instead.";
		}
		return '';
	}

	/**
	 * Whether a version constraint names one release and no other.
	 *
	 * A tarball is pinned by its sha256 and a repository by its commit; for a package the pin is an
	 * exact version, and anything looser takes whatever the registry serves on the day. That is a
	 * bargain a site is entitled to make -- the same one a moving `reference` makes -- so this only
	 * decides whether the build says so.
	 *
	 * @param string $constraint What follows the colon in "vendor/name:constraint", or "*".
	 */
	public static function isExactVersion(string $constraint): bool {
		return preg_match('/^v?\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?$/', trim($constraint)) === 1;
	}

	/** $path written against $installPath where it is under it, for a message about this install. */
	private static function relativeTo(string $installPath, string $path): string {
		$prefix = rtrim($installPath, '/') . '/';
		return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
	}
}
