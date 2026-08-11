<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\BuildStamps;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\BuildStamps
 */
class BuildStampsTest extends MediaWikiUnitTestCase {
	/** A page as the file cache wrote it, with every stamp a real bake produced. */
	private function page(string $request, string $render, string $stamp, int $id, int $ms): string {
		return (
			"<!DOCTYPE html>\n<html><head><script>RLCONF={\"wgRequestId\":\"$request\","
			. "\"wgPageName\":\"index\",\"wgCurRevisionId\":$id,\"wgRevisionId\":$id,"
			. "\"wgArticleId\":75,\"wgRelevantArticleId\":75,\"wgIsArticle\":true};</script></head><body>\n"
			. "<p>The page itself.</p>\n"
			. "<ul id=\"footer-info\">\n\t<li id=\"footer-info-copyright\">CC BY-SA</li>\n</ul>\n"
			. "<!-- Render ID $render -->\n"
			. '<!-- Saved in parser cache with key my_wiki:pcache:75:|#|:idhash:canonical and '
			. "timestamp $stamp and revision id $id. Rendering was triggered because: page_view\n -->\n"
			. '<script>(RLQ=window.RLQ||[]).push(function(){mw.config.set('
			. "{\"wgBackendResponseTime\":$ms,\"wgHostname\":\"runner\"});});</script>\n"
			. "<!-- Cached $stamp -->\n</html>\n"
		);
	}

	/** One bake's stamps, as they really came out of two runs. */
	private function first(): string {
		return $this->page(
			'1f7c4e3d5fdc9ddb1edf5acc',
			'9f9a68fb-95a6-11f1-abf7-999c0f47f1e8',
			'20260811170350',
			629,
			34
		);
	}

	private function second(): string {
		return $this->page(
			'4d0af69e26d7d90bc2bc9c2a',
			'e18ba777-9404-11f1-b2fd-015fcea9a1a3',
			'20260809151331',
			631,
			28
		);
	}

	/** The property that matters: two bakes of one page come out as the same bytes. */
	public function testTwoBakesOfOnePageBecomeIdentical() {
		$first = $this->first();
		$second = $this->second();
		$this->assertNotSame($first, $second, 'the inputs differ to begin with');

		$this->assertSame(BuildStamps::strip($first), BuildStamps::strip($second));
	}

	public function testTheStampsAreGone() {
		$stripped = BuildStamps::strip($this->first());
		$this->assertStringNotContainsString('1f7c4e3d5fdc9ddb1edf5acc', $stripped);
		$this->assertStringNotContainsString('9f9a68fb', $stripped);
		$this->assertStringNotContainsString('20260811170350', $stripped);
		$this->assertStringNotContainsString('Render ID', $stripped);
		$this->assertStringNotContainsString('Saved in parser cache', $stripped);
		$this->assertStringNotContainsString('<!-- Cached', $stripped);
		$this->assertStringNotContainsString(':629', $stripped);
	}

	/** The page keeps its content, its structure and the config keys scripts read. */
	public function testThePageSurvives() {
		$stripped = BuildStamps::strip($this->first());
		$this->assertStringContainsString('<p>The page itself.</p>', $stripped);
		$this->assertStringContainsString('"wgPageName":"index"', $stripped);
		$this->assertStringContainsString('"wgIsArticle":true', $stripped);
		$this->assertStringContainsString('"wgHostname":"runner"', $stripped);
		$this->assertStringEndsWith("</html>\n", $stripped);
		// Blanked, not deleted: mw.config.get() would return null for a key that is not there.
		$this->assertStringContainsString('"wgArticleId":0', $stripped);
		$this->assertStringContainsString('"wgCurRevisionId":0', $stripped);
		$this->assertStringContainsString('"wgRevisionId":0', $stripped);
		$this->assertStringContainsString('"wgRequestId":""', $stripped);
		$this->assertStringContainsString('"wgBackendResponseTime":0', $stripped);
		$this->assertStringContainsString('"wgRelevantArticleId":0', $stripped);
		// The footer is not this class's business; the dates in it are frozen in the database.
		$this->assertStringContainsString('footer-info-copyright', $stripped);
	}

	public function testStrippingIsIdempotent() {
		$once = BuildStamps::strip($this->first());
		$this->assertSame($once, BuildStamps::strip($once));
	}

	/** A page carrying none of them is left exactly as it was. */
	public function testAPageWithoutStampsIsUnchanged() {
		$html = "<!DOCTYPE html>\n<html><body><p>Nothing to strip.</p></body></html>\n";
		$this->assertSame($html, BuildStamps::strip($html));
	}

	/** The compressed variant of the file cache comment is worded differently. */
	public function testTheCompressedFileCacheCommentIsAlsoDropped() {
		$html = "<html><body>x</body>\n<!-- Cached/compressed 20260811170350 -->\n</html>";
		$this->assertStringNotContainsString('Cached/compressed', BuildStamps::strip($html));
	}
}
