<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Output\Stylesheet;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Output\Stylesheet
 */
class StylesheetTest extends MediaWikiUnitTestCase {
	/** One rule, so a written file has bytes worth reading back. */
	private const CSS = ".mw-body { color: #000; }\n";

	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->directory = sys_get_temp_dir() . '/wikven-stylesheet-' . getmypid() . '-' . uniqid();
		mkdir($this->directory, 0777, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->directory . '/*') as $file) {
			unlink($file);
		}
		if (is_dir($this->directory)) {
			rmdir($this->directory);
		}
		parent::tearDown();
	}

	public function testAStylesheetThatReachedTheDiskIsNoProblem() {
		$file = $this->directory . '/skins.vector.styles.css';

		$this->assertNull(Stylesheet::write($file, self::CSS));
		$this->assertSame(self::CSS, file_get_contents($file));
	}

	/**
	 * The one that used to go unnoticed: a bake that could not write its CSS left the site
	 * unstyled, said so only to a debug log wikven never configures, and exited 0 anyway.
	 */
	public function testAStylesheetThatCouldNotBeWrittenIsAProblemNamingTheFile() {
		// No such directory, which refuses to open for writing the way a read-only export
		// directory does.
		$file = $this->directory . '/gone/skins.vector.styles.css';

		$problem = $this->writeQuietly($file, self::CSS);

		$this->assertNotNull($problem, 'a stylesheet that never landed has to be reported');
		$this->assertStringContainsString($file, $problem);
	}

	public function testAPageInTheSitesDirectionLinksTheSitesStylesheet() {
		$this->assertSame('skins.vector.styles.css', Stylesheet::fileName('skins.vector.styles', 'ltr', 'ltr'));
		$this->assertSame('skins.vector.styles.css', Stylesheet::fileName('skins.vector.styles', 'rtl', 'rtl'));
	}

	/** An Arabic translation on an English site, and an English one on an Arabic site. */
	public function testAPageReadTheOtherWayLinksACopyNamedForItsDirection() {
		$this->assertSame('skins.vector.styles.rtl.css', Stylesheet::fileName('skins.vector.styles', 'rtl', 'ltr'));
		$this->assertSame('skins.vector.styles.ltr.css', Stylesheet::fileName('skins.vector.styles', 'ltr', 'rtl'));
	}

	public function testTheSitesOwnStylesheetIsItsModuleInNoParticularDirection() {
		$this->assertSame(
			['module' => 'skins.vector.styles', 'direction' => null],
			Stylesheet::module('/out/assets/skins.vector.styles.css', $this->modules('skins.vector.styles'))
		);
	}

	public function testADirectionalCopyIsItsModuleInThatDirection() {
		$isModule = $this->modules('skins.vector.styles');

		$this->assertSame(
			['module' => 'skins.vector.styles', 'direction' => 'rtl'],
			Stylesheet::module('/out/assets/skins.vector.styles.rtl.css', $isModule)
		);
		$this->assertSame(
			['module' => 'skins.vector.styles', 'direction' => 'ltr'],
			Stylesheet::module('skins.vector.styles.ltr.css', $isModule)
		);
	}

	/** A name only looks like a copy; a module of that very name is the module itself. */
	public function testAModuleWhoseNameEndsInADirectionIsThatModule() {
		$this->assertSame(
			['module' => 'ext.example.rtl', 'direction' => null],
			Stylesheet::module('ext.example.rtl.css', $this->modules('ext.example', 'ext.example.rtl'))
		);
	}

	/**
	 * @param string ...$names The modules ResourceLoader has.
	 * @return callable(string):bool
	 */
	private function modules(string ...$names): callable {
		return static function (string $name) use ($names): bool {
			return in_array($name, $names, true);
		};
	}

	/**
	 * A failing write warns, and a build wants to see that warning; a test that asks for the
	 * failure on purpose does not, and PHPUnit would otherwise make the expected one a failure.
	 */
	private function writeQuietly(string $filename, string $text): ?string {
		set_error_handler(static function (): bool {
			return true;
		});
		try {
			return Stylesheet::write($filename, $text);
		} finally {
			restore_error_handler();
		}
	}
}
