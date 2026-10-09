<?php

namespace MediaWiki\Extension\Wikven\Output;

/**
 * Puts back the spaces Sanitizer::armorFrenchSpaces took out of code, where Tidy runs it too: the
 * space in `a != b` becomes a no-break space that copied code then carries (#833). Only the shapes
 * that rule writes are undone, and only inside `<pre>` and `<code>`.
 */
class CodeSpaces {
	private const NBSP = '(?:\x{00A0}|&#160;|&nbsp;)';

	public static function restore(string $html): string {
		$undo = static function (array $code): string {
			return preg_replace(
				[
					// The inverse of the rule's two patterns, in its order
					'/' . self::NBSP . '(?=[?:;!%»›](?!\w))/u',
					'/(?<!\w)([«‹])' . self::NBSP . '/u'
				],
				[' ', '$1 '],
				$code[0]
			) ?? $code[0];
		};
		return preg_replace_callback('/<(pre|code)\b[^>]*>.*?<\/\1>/isu', $undo, $html) ?? $html;
	}
}
