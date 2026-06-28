<?php

namespace MediaWiki\Extension\Wikven;

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
	 * Lint decoded site-config contents, returning a warning per silently-dropped mistake.
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

		// Catch wrong types or URL templates missing the $1 placeholder before they break links.
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
