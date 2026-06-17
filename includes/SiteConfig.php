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
	 * wrong-typed config/extensions/skins, or a misspelled Wikven variable (which
	 * would set a global nothing reads). Messages are returned rather than logged
	 * so the check stays pure and unit-testable.
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
		foreach (array_keys($data['config'] ?? []) as $cfgKey) {
			if (str_starts_with($cfgKey, 'Wikven') && !in_array($cfgKey, $knownConfig, true)) {
				$warnings[] = "unknown config '$cfgKey' (not a Wikven variable; typo?).";
			}
		}
		return $warnings;
	}
}
