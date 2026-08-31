<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\OutputName;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\OutputName
 */
class OutputNameTest extends MediaWikiUnitTestCase {
	/** Namespace numbers as the wiki this file describes has them. */
	private const NAMESPACES = ['' => 0, 'File' => 6, 'Help' => 12, '도움말' => 12];

	protected function tearDown(): void {
		unset($GLOBALS['wgWikvenFileNames']);
		parent::tearDown();
	}

	/**
	 * The defect this class exists for. A title made only of letters agreed by luck; anything else
	 * had the build write one name and every link say another, and the reader got a 404 out of a
	 * build that exited 0.
	 *
	 * @dataProvider provideReadable
	 */
	public function testAReadableNameIsTheTitleAsWritten(string $namespace, string $dbkey, string $file) {
		$this->assertSame($file, OutputName::of($namespace, $dbkey, OutputName::READABLE));
	}

	public static function provideReadable(): array {
		return [
			'letters agree either way' => ['', 'Getting_Started', 'Getting_Started.html'],
			'the parentheses that started this' => ['', 'Vector_(skin)', 'Vector_(skin).html'],
			'a namespace keeps its colon' => ['File', 'Bakery_oven.jpg', 'File:Bakery_oven.jpg.html'],
			'a subpage is a directory' => ['', 'Manual/Config', 'Manual/Config.html'],
			'an ampersand stands' => ['', 'Q&A', 'Q&A.html'],
			'a plus stands' => ['', 'C++', 'C++.html'],
			'a percent stands' => ['', '50%_done', '50%_done.html'],
			'a Korean title stands' => ['', '설치', '설치.html'],
			'a Korean namespace stands too' => ['도움말', '설치', '도움말:설치.html'],
			// The four a Windows path cannot hold, other than the colon and the slash.
			'a question mark stays escaped' => ['', 'What?', 'What%3F.html'],
			'a quote stays escaped' => ['', 'A"B', 'A%22B.html'],
			'an asterisk stays escaped' => ['', 'A*B', 'A%2AB.html'],
			'a backslash stays escaped' => ['', 'A\\B', 'A%5CB.html']
		];
	}

	/**
	 * What a site on a filesystem that cannot hold a colon asks for: every escape the file cache
	 * made, left where it is.
	 *
	 * @dataProvider provideEncoded
	 */
	public function testAnEncodedNameKeepsWhatTheCacheEscaped(string $namespace, string $dbkey, string $file) {
		$this->assertSame($file, OutputName::of($namespace, $dbkey, OutputName::ENCODED));
	}

	public static function provideEncoded(): array {
		return [
			'letters agree either way' => ['', 'Getting_Started', 'Getting_Started.html'],
			'the colon is the point' => ['File', 'Bakery_oven.jpg', 'File%3ABakery_oven.jpg.html'],
			'parentheses stay escaped' => ['', 'Vector_(skin)', 'Vector_%28skin%29.html'],
			// A subpage is a real directory under both, or nothing could count a page's depth.
			'a subpage is still a directory' => ['', 'Manual/Config', 'Manual/Config.html'],
			// As is the extension: the cache escapes every dot, and a name with none cannot end .html.
			'the dot in a name comes back' => ['', 'File.svg', 'File.svg.html'],
			// The namespace is escaped as the body is, or the name comes out half one and half the
			// other -- and the two directions below stop agreeing on what the file is called.
			'a Korean namespace is escaped too' => [
				'도움말',
				'설치',
				'%EB%8F%84%EC%9B%80%EB%A7%90%3A%EC%84%A4%EC%B9%98.html'
			]
		];
	}

	/**
	 * The relationship that makes a link work: a static server url-decodes the path it is asked
	 * for, so the link is the name url-encoded and nothing else. Escaping the link to look like the
	 * name -- which is what the build used to do by accident -- asks for a different file.
	 *
	 * @dataProvider provideHrefs
	 */
	public function testALinkIsTheNameUrlEncoded(string $file, string $href) {
		$this->assertSame($href, OutputName::href($file));
		$this->assertSame($file, OutputName::file($href), 'and reads back to the name it came from');
	}

	public static function provideHrefs(): array {
		return [
			'nothing to escape' => ['Getting_Started.html', 'Getting_Started.html'],
			'parentheses need none' => ['Vector_(skin).html', 'Vector_(skin).html'],
			'a colon needs none' => ['File:Bakery_oven.jpg.html', 'File:Bakery_oven.jpg.html'],
			'an ampersand needs none in a path' => ['Q&A.html', 'Q&A.html'],
			'a slash is a path separator' => ['Manual/Config.html', 'Manual/Config.html'],
			// The three that cannot stand in a url path, and the doubling that follows from the first.
			'a percent would start an escape' => ['50%_done.html', '50%25_done.html'],
			'a kept escape is escaped again' => ['What%3F.html', 'What%253F.html'],
			'an encoded name doubles throughout' => ['File%3ALogo.png.html', 'File%253ALogo.png.html'],
			'a question mark would start a query' => ['What?.html', 'What%3F.html'],
			'a hash would start a fragment' => ['A#B.html', 'A%23B.html']
		];
	}

	/**
	 * The two directions have to agree or the defect is back: rename.php reads the cache directory
	 * and has no titles, every link is written from a title, and only their agreeing makes a page
	 * reachable.
	 *
	 * @dataProvider provideBothSchemes
	 */
	public function testBothDirectionsNameTheSameFile(string $scheme) {
		foreach (self::everyTitle() as $case => [$namespace, $dbkey]) {
			$cached = self::asTheCacheWritesIt(self::NAMESPACES[$namespace], $dbkey);
			$this->assertSame(
				OutputName::of($namespace, $dbkey, $scheme),
				OutputName::fromCache($cached, self::alwaysNamespace($namespace), $scheme),
				"$case, $scheme"
			);
		}
	}

	public static function provideBothSchemes(): array {
		return ['readable' => [OutputName::READABLE], 'encoded' => [OutputName::ENCODED]];
	}

	/**
	 * A prefixed name handed in whole names the file its namespace and dbkey name apart.
	 *
	 * The Special:MyLanguage marker is built that way: the target's prefixed name rides inside the
	 * marker's own dbkey (Hooks\Adder::licensesHref, and any link through GetLocalURL), and
	 * resolveTranslationLinks.php then looks for the file that name spells. So the two ways of
	 * spelling one title have to land on one file, which is only true while the namespace is
	 * escaped exactly as the rest of the name is.
	 *
	 * @dataProvider provideBothSchemes
	 */
	public function testAPrefixedNameSpellsTheFileItsPartsDo(string $scheme) {
		foreach ([['File', 'Logo.png'], ['Help', '설치'], ['도움말', '설치'], ['도움말', 'Licenses']] as $title) {
			[$namespace, $dbkey] = $title;
			$this->assertSame(
				OutputName::of($namespace, $dbkey, $scheme),
				OutputName::of('', "$namespace:$dbkey", $scheme),
				"$namespace:$dbkey, $scheme"
			);
		}
	}

	/** Nothing the file cache wrote, so nothing to rename: left exactly as it is. */
	public function testANameTheCacheDidNotWriteIsUntouched() {
		$namespaceText = self::alwaysNamespace('Help');

		$this->assertSame('index.html', OutputName::fromCache('index.html', $namespaceText));
		$this->assertSame('assets', OutputName::fromCache('assets', $namespaceText));
		$this->assertSame('nsx%3AFoo.html', OutputName::fromCache('nsx%3AFoo.html', $namespaceText));
	}

	public function testASiteThatSaysNothingGetsReadableNames() {
		unset($GLOBALS['wgWikvenFileNames']);

		$this->assertSame(OutputName::READABLE, OutputName::current());
		$this->assertSame('File:Logo.png.html', OutputName::of('File', 'Logo.png'));
	}

	public function testTheEncodedSpellingIsRecognised() {
		$GLOBALS['wgWikvenFileNames'] = OutputName::ENCODED;

		$this->assertSame(OutputName::ENCODED, OutputName::current());
		$this->assertSame('File%3ALogo.png.html', OutputName::of('File', 'Logo.png'));
	}

	/**
	 * Reading an unknown value as readable is what a site that said nothing would have got, and
	 * SiteConfig::lint() has already named it by then.
	 */
	public function testAnUnrecognisedSpellingReadsAsReadable() {
		foreach (['READABLE', 'raw', '', true, 1, ['encoded']] as $written) {
			$GLOBALS['wgWikvenFileNames'] = $written;
			$this->assertSame(OutputName::READABLE, OutputName::current());
		}
	}

	/**
	 * @return array<string, array{string, string}> case => [namespace text, dbkey]
	 */
	private static function everyTitle(): array {
		$titles = [];
		foreach ([...self::provideReadable(), ...self::provideEncoded()] as $case => [$namespace, $dbkey]) {
			$titles[$case] = [$namespace, $dbkey];
		}
		// Not a Special: page: it is never cached to a file, so there is no cache name to agree
		// with. What it names is a marker resolveMyLanguage() rewrites, and that is tested there.
		$titles['a namespaced subpage'] = ['Help', 'Manual/Deep/Config'];
		return $titles;
	}

	/**
	 * A namespace-number-to-text callback that answers the same thing whatever it is asked.
	 *
	 * @return callable(int):string
	 */
	private static function alwaysNamespace(string $text): callable {
		return static function (int $namespace) use ($text): string {
			return $text;
		};
	}

	/** FileCacheBase::cachePath(): url-encode "ns<N>:<dbkey>", escape every dot, add the extension. */
	private static function asTheCacheWritesIt(int $namespace, string $dbkey): string {
		return str_replace('.', '%2E', urlencode("ns$namespace:$dbkey")) . '.html';
	}
}
