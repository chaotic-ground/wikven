<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Stylesheet;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Stylesheet
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
