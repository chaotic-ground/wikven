<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\SiteConfig;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SiteConfig
 */
class SiteConfigTest extends MediaWikiUnitTestCase {
	private const KNOWN = ['WikvenFooterUrl', 'WikvenEditUrl', 'WikvenMainPage'];

	public function testSoundFileHasNoWarnings() {
		$data = [
			'config' => ['WikvenEditUrl' => 'https://example.org/edit/$1', 'Sitename' => 'S'],
			'extensions' => ['Foo'],
			'skins' => ['Vector']
		];
		$this->assertSame([], SiteConfig::lint($data, self::KNOWN));
	}

	public function testUrlTemplateMissingPlaceholderWarns() {
		$warnings = SiteConfig::lint(['config' => ['WikvenEditUrl' => 'https://example.org/edit']], self::KNOWN);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("'WikvenEditUrl' should be a URL template containing", $warnings[0]);
	}

	public function testNonMapValueWarns() {
		$warnings = SiteConfig::lint(['config' => ['WikvenLogos' => 'logo.png']], self::KNOWN);
		$this->assertContains("'WikvenLogos' must be a map.", $warnings);
	}

	public function testNonMapIsRejected() {
		$this->assertSame(['the file is not a map; ignoring it.'], SiteConfig::lint('oops', self::KNOWN));
	}

	public function testUnknownTopLevelKeyWarns() {
		$warnings = SiteConfig::lint(['extension' => ['Foo']], self::KNOWN);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("unknown top-level key 'extension'", $warnings[0]);
	}

	public function testWrongTypedListWarns() {
		$warnings = SiteConfig::lint(['extensions' => 'Foo'], self::KNOWN);
		$this->assertContains("'extensions' must be a list.", $warnings);
	}

	public function testMisspelledWikvenVariableWarns() {
		$warnings = SiteConfig::lint(['config' => ['WikvenFooterURL' => 'x']], self::KNOWN);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("unknown config 'WikvenFooterURL'", $warnings[0]);
	}

	public function testNonWikvenConfigKeyIsNotFlagged() {
		$this->assertSame(
			[],
			SiteConfig::lint(['config' => ['Sitename' => 'S', 'Localtimezone' => 'UTC']], self::KNOWN)
		);
	}

	public function testLocateFindsNothingInEmptyDir() {
		$dir = $this->makeTempDir();
		$this->assertSame(['path' => null, 'ignored' => []], SiteConfig::locate($dir));
	}

	public function testLocateReturnsTheSingleFilePresent() {
		$dir = $this->makeTempDir();
		touch("$dir/wikven.yml");
		$located = SiteConfig::locate($dir);
		$this->assertSame("$dir/wikven.yml", $located['path']);
		$this->assertSame([], $located['ignored']);
	}

	public function testLocatePrefersHigherPrecedenceAndIgnoresTheRest() {
		$dir = $this->makeTempDir();
		// Lowest, a middle one, and the highest-precedence name, out of order.
		touch("$dir/wikven.json");
		touch("$dir/.wikven.yml");
		touch("$dir/.wikven.yaml");
		$located = SiteConfig::locate($dir);
		$this->assertSame("$dir/.wikven.yaml", $located['path']);
		$this->assertSame(["$dir/.wikven.yml", "$dir/wikven.json"], $located['ignored']);
	}

	private function makeTempDir(): string {
		$dir = sys_get_temp_dir() . '/wikven-locate-' . uniqid();
		mkdir($dir);
		$this->dirsToClean[] = $dir;
		return $dir;
	}

	/** @var string[] */
	private array $dirsToClean = [];

	protected function tearDown(): void {
		foreach ($this->dirsToClean as $dir) {
			foreach (SiteConfig::CONFIG_FILENAMES as $name) {
				if (is_file("$dir/$name")) {
					unlink("$dir/$name");
				}
			}
			rmdir($dir);
		}
		parent::tearDown();
	}
}
