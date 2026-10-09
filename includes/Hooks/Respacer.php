<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Extension\Wikven\Output\CodeSpaces;

/**
 * Code reads as its source wrote it, a skin preview included: the spaces are content, not chrome.
 */
class Respacer implements \MediaWiki\Hook\ParserOutputPostCacheTransformHook {
	/** @inheritDoc */
	public function onParserOutputPostCacheTransform($parserOutput, &$text, &$options): void {
		$text = CodeSpaces::restore($text);
	}
}
