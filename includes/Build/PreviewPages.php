<?php

namespace MediaWiki\Extension\Wikven\Build;

/**
 * The pages a skin preview renders beyond the content: special pages, a history, a diff (#830).
 *
 * rebuildFileCache.php walks the page table, so none of these are ever rendered, and the history
 * it would render is skipped on purpose (#408). Each is rendered only where a preview lists it.
 */
class PreviewPages {
	/**
	 * Between a title and its query in an output name: "index#action=history.html".
	 *
	 * No title can hold a "#", so no page of the site can be written to the same file.
	 */
	public const SEPARATOR = '#';

	/**
	 * The entries this build renders: $wgWikvenPreviewExtraPages, in a skin preview only.
	 *
	 * @return string[]
	 */
	public static function entries(): array {
		if (!BuildFor::skinPreview()) {
			return [];
		}
		$listed = $GLOBALS['wgWikvenPreviewExtraPages'] ?? [];
		return is_array($listed) ? array_values(array_filter($listed, 'is_string')) : [];
	}

	/**
	 * An entry's title text and query: "index?action=history" is ["index", ["action" => "history"]].
	 *
	 * A title may hold a "?" itself, so only a tail that reads as name=value pairs is a query.
	 *
	 * @return array{string,array<string,string>}
	 */
	public static function parse(string $entry): array {
		if (!preg_match('/^(.*?)\?([^?]*=[^?]*)$/s', $entry, $matches)) {
			return [$entry, []];
		}
		parse_str($matches[2], $params);
		return [$matches[1], array_map('strval', array_filter($params, 'is_scalar'))];
	}

	/**
	 * A query in the one spelling an entry and a link are compared in.
	 *
	 * The title is the page's own name, which a link carries in the query and an entry does not.
	 *
	 * @param array<string,mixed> $params
	 * @return ?string Null for a query no entry can spell, such as one carrying a list.
	 */
	public static function query(array $params): ?string {
		unset($params['title']);
		foreach ($params as $value) {
			if (!is_scalar($value)) {
				return null;
			}
		}
		ksort($params);
		return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	}

	/** The name an entry is written under, in the place OutputName::of() takes a dbkey. */
	public static function dbkey(string $dbkey, string $query): string {
		return $query === '' ? $dbkey : $dbkey . self::SEPARATOR . $query;
	}
}
