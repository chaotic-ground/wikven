<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Extension\Wikven\Build\BuildFor;
use MediaWiki\Extension\Wikven\Output\OutputName;
use StatusValue;

// LocalSettings.php loads this class by hand, before wfLoadExtension has given the extension an
// autoloader, so its neighbours are fetched the same way.
require_once __DIR__ . '/Build/BuildFor.php';
require_once __DIR__ . '/Output/OutputName.php';

/** Helpers for a site's configuration file (accepted .wikven.* names; see CONFIG_FILENAMES). */
class SiteConfig {
	/** Top-level keys the settings format recognises. */
	private const TOP_LEVEL_KEYS = ['config', 'extensions', 'skins'];

	/** Site-config file names wikven accepts, in precedence order (first present wins). */
	public const CONFIG_FILENAMES = [
		'.wikven.yaml',
		'.wikven.yml',
		'.wikven.json',
		'wikven.yaml',
		'wikven.yml',
		'wikven.json'
	];

	/**
	 * Find the site config file; return highest-precedence present name and ignored lower ones.
	 *
	 * @param string $srcDir The source directory to look in.
	 * @return array{path: ?string, ignored: string[]}
	 */
	public static function locate(string $srcDir): array {
		$dir = rtrim($srcDir, '/');
		$found = [];
		foreach (self::CONFIG_FILENAMES as $name) {
			if (is_file("$dir/$name")) {
				$found[] = "$dir/$name";
			}
		}
		return [
			'path' => $found[0] ?? null,
			'ignored' => array_slice($found, 1)
		];
	}

	/**
	 * Config the build works out for itself, which a site's file cannot set.
	 *
	 * All three come from WIKVEN_WORKDIR. A site that set one would move where its pages come from
	 * without moving where its own config file is looked for.
	 */
	public const DERIVED_CONFIG = [
		'WikvenSourceDirectory',
		'WikvenHtmlDirectory',
		'WikvenSourceHistoryFile'
	];

	/**
	 * Config the build works out from the site's own lists, which a site's file cannot set either.
	 *
	 * A site says which skins to build and which to read it in; the build turns those into the
	 * names MediaWiki knows.
	 */
	public const DERIVED_SKIN_CONFIG = [
		'WikvenSkins',
		'WikvenMainSkin',
		'WikvenMissing'
	];

	/**
	 * Lint decoded site-config contents, returning a warning per silently-dropped mistake.
	 *
	 * About shape and values only. Whether a name under "config" is one anything defines cannot be
	 * asked this early: the extensions that would answer are still queued.
	 *
	 * @param mixed $data Decoded .wikven.yaml/.json contents.
	 * @return string[] Warning messages, empty when the file is sound.
	 */
	public static function lint($data): array {
		if (!is_array($data)) {
			return ['the file is not a map; ignoring it.'];
		}
		$warnings = [];
		foreach (array_keys($data) as $key) {
			if (!in_array($key, self::TOP_LEVEL_KEYS, true)) {
				$warnings[] = "unknown top-level key '$key' (expected config/extensions/skins).";
			}
		}
		foreach (['extensions', 'skins'] as $listKey) {
			if (isset($data[$listKey]) && !is_array($data[$listKey])) {
				$warnings[] = "'$listKey' must be a list.";
			}
		}
		if (isset($data['config']) && !is_array($data['config'])) {
			$warnings[] = "'config' must be a map.";
			return $warnings;
		}
		$config = $data['config'] ?? [];
		foreach (array_keys($config) as $cfgKey) {
			if (in_array($cfgKey, self::DERIVED_CONFIG, true)) {
				$warnings[] = "'$cfgKey' is worked out from WIKVEN_WORKDIR by the build; the value here is ignored.";
			}
			if (in_array($cfgKey, self::DERIVED_SKIN_CONFIG, true)) {
				$warnings[] = "'$cfgKey' is worked out by the build from the lists above; the value here is ignored.";
			}
		}

		// Catch wrong types or URL templates missing the $1 placeholder before they break links.
		foreach (['WikvenEditUrl', 'WikvenHistoryUrl', 'WikvenViewSourceUrl'] as $urlKey) {
			$value = $config[$urlKey] ?? '';
			if ($value !== '' && ( !is_string($value) || !str_contains($value, '$1') )) {
				$warnings[] = "'$urlKey' should be a URL template containing \$1 (replaced by the source file name).";
			}
		}
		// The base every absolute URL is built on, which is either one a crawler can fetch or nothing
		// at all: an unusable one in a sitemap is worse than no sitemap.
		$siteUrl = $config['WikvenSiteUrl'] ?? '';
		if ($siteUrl !== '' && ( !is_string($siteUrl) || !SiteUrl::fromWritten($siteUrl)->isKnown() )) {
			$named = is_string($siteUrl) ? "'$siteUrl'" : get_debug_type($siteUrl);
			$warnings[] = "'WikvenSiteUrl' is $named; expected an http or https URL. Ignoring it.";
		}

		foreach (['WikvenLogos', 'WikvenRepositories'] as $mapKey) {
			if (isset($config[$mapKey]) && !is_array($config[$mapKey])) {
				$warnings[] = "'$mapKey' must be a map.";
			}
		}

		// The settings whose value is chosen from a list rather than written freely, so the ones where
		// a near miss is worth naming: each reads what it does not know as its own default.
		$chosen = [
			'WikvenBuildFor' => [BuildFor::all(), BuildFor::SITE],
			'WikvenFileNames' => [OutputName::all(), OutputName::READABLE]
		];
		foreach ($chosen as $key => [$allowed, $fallback]) {
			$written = $config[$key] ?? $fallback;
			if (in_array($written, $allowed, true)) {
				continue;
			}
			// Quote what was written when it is something a person wrote; name the type when it is
			// not, so a stray "true" reads as the wrong kind of value rather than as the string 1.
			$named = is_string($written) ? "'$written'" : get_debug_type($written);
			$expected = implode(', ', $allowed);
			$warnings[] = "'$key' is $named; expected one of $expected. Using '$fallback'.";
		}
		return $warnings;
	}

	/**
	 * Config-schema failures, as lines naming the setting that is wrong.
	 *
	 * Core renders a StatusValue as a debug table wrapped at twenty-five characters, so the name
	 * and the reason are pulled out here. Only errors are read.
	 *
	 * @param StatusValue $status What SettingsBuilder::validate() returned.
	 * @return string[] One line per failed setting, empty when the config conforms.
	 */
	public static function schemaErrors(StatusValue $status): array {
		$errors = [];
		foreach ($status->getMessages('error') as $failure) {
			$parts = [];
			foreach ($failure->getParams() as $param) {
				// A parameter reaches here either as the scalar it was raised with or wrapped in a
				// MessageParam, depending on the version; both carry the same thing to say.
				if (is_object($param) && method_exists($param, 'getValue')) {
					$param = $param->getValue();
				}
				$parts[] = is_scalar($param) ? (string)$param : get_debug_type($param);
			}
			// The message key names the kind of failure, not the setting, so it stands in only when
			// there is nothing better to show.
			$errors[] = $parts === [] ? $failure->getKey() : implode(': ', $parts);
		}
		return $errors;
	}

	/**
	 * Config names declared by a set of extension and skin manifests, as a lookup set.
	 *
	 * ExtensionRegistry turns each name under a manifest's "config" map straight into a global,
	 * which never reaches the schema SettingsBuilder validates against.
	 *
	 * @param string[] $manifestPaths Absolute paths to extension.json/skin.json files.
	 * @return array<string,true> Config names, as keys.
	 */
	public static function manifestConfigNames(array $manifestPaths): array {
		$names = [];
		foreach ($manifestPaths as $manifestPath) {
			$declared = is_readable($manifestPath)
				? json_decode((string)file_get_contents($manifestPath), true)
				: null;
			if (!is_array($declared) || !is_array($declared['config'] ?? null)) {
				continue;
			}
			$config = $declared['config'];
			// Manifest version 1 spells the prefix inside the config map, version 2 beside it.
			$prefix = $config['_prefix'] ?? $declared['config_prefix'] ?? 'wg';
			unset($config['_prefix']);
			if ($prefix !== 'wg') {
				continue;
			}
			foreach (array_keys($config) as $name) {
				$names[(string)$name] = true;
			}
		}
		return $names;
	}

	/**
	 * Config names a site wrote that nothing defines, each with a near miss where there is one.
	 *
	 * The quietest mistake a config file can make: the name goes into a global nothing reads, and
	 * the build succeeds.
	 *
	 * @param string[] $configKeys The names under "config" in the site's file.
	 * @param string[] $defined Every config name something here defines.
	 * @return string[] One warning per undefined name, empty when every name is somebody's.
	 */
	public static function undefinedConfig(array $configKeys, array $defined): array {
		$known = array_fill_keys($defined, true);
		$warnings = [];
		foreach ($configKeys as $configKey) {
			$name = (string)$configKey;
			if (isset($known[$name])) {
				continue;
			}
			$nearest = self::nearestName($name, $defined);
			$warnings[] = $nearest === null
				? "unknown config '$name'; nothing loaded here defines it, and it is ignored."
				: "unknown config '$name'; nothing loaded here defines it. Did you mean '$nearest'?";
		}
		return $warnings;
	}

	/**
	 * The defined name closest to $name, or null when none of them is close enough to suggest.
	 *
	 * A misspelling is a character or two out; past that a suggestion is a guess. The allowance
	 * grows with the name.
	 *
	 * @param string $name A config name nothing defines.
	 * @param string[] $defined Every config name something here defines.
	 * @return ?string
	 */
	private static function nearestName(string $name, array $defined): ?string {
		$allowed = max(1, intdiv(strlen($name), 4));
		$nearest = null;
		$distance = $allowed + 1;
		foreach ($defined as $candidate) {
			$candidate = (string)$candidate;
			if (abs(strlen($candidate) - strlen($name)) > $allowed) {
				continue;
			}
			$candidateDistance = levenshtein($name, $candidate);
			if ($candidateDistance < $distance) {
				$nearest = $candidate;
				$distance = $candidateDistance;
			}
		}
		return $nearest;
	}

	/**
	 * Is $name usable as the directory name of a bundled extension or skin?
	 *
	 * These names become paths under $IP, so one carrying a separator would be loaded from
	 * elsewhere as if the image had shipped it.
	 *
	 * @param string $name A name from a config file's 'extensions' or 'skins' list.
	 * @return bool Whether the name is a plain directory name.
	 */
	public static function isComponentName(string $name): bool {
		return $name !== '' && strpbrk($name, "/\\") === false && !str_starts_with($name, '.');
	}
}
