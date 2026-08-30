<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\ComposerInstall;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\ComposerInstall
 */
class ComposerInstallTest extends MediaWikiUnitTestCase {
	/** @var string[] */
	private array $treesToClean = [];

	/** @dataProvider provideIsExactVersion */
	public function testIsExactVersion(string $constraint, bool $expected) {
		$this->assertSame($expected, ComposerInstall::isExactVersion($constraint));
	}

	public static function provideIsExactVersion(): array {
		return [
			'a release' => ['4.1.0', true],
			'a release with composer\'s fourth part' => ['4.1.0.0', true],
			'a tagged release' => ['v4.1.0', true],
			'a prerelease' => ['4.1.0-beta.2', true],
			// Every one of these can install different code tomorrow.
			'a caret range' => ['^4.1', false],
			'a tilde range' => ['~4.1', false],
			'anything at all' => ['*', false],
			'a wildcard patch' => ['4.1.*', false],
			'a branch' => ['dev-main', false],
			'two majors' => ['>=4.1 <6.0', false]
		];
	}

	public function testLocationsAreEmptyWithoutARecord() {
		$this->assertSame([], ComposerInstall::locations($this->makeTree()['ip']));
	}

	/**
	 * install-path is written relative to vendor/composer, which is where the record itself lives,
	 * so resolving it is what turns composer's answer into a path this build can compare.
	 */
	public function testLocationsResolveEachInstallPath() {
		$tree = $this->makeTree([
			'extensions/TabberNeue',
			'skins/chameleon'
		], [
			[
				'name' => 'example/tabber-neue-extension',
				'version' => '1.0.0',
				'install-path' => '../../extensions/TabberNeue'
			],
			['name' => 'example/chameleon-skin', 'version' => '2.0.0', 'install-path' => '../../skins/chameleon']
		]);
		$locations = ComposerInstall::locations($tree['ip']);

		$this->assertSame(
			['example/tabber-neue-extension', 'example/chameleon-skin'],
			array_keys($locations)
		);
		$this->assertSame("{$tree['ip']}/extensions/TabberNeue", $locations['example/tabber-neue-extension']['path']);
		$this->assertSame('2.0.0', $locations['example/chameleon-skin']['version']);
	}

	/**
	 * The one this exists for. composer/installers does not CamelCase a skin, so a site listing
	 * "Chameleon" and asking for "mediawiki/chameleon-skin" gets skins/chameleon, which on a
	 * case-sensitive filesystem is a different directory. Nothing failed: the package installed,
	 * the log said "skipping skin 'Chameleon'", and the site went out without its skin.
	 */
	public function testASkinInstalledUnderAnotherNameIsNamed() {
		$tree = $this->makeTree(['skins/chameleon'], [
			['name' => 'mediawiki/chameleon-skin', 'version' => '4.5.0', 'install-path' => '../../skins/chameleon']
		]);
		$problem = ComposerInstall::misplaced(
			$tree['ip'],
			[
				'package' => 'mediawiki/chameleon-skin',
				'name' => 'Chameleon',
				'kind' => 'skin',
				'dest' => "{$tree['ip']}/skins/Chameleon"
			],
			ComposerInstall::locations($tree['ip'])
		);

		$this->assertNotNull($problem);
		$this->assertStringContainsString('installed into skins/chameleon', $problem);
		$this->assertStringContainsString('it looks in skins/Chameleon', $problem);
		$this->assertStringContainsString("List it as 'chameleon' instead.", $problem);
	}

	public function testAPackageThatLandedWhereTheBuildLooksIsNoProblem() {
		$tree = $this->makeTree(['extensions/TabberNeue'], [
			[
				'name' => 'mediawiki/tabber-neue-extension',
				'version' => '4.0.2',
				'install-path' => '../../extensions/TabberNeue'
			]
		]);
		$this->assertNull(ComposerInstall::misplaced(
			$tree['ip'],
			[
				'package' => 'mediawiki/tabber-neue-extension',
				'name' => 'TabberNeue',
				'kind' => 'extension',
				'dest' => "{$tree['ip']}/extensions/TabberNeue"
			],
			ComposerInstall::locations($tree['ip'])
		));
	}

	public function testAPackageComposerNeverInstalledIsNamed() {
		$tree = $this->makeTree();
		$problem = ComposerInstall::misplaced(
			$tree['ip'],
			[
				'package' => 'example/absent',
				'name' => 'Absent',
				'kind' => 'extension',
				'dest' => "{$tree['ip']}/extensions/Absent"
			],
			[]
		);
		$this->assertNotNull($problem);
		$this->assertStringContainsString('composer did not install', $problem);
	}

	/**
	 * A package declaring neither MediaWiki type lands in vendor/ like any other library. Renaming
	 * the entry would not make it an extension, so no name is suggested.
	 */
	public function testAPackageThatIsNotAComponentIsNotAnsweredWithARename() {
		$tree = $this->makeTree(['vendor/example/helper'], [
			['name' => 'example/helper', 'version' => '1.0.0', 'install-path' => '../example/helper']
		]);
		$problem = ComposerInstall::misplaced(
			$tree['ip'],
			[
				'package' => 'example/helper',
				'name' => 'Helper',
				'kind' => 'extension',
				'dest' => "{$tree['ip']}/extensions/Helper"
			],
			ComposerInstall::locations($tree['ip'])
		);
		$this->assertNotNull($problem);
		$this->assertStringContainsString('installed into vendor/example/helper', $problem);
		$this->assertStringNotContainsString('List it as', $problem);
	}

	/**
	 * Composer's record is read defensively because it is not this build's file: an entry without a
	 * name is nothing this can act on, and one without a path is a package that is installed but
	 * nowhere this build can point at.
	 */
	public function testAnEntryWithoutANameOrAPathIsHandled() {
		$tree = $this->makeTree([], [
			['version' => '1.0.0', 'install-path' => '../../extensions/Nameless'],
			['name' => 'example/pathless', 'version' => '1.0.0']
		]);
		$locations = ComposerInstall::locations($tree['ip']);
		$this->assertSame(['example/pathless'], array_keys($locations));
		$this->assertSame('', $locations['example/pathless']['path']);

		$problem = ComposerInstall::misplaced(
			$tree['ip'],
			[
				'package' => 'example/pathless',
				'name' => 'Pathless',
				'kind' => 'extension',
				'dest' => "{$tree['ip']}/extensions/Pathless"
			],
			$locations
		);
		$this->assertNotNull($problem);
		$this->assertStringContainsString('nowhere this build can see', $problem);
		$this->assertStringNotContainsString('List it as', $problem);
	}

	/**
	 * A package that landed under the other of the two directories is a component, just not the
	 * kind it was listed as, and which list it belongs in is the useful half of that answer.
	 */
	public function testAComponentListedUnderTheWrongKindIsSentToTheOtherList() {
		$tree = $this->makeTree(['skins/chameleon'], [
			['name' => 'mediawiki/chameleon-skin', 'version' => '4.5.0', 'install-path' => '../../skins/chameleon']
		]);
		$problem = ComposerInstall::misplaced(
			$tree['ip'],
			[
				'package' => 'mediawiki/chameleon-skin',
				'name' => 'chameleon',
				'kind' => 'extension',
				'dest' => "{$tree['ip']}/extensions/chameleon"
			],
			ComposerInstall::locations($tree['ip'])
		);
		$this->assertNotNull($problem);
		$this->assertStringContainsString("It is a skin, so list 'chameleon' under skins: instead.", $problem);
	}

	/**
	 * @param string[] $directories Paths under the install directory to create.
	 * @param list<array<string,string>> $packages Entries for vendor/composer/installed.json.
	 * @return array{ip:string}
	 */
	private function makeTree(array $directories = [], array $packages = []): array {
		$ip = sys_get_temp_dir() . '/wikven-composer-' . uniqid();
		$this->treesToClean[] = $ip;
		foreach ($directories as $directory) {
			mkdir("$ip/$directory", 0777, true);
		}
		if ($packages !== []) {
			mkdir("$ip/vendor/composer", 0777, true);
			file_put_contents(
				"$ip/vendor/composer/installed.json",
				json_encode(['packages' => $packages])
			);
		}
		if (!is_dir($ip)) {
			mkdir($ip, 0777, true);
		}
		return ['ip' => $ip];
	}

	protected function tearDown(): void {
		foreach ($this->treesToClean as $tree) {
			self::removeTree($tree);
		}
		parent::tearDown();
	}

	private static function removeTree(string $path): void {
		if (!is_dir($path)) {
			return;
		}
		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$child = "$path/$entry";
			is_dir($child) ? self::removeTree($child) : unlink($child);
		}
		rmdir($path);
	}
}
