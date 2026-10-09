<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Output\CodeSpaces;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Output\CodeSpaces
 */
class CodeSpacesTest extends MediaWikiUnitTestCase {
	/**
	 * Each way core hands code over, with the no-break space armorFrenchSpaces put before `!=`.
	 *
	 * @dataProvider provideArmoredCode
	 */
	public function testTheSpaceBeforeAnOperatorIsASpaceAgain(string $armored, string $restored) {
		$this->assertSame($restored, CodeSpaces::restore($armored));
	}

	public static function provideArmoredCode(): iterable {
		yield '<pre>' => ["<pre>a\u{00A0}!= b</pre>", '<pre>a != b</pre>'];
		yield '<code>' => ["<p><code>a\u{00A0}!= b</code></p>", '<p><code>a != b</code></p>'];
		// What SyntaxHighlight renders for `if: a != b` in YAML
		yield 'syntaxhighlight' => [
			'<div class="mw-highlight mw-highlight-lang-yaml mw-content-ltr" dir="ltr"><pre><span></span>'
				. '<span class="nt">if</span><span class="p">:</span><span class="w"> </span>'
				. "<span class=\"l l-Scalar l-Scalar-Plain\">a\u{00A0}!= b</span>\n</pre></div>",
			'<div class="mw-highlight mw-highlight-lang-yaml mw-content-ltr" dir="ltr"><pre><span></span>'
				. '<span class="nt">if</span><span class="p">:</span><span class="w"> </span>'
				. "<span class=\"l l-Scalar l-Scalar-Plain\">a != b</span>\n</pre></div>"
		];
		yield 'as an entity' => ['<code>a&#160;!= b</code>', '<code>a != b</code>'];
		yield 'after a guillemet' => ["<code>«\u{00A0}a »</code>", '<code>« a »</code>'];
	}

	/** Prose keeps the armor: there the no-break space is what the rule is for. */
	public function testANoBreakSpaceOutsideCodeIsLeftAlone() {
		$html = "<p>a\u{00A0}!</p><pre>a\u{00A0}!= b</pre><p>b\u{00A0}?</p>";
		$this->assertSame("<p>a\u{00A0}!</p><pre>a != b</pre><p>b\u{00A0}?</p>", CodeSpaces::restore($html));
	}

	/** One the rule could not have written, so an author meant it. */
	public function testANoBreakSpaceTheRuleDidNotWriteStays() {
		foreach (["<code>a\u{00A0}b</code>", "<code>a\u{00A0}!b</code>"] as $html) {
			$this->assertSame($html, CodeSpaces::restore($html));
		}
	}
}
