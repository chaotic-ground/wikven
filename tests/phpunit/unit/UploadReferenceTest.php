<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Output\UploadReference;
use MediaWiki\Extension\Wikven\SiteUrl;
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
		return UploadReference::stored('/images', SiteUrl::fromWritten($siteUrl));
	}

	private static function hotlinks(string $siteUrl = 'https://example.org/wikven/'): UploadReference {
		return UploadReference::hotlinked('upload.wikimedia.org', SiteUrl::fromWritten($siteUrl));
	}

	/** A page carrying one reference in the head, so a test can say where it sits and read it back. */
	private static function inHead(string $reference): string {
		return "<html><head><meta property=\"og:image\" content=\"$reference\"></head><body></body></html>";
	}

	public function testAReferenceFromTheSiteRootIsAnsweredBesideThePage() {
		$this->assertSame(
			'<img src="./assets/img-abc123def456.png">',
			self::references()->rewrite('<img src="/images/Card.png">', self::published())
		);
	}

	/**
	 * The defect this class exists for. MediaWiki hands a head tag File::getFullUrl(), so a scheme
	 * and host arriving here is MediaWiki saying this will be read away from the page.
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
	 * json_encode() escapes a slash unless told not to. Matching only the bare spelling is how one
	 * page could carry a rewritten og:image and a JSON-LD image still naming the upload path.
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
	 * {{filepath:}} writes its URL into the autolink's text as well as the href, and nothing quotes
	 * the text, so a path admitting "<" runs on into the tag after it.
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

	/**
	 * A stored picture named from the head arrives whole, so the shape has always been enough. It
	 * is answered by where it sits as well, because that is the rule the shape stands in for.
	 */
	public function testAReferenceFromTheSiteRootInTheHeadIsAnsweredWhole() {
		$this->assertSame(
			self::inHead('https://example.org/wikven/assets/img-abc123def456.png'),
			self::references()->rewrite(self::inHead('/images/Card.png'), self::published())
		);
	}

	/** The head's other references are fetched by the browser rendering the page, like the body's. */
	public function testAFaviconInTheHeadIsAnsweredBesideThePage() {
		$this->assertSame(
			'<html><head><link rel="icon" href="./assets/img-abc123def456.png"></head></html>',
			self::references()
				->rewrite(
					'<html><head><link rel="icon" href="/images/Logo.png"></head></html>',
					self::published()
				)
		);
	}

	/**
	 * The defect this half exists for. A foreign repository's file is a whole URL wherever it is
	 * named, so answering the head's copy beside the page left an og:image nothing can resolve.
	 */
	public function testAHotlinkInTheHeadIsAnsweredWhole() {
		$this->assertSame(
			self::inHead('https://example.org/wikven/assets/img-abc123def456.png'),
			self::hotlinks()
				->rewrite(
					self::inHead('https://upload.wikimedia.org/wikipedia/commons/1/12/Oven.jpg'),
					self::published()
				)
		);
	}

	/** And the body's copy stays beside the page, which is what makes the export a movable directory. */
	public function testAHotlinkInTheBodyIsAnsweredBesideThePage() {
		$oven = 'https://upload.wikimedia.org/wikipedia/commons/1/12/Oven.jpg';
		$this->assertSame(
			'<html><body><img src="./assets/img-abc123def456.png"></body></html>',
			self::hotlinks()->rewrite("<html><body><img src=\"$oven\"></body></html>", self::published())
		);
	}

	/**
	 * The spelling a schema.org block writes. Nothing matched it, so a page could carry a rewritten
	 * og:image beside a JSON-LD image still hotlinking the repository.
	 */
	public function testAJsonEscapedHotlinkIsMatchedAndAnsweredEscaped() {
		$html = '<html><head><script type="application/ld+json">{"url":"%s"}</script></head></html>';
		$this->assertSame(
			sprintf($html, 'https:\/\/example.org\/wikven\/assets\/img-abc123def456.png'),
			self::hotlinks()
				->rewrite(
					sprintf($html, 'https:\/\/upload.wikimedia.org\/wikipedia\/commons\/1\/12\/Oven.jpg'),
					self::published()
				)
		);
	}

	public function testBothSpellingsOfAHotlinkAskAboutTheSameUrl() {
		$asked = [];
		self::hotlinks()
			->rewrite(
				'<img src="//upload.wikimedia.org/wikipedia/commons/1/12/Oven.jpg"> '
				. '"url":"https:\/\/upload.wikimedia.org\/wikipedia\/commons\/1\/12\/Oven.jpg"',
				static function (string $url) use (&$asked): ?string {
					$asked[] = $url;
					return './assets/img-abc123def456.png';
				}
			);
		$this->assertSame(
			[
				'//upload.wikimedia.org/wikipedia/commons/1/12/Oven.jpg',
				'https://upload.wikimedia.org/wikipedia/commons/1/12/Oven.jpg'
			],
			$asked
		);
	}

	/**
	 * A repository's thumbnailer answers the URL it was given, so the query is part of what the
	 * page asked for rather than a stamp on a file the build already has.
	 */
	public function testAHotlinkKeepsItsQueryInWhatIsAskedAbout() {
		$asked = null;
		self::hotlinks()
			->rewrite(
				'<img src="https://upload.wikimedia.org/wikipedia/commons/thumb/Oven.jpg?w=100">',
				static function (string $url) use (&$asked): ?string {
					$asked = $url;
					return './assets/img-abc123def456.png';
				}
			);
		$this->assertSame('https://upload.wikimedia.org/wikipedia/commons/thumb/Oven.jpg?w=100', $asked);
	}

	/** The autolink defect the stored half already knew about; a hotlinked URL is autolinked too. */
	public function testAnAutolinkedHotlinkDoesNotSwallowTheTagAfterIt() {
		$url = 'https://upload.wikimedia.org/wikipedia/commons/1/12/Oven.jpg';
		$out = self::hotlinks()
			->rewrite(
				"<a href=\"$url\" class=\"external free\">$url</a>",
				self::published()
			);
		$local = './assets/img-abc123def456.png';
		$this->assertSame("<a href=\"$local\" class=\"external free\">$local</a>", $out);
	}

	/** A site that has not said where it is published has no whole URL to give the head either. */
	public function testWithNoPublishedBaseAHotlinkInTheHeadFallsBackToThePathBesideThePage() {
		$this->assertSame(
			self::inHead('./assets/img-abc123def456.png'),
			self::hotlinks('')
				->rewrite(
					self::inHead('https://upload.wikimedia.org/wikipedia/commons/1/12/Oven.jpg'),
					self::published()
				)
		);
	}

	public function testAHotlinkThatCouldNotBeDownloadedIsLeftAsThePageWroteIt() {
		$html = self::inHead('https://upload.wikimedia.org/wikipedia/commons/1/12/Gone.jpg');
		$this->assertSame(
			$html,
			self::hotlinks()
				->rewrite($html, static function (string $url): ?string {
					return null;
				})
		);
	}

	public function testAUrlAtAnotherHostIsNotOurs() {
		$html = '<img src="https://example.com/wikipedia/commons/1/12/Oven.jpg">';
		$this->assertSame($html, self::hotlinks()->rewrite($html, self::published()));
	}
}
