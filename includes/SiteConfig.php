<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Helpers for a site's .wikven.yaml / .wikven.json configuration file.
 */
class SiteConfig {
	/** Top-level keys the settings format recognises. */
	private const TOP_LEVEL_KEYS = ['config', 'extensions', 'skins'];

	/**
	 * Lint decoded site-config contents, returning a warning for each mistake the
	 * settings system would otherwise drop silently: an unknown top-level key, a
	 * wrong-typed config/extensions/skins, a misspelled Wikven variable (which
	 * would set a global nothing reads), or a Wikven value of the wrong shape (a
	 * URL template missing its $1 placeholder, a non-map logos/repositories).
	 * Messages are returned rather than logged so the check stays pure and
	 * unit-testable.
	 *
	 * @param mixed $data Decoded .wikven.yaml/.json contents.
	 * @param string[] $knownConfig Canonical Wikven config variable names, from extension.json.
	 * @return string[] Warning messages, empty when the file is sound.
	 */
	public static function lint($data, array $knownConfig): array {
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
			if (str_starts_with($cfgKey, 'Wikven') && !in_array($cfgKey, $knownConfig, true)) {
				$warnings[] = "unknown config '$cfgKey' (not a Wikven variable; typo?).";
			}
		}

		// Value-shape checks for the Wikven keys whose shape matters, so a wrong
		// type or a URL template missing its $1 placeholder is caught here rather
		// than silently producing broken links or being dropped.
		foreach (['WikvenEditUrl', 'WikvenHistoryUrl', 'WikvenViewSourceUrl'] as $urlKey) {
			$value = $config[$urlKey] ?? '';
			if ($value !== '' && ( !is_string($value) || !str_contains($value, '$1') )) {
				$warnings[] = "'$urlKey' should be a URL template containing \$1 (replaced by the source file name).";
			}
		}
		foreach (['WikvenLogos', 'WikvenRepositories'] as $mapKey) {
			if (isset($config[$mapKey]) && !is_array($config[$mapKey])) {
				$warnings[] = "'$mapKey' must be a map.";
			}
		}
		return $warnings;
	}
}
