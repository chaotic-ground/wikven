<?php

namespace MediaWiki\Extension\Wikven;

/** Append list items to a rendered <ul> chosen by id, leaving the rest of the document alone. */
class HtmlListAppender {
	/**
	 * @param string $html A rendered page.
	 * @param string $listId The id of the <ul> to append to.
	 * @param string $items The markup to insert before that list's closing tag.
	 * @return string The page, unchanged when the list is not there or never closes.
	 */
	public static function append(string $html, string $listId, string $items): string {
		$attribute = 'id="' . $listId . '"';
		$at = strpos($html, $attribute);
		if ($at === false) {
			return $html;
		}
		// The lists this targets hold flat <li> entries, so the first close is this list's.
		$close = strpos($html, '</ul>', $at);
		if ($close === false) {
			return $html;
		}
		return substr($html, 0, $close) . $items . substr($html, $close);
	}
}
