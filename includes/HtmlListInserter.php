<?php

namespace MediaWiki\Extension\Wikven;

/** Insert markup after a rendered <ul> chosen by id, leaving the rest of the document alone. */
class HtmlListInserter {
	/**
	 * @param string $html A rendered page.
	 * @param string $listId The id of the <ul> to insert after.
	 * @param string $markup What to insert once that list closes.
	 * @return string The page, unchanged when the list is not there or never closes.
	 */
	public static function after(string $html, string $listId, string $markup): string {
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
		$after = $close + strlen('</ul>');
		return substr($html, 0, $after) . $markup . substr($html, $after);
	}
}
