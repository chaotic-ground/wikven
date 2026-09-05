<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\SiteUrl;
use MediaWiki\Extension\Wikven\UploadReference;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\UploadReference
 */
class UploadReferenceTest extends MediaWikiUnitTestCase {
	/** Publishes every path as one asset, so a test can read what was written rather than which. */
	private static function published(): callable {
		return static function (string $path): ?string {
			return './assets/img-abc123def456.png';
		};
	}

	private static function references(string $siteUrl = 'https://example.org/wikven/'): UploadReference {
		return new UploadReference('/images', SiteUrl::fromWritten($siteUrl));
	}

	public function testAReferenceFromTheSiteRootIsAnsweredBesideThePage() {
		$this->assertSame(
			'<img src="./assets/img-abc123def456.png">',
			self::references()->rewrite('<img src="/images/Card.png">', self::published())
		);
	}

	/**
	 * The defect this class exists for. MediaWiki hands a head tag File::getFullUrl(), which is the
	 * body's URL expanded against $wgServer, so a scheme and host arriving here is MediaWiki saying
	 * this will be read away from the page. Answered with a path beside the page it means nothing:
	 * a crawler that never saw the page has nothing to resolve it against.
	 */
	public function testAWholeReferenceIsAnsweredWhole() {
		$this->assertSame(
			'<meta property="og:image" content="https://example.org/wikven/assets/img-abc123def456.png">',
			self::references()
				->rewrite(
					'<meta property="og:image" content="https://example.org/images/Card.png">',
					self::published()
				)
		);
	}

	public function testAProtocolRelativeReferenceIsWholeToo() {
		$this->assertSame(
			'https://example.org/wikven/assets/img-abc123def456.png',
			self::references()->rewrite('//example.org/images/Card.png', self::published())
		);
	}

	/**
	 * A site that has not said where it is published has no whole URL to be given, and the host in
	 * the reference is the machine that ran the build. Naming that is worse than naming nothing.
	 */
	public function testWithNoPublishedBaseAWholeReferenceFallsBackToThePathBesideThePage() {
		$this->assertSame(
			'./assets/img-abc123def456.png',
			self::references('')->rewrite('http://localhost:4000/images/Card.png', self::published())
		);
	}

	/**
	 * json_encode() escapes a slash unless told not to, and an extension writing a schema.org block
	 * calls it plainly. Matching only the bare spelling is how one page could carry a rewritten
	 * og:image and, for the same file, a JSON-LD image still naming the upload path.
	 */
	public function testAJsonEscapedReferenceIsMatchedAndAnsweredEscaped() {
		$this->assertSame(
			'"url":"https:\/\/example.org\/wikven\/assets\/img-abc123def456.png"',
			self::references()
				->rewrite(
					'"url":"https:\/\/example.org\/images\/Card.png"',
					self::published()
				)
		);
	}

	public function testAJsonEscapedReferenceFromTheSiteRootStaysRelativeAndEscaped() {
		$this->assertSame(
			'"url":".\/assets\/img-abc123def456.png"',
			self::references('')->rewrite('"url":"\/images\/Card.png"', self::published())
		);
	}

	public function testBothSpellingsAskAboutTheSamePath() {
		$asked = [];
		self::references()
			->rewrite(
				'<img src="/images/Card.png"> "url":"https:\/\/example.org\/images\/Card.png"',
				static function (string $path) use (&$asked): ?string {
					$asked[] = $path;
					return './assets/img-abc123def456.png';
				}
			);
		$this->assertSame(['/Card.png', '/Card.png'], $asked);
	}

	/** A page carries one picture at several sizes and cache-busting stamps; all are one file. */
	public function testTheQueryIsLeftOutOfThePathAndOutOfTheAnswer() {
		$asked = null;
		$out = self::references()
			->rewrite(
				'<meta content="https://example.org/images/Card.png?version=9f8e7d">',
				static function (string $path) use (&$asked): ?string {
					$asked = $path;
					return './assets/img-abc123def456.png';
				}
			);
		$this->assertSame('/Card.png', $asked);
		$this->assertSame(
			'<meta content="https://example.org/wikven/assets/img-abc123def456.png">',
			$out
		);
	}

	public function testAPathOfSeveralSegmentsIsOnePath() {
		$asked = null;
		self::references()
			->rewrite(
				'<img src="/images/thumb/Card.png/100px-Card.png">',
				static function (string $path) use (&$asked): ?string {
					$asked = $path;
					return './assets/img-abc123def456.png';
				}
			);
		$this->assertSame('/thumb/Card.png/100px-Card.png', $asked);
	}

	/**
	 * {{filepath:}} writes its URL into the autolink's text as well as into the href, and nothing
	 * quotes the text. A path that admits "<" runs on into the tag after it, and the build aborts
	 * over "/Card.png</a>", a file that was never referenced.
	 */
	public function testAnAutolinkedUrlDoesNotSwallowTheTagAfterIt() {
		$asked = null;
		$out = self::references()
			->rewrite(
				'<a href="https://example.org/images/Card.png" class="external free">'
				. 'https://example.org/images/Card.png</a>',
				static function (string $path) use (&$asked): ?string {
					$asked = $path;
					return './assets/img-abc123def456.png';
				}
			);
		$this->assertSame('/Card.png', $asked);
		$this->assertStringEndsWith('</a>', $out);
	}

	public function testAReferenceThatCouldNotBePublishedIsLeftAsThePageWroteIt() {
		$html = '<img src="/images/Missing.png">';
		$this->assertSame(
			$html,
			self::references()
				->rewrite($html, static function (string $path): ?string {
					return null;
				})
		);
	}

	public function testAUrlOutsideTheUploadPathIsNotOurs() {
		$html = '<img src="https://example.com/elsewhere/Card.png">';
		$this->assertSame($html, self::references()->rewrite($html, self::published()));
	}
}
