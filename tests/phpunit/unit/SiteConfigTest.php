<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\BuildFor;
use MediaWiki\Extension\Wikven\SiteConfig;
use MediaWikiUnitTestCase;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\Wikven\SiteConfig
 */
class SiteConfigTest extends MediaWikiUnitTestCase {
	public function testSoundFileHasNoWarnings() {
		$data = [
			'config' => ['WikvenEditUrl' => 'https://example.org/edit/$1', 'Sitename' => 'S'],
			'extensions' => ['Foo'],
			'skins' => ['Vector']
		];
		$this->assertSame([], SiteConfig::lint($data));
	}

	/**
	 * The three paths the build derives from WIKVEN_WORKDIR. Setting one used to survive
	 * $wgSettings->apply() and silently move where pages were read from, while the config file
	 * itself was still looked for beside the workdir.
	 */
	public function testAPathTheBuildDerivesForItselfWarns() {
		foreach (SiteConfig::DERIVED_CONFIG as $derived) {
			$warnings = SiteConfig::lint(['config' => [$derived => '/elsewhere']]);
			$this->assertCount(1, $warnings, "expected one warning for '$derived'");
			$this->assertStringContainsString("'$derived' is worked out from WIKVEN_WORKDIR", $warnings[0]);
		}
	}

	/** A derived key is a real Wikven variable, so it must not also be reported as a typo. */
	public function testADerivedPathIsNotReportedAsAnUnknownVariable() {
		$warnings = SiteConfig::lint(['config' => ['WikvenSourceDirectory' => '/elsewhere']]);
		$this->assertCount(1, $warnings);
		$this->assertStringNotContainsString('typo', $warnings[0]);
	}

	public function testAConformingConfigHasNoSchemaErrors() {
		$this->assertSame([], SiteConfig::schemaErrors(StatusValue::newGood()));
	}

	public function testASchemaErrorNamesTheSettingAndTheReason() {
		$status = StatusValue::newGood();
		$status->fatal('config-invalid-value', 'Sitename', 'expected string, got array');
		$errors = SiteConfig::schemaErrors($status);
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('Sitename', $errors[0]);
		$this->assertStringContainsString('expected string, got array', $errors[0]);
	}

	/**
	 * The validator warns that it skipped a map with integer keys, which no site can act on. Every
	 * build sets such a map, so reporting warnings would put a line in every build log.
	 */
	public function testAValidatorWarningIsNotReportedAsAnError() {
		$status = StatusValue::newGood();
		$status->warning('config-invalid-key', 'WikvenLogos', 'Skipping validation of object with integer keys');
		$this->assertSame([], SiteConfig::schemaErrors($status));
	}

	public function testEveryAudienceBuildForKnowsPassesLint() {
		foreach (BuildFor::all() as $audience) {
			$this->assertSame([], SiteConfig::lint(['config' => ['WikvenBuildFor' => $audience]]));
		}
	}

	/**
	 * BuildFor reads a value it does not know as a site, which is the safe reading but a silent
	 * one. A near miss is exactly what a person wants told back to them.
	 */
	public function testAnAudienceBuildForDoesNotKnowIsNamed() {
		$warnings = SiteConfig::lint(['config' => ['WikvenBuildFor' => 'sit']]);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("'WikvenBuildFor' is 'sit'", $warnings[0]);
		$this->assertStringContainsString('skin-preview', $warnings[0]);
	}

	/** A value of the wrong kind is named by its type, so "true" does not read as the string 1. */
	public function testAnAudienceOfTheWrongTypeIsNamedByItsType() {
		$warnings = SiteConfig::lint(['config' => ['WikvenBuildFor' => true]]);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('is bool;', $warnings[0]);
	}

	public function testUrlTemplateMissingPlaceholderWarns() {
		$warnings = SiteConfig::lint(['config' => ['WikvenEditUrl' => 'https://example.org/edit']]);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("'WikvenEditUrl' should be a URL template containing", $warnings[0]);
	}

	public function testNonMapValueWarns() {
		$warnings = SiteConfig::lint(['config' => ['WikvenLogos' => 'logo.png']]);
		$this->assertContains("'WikvenLogos' must be a map.", $warnings);
	}

	public function testNonMapIsRejected() {
		$this->assertSame(['the file is not a map; ignoring it.'], SiteConfig::lint('oops'));
	}

	public function testUnknownTopLevelKeyWarns() {
		$warnings = SiteConfig::lint(['extension' => ['Foo']]);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("unknown top-level key 'extension'", $warnings[0]);
	}

	public function testWrongTypedListWarns() {
		$warnings = SiteConfig::lint(['extensions' => 'Foo']);
		$this->assertContains("'extensions' must be a list.", $warnings);
	}

	/**
	 * Whose a name is takes reading the manifests of everything queued, which is not something
	 * lint() is in a position to do: it runs while a site's file is being read, and the extensions
	 * that would answer are still queued. undefinedConfig() below is where that question is asked.
	 */
	public function testLintDoesNotJudgeWhetherAConfigNameExists() {
		$this->assertSame([], SiteConfig::lint(['config' => ['WikvenFooterURL' => 'x']]));
		$this->assertSame([], SiteConfig::lint(['config' => ['Sitename' => 'S', 'Localtimezone' => 'UTC']]));
	}

	public function testAPlainDirectoryNameIsAComponentName() {
		$this->assertTrue(SiteConfig::isComponentName('TabberNeue'));
		$this->assertTrue(SiteConfig::isComponentName('citizen'));
	}

	public function testANameThatEscapesTheImageIsNotAComponentName() {
		// The one that matters: the loader would resolve this under the mounted source tree and run
		// whatever extension.json it found there, with nothing declaring where the code came from.
		$this->assertFalse(SiteConfig::isComponentName('../../../workspace/src/payload'));
		$this->assertFalse(SiteConfig::isComponentName('vendor/payload'));
		$this->assertFalse(SiteConfig::isComponentName('vendor\\payload'));
	}

	public function testADotLeadingOrEmptyNameIsNotAComponentName() {
		$this->assertFalse(SiteConfig::isComponentName('.'));
		$this->assertFalse(SiteConfig::isComponentName('..'));
		$this->assertFalse(SiteConfig::isComponentName('.hidden'));
		$this->assertFalse(SiteConfig::isComponentName(''));
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

	public function testManifestConfigNamesCollectsEveryNameDeclared() {
		$names = SiteConfig::manifestConfigNames([
			$this->makeManifest([
				'config' => ['SifterSearchOutputDir' => ['value' => ''], 'SifterSearchBinary' => ['value' => '']]
			]),
			$this->makeManifest(['config' => ['CitizenEnableManifest' => ['value' => true]]])
		]);
		$this->assertSame(
			['SifterSearchOutputDir', 'SifterSearchBinary', 'CitizenEnableManifest'],
			array_keys($names)
		);
	}

	/**
	 * A site's file reaches globals through $wgSettings, which writes the "wg" prefix and no other,
	 * so a setting behind a different prefix cannot be written from one at all. Counting it as
	 * known would tell a site that wrote it that the line was taken.
	 */
	public function testManifestConfigNamesSkipsAManifestBehindAnotherPrefix() {
		$this->assertSame(
			[],
			SiteConfig::manifestConfigNames([
				$this->makeManifest(['config_prefix' => 'eg', 'config' => ['Something' => ['value' => 1]]])
			])
		);
		$this->assertSame(
			[],
			SiteConfig::manifestConfigNames([
				$this->makeManifest(['config' => ['_prefix' => 'eg', 'Something' => 1]])
			])
		);
	}

	/** manifest_version 1 declares the prefix inside the map; the names beside it are still names. */
	public function testManifestConfigNamesReadsAVersionOneManifest() {
		$names = SiteConfig::manifestConfigNames([
			$this->makeManifest(['config' => ['_prefix' => 'wg', 'FooBar' => 1]])
		]);
		$this->assertSame(['FooBar'], array_keys($names));
	}

	public function testManifestConfigNamesIgnoresAnUnreadableOrConfiglessManifest() {
		$this->assertSame(
			[],
			SiteConfig::manifestConfigNames([
				'/nonexistent/extension.json',
				$this->makeManifest(['name' => 'NoSettingsHere'])
			])
		);
	}

	public function testANameSomethingDefinesIsNotReported() {
		$this->assertSame(
			[],
			SiteConfig::undefinedConfig(['Sitename', 'WikvenEditUrl'], ['Sitename', 'WikvenEditUrl'])
		);
	}

	/**
	 * The whole point: a name nobody defines is written into a global that nothing reads, and the
	 * build then succeeds having ignored the line.
	 */
	public function testANameNothingDefinesIsReportedWithItsNearestMatch() {
		$warnings = SiteConfig::undefinedConfig(['EnableUpoads'], ['EnableUploads', 'Sitename']);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("unknown config 'EnableUpoads'", $warnings[0]);
		$this->assertStringContainsString("Did you mean 'EnableUploads'?", $warnings[0]);
	}

	/** Nothing is close, so nothing is suggested: a wrong suggestion sends a site off reading. */
	public function testANameLikeNothingDefinedIsReportedWithoutASuggestion() {
		$warnings = SiteConfig::undefinedConfig(['Nonsense'], ['EnableUploads', 'Sitename']);
		$this->assertCount(1, $warnings);
		$this->assertStringNotContainsString('Did you mean', $warnings[0]);
	}

	/** Two candidates are near; the nearer one is the one worth naming. */
	public function testTheNearestOfSeveralCandidatesIsSuggested() {
		$warnings = SiteConfig::undefinedConfig(
			['WikvenFooterUrl'],
			['WikvenFooterURL', 'WikvenFooterText']
		);
		$this->assertStringContainsString("Did you mean 'WikvenFooterURL'?", $warnings[0]);
	}

	/**
	 * A short name reaches an unrelated one in very few edits, so the allowance grows with length:
	 * "Foo" must not be answered with "Bar".
	 */
	public function testAShortNameIsNotMatchedToAnUnrelatedOne() {
		$warnings = SiteConfig::undefinedConfig(['Foo'], ['Bar']);
		$this->assertStringNotContainsString('Did you mean', $warnings[0]);
	}

	/**
	 * @param array $manifest Decoded manifest contents.
	 * @return string Path to a file holding it.
	 */
	private function makeManifest(array $manifest): string {
		$path = sys_get_temp_dir() . '/wikven-manifest-' . uniqid() . '.json';
		file_put_contents($path, json_encode($manifest));
		$this->filesToClean[] = $path;
		return $path;
	}

	/** @var string[] */
	private array $filesToClean = [];

	private function makeTempDir(): string {
		$dir = sys_get_temp_dir() . '/wikven-locate-' . uniqid();
		mkdir($dir);
		$this->dirsToClean[] = $dir;
		return $dir;
	}

	/** @var string[] */
	private array $dirsToClean = [];

	protected function tearDown(): void {
		foreach ($this->filesToClean as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
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
