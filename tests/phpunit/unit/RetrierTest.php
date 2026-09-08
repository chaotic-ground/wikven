<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\Wikven\Fetching\RetryingForeignRepo;
use MediaWiki\Extension\Wikven\Hooks\Retrier;
use MediaWiki\FileRepo\ForeignAPIRepo;
use MediaWiki\FileRepo\ForeignDBRepo;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Hooks\Retrier
 */
class RetrierTest extends MediaWikiUnitTestCase {
	/** The InstantCommons entry, as SetupDynamicConfig.php leaves it in $wgForeignFileRepos. */
	private function instantCommons(): array {
		return [
			'class' => ForeignAPIRepo::class,
			'name' => 'wikimediacommons',
			'apibase' => 'https://commons.wikimedia.org/w/api.php',
			'apiThumbCacheExpiry' => 0,
			'directory' => '/var/www/html/images',
			'backend' => 'wikimediacommons-backend'
		];
	}

	public function testTheInstantCommonsRepositoryIsMadeRetrying() {
		$repos = Retrier::retrying([$this->instantCommons()]);

		$this->assertSame(RetryingForeignRepo::class, $repos[0]['class']);
	}

	public function testEveryOtherSettingOfTheRepositoryIsLeftAsCoreLeftIt() {
		$before = $this->instantCommons();
		$after = Retrier::retrying([$before])[0];

		unset($before['class'], $after['class']);
		$this->assertSame($before, $after);
	}

	public function testARepositoryOfAnotherClassIsLeftAlone() {
		// A subclass may already override what RetryingForeignRepo overrides; don't take it over.
		$repos = Retrier::retrying([
			['class' => ForeignDBRepo::class, 'name' => 'shared'],
			['class' => RetryingForeignRepo::class, 'name' => 'already'],
			['name' => 'classless']
		]);

		$this->assertSame(ForeignDBRepo::class, $repos[0]['class']);
		$this->assertSame(RetryingForeignRepo::class, $repos[1]['class']);
		$this->assertArrayNotHasKey('class', $repos[2]);
	}

	public function testTheDeprecatedUnqualifiedClassNameCountsToo() {
		// Core registers the qualified name, but its class_alias keeps this spelling working.
		$repos = Retrier::retrying([
			['class' => 'ForeignAPIRepo', 'name' => 'unqualified'],
			['class' => '\\' . ForeignAPIRepo::class, 'name' => 'leading-backslash']
		]);

		$this->assertSame(RetryingForeignRepo::class, $repos[0]['class']);
		$this->assertSame(RetryingForeignRepo::class, $repos[1]['class']);
	}

	public function testEveryForeignApiRepositoryIsMadeRetrying() {
		$second = ['class' => ForeignAPIRepo::class, 'name' => 'otherwiki'];
		$repos = Retrier::retrying([$this->instantCommons(), $second]);

		$this->assertSame(RetryingForeignRepo::class, $repos[0]['class']);
		$this->assertSame(RetryingForeignRepo::class, $repos[1]['class']);
	}

	public function testNoRepositoryAtAllIsHarmless() {
		$this->assertSame([], Retrier::retrying([]));
	}

	/**
	 * The hook is where this reaches core: the read goes through the service and the write cannot,
	 * because what core assembles the repositories from is the global.
	 */
	public function testTheHookWritesTheRepositoriesBackToTheGlobal() {
		$before = $GLOBALS['wgForeignFileRepos'] ?? null;
		try {
			$GLOBALS['wgForeignFileRepos'] = [];
			$retrier = new Retrier(new HashConfig(['ForeignFileRepos' => [$this->instantCommons()]]));

			$retrier->onSetupAfterCache();

			$this->assertSame(RetryingForeignRepo::class, $GLOBALS['wgForeignFileRepos'][0]['class']);
		} finally {
			if ($before === null) {
				unset($GLOBALS['wgForeignFileRepos']);
			} else {
				$GLOBALS['wgForeignFileRepos'] = $before;
			}
		}
	}

	/** $wgForeignFileRepos is a site's own setting, and a value that is not a repository is passed by. */
	public function testAnEntryThatIsNotARepositoryIsLeftWhereItIs() {
		$repos = Retrier::retrying(['nonsense', $this->instantCommons()]);

		$this->assertSame('nonsense', $repos[0]);
		$this->assertSame(RetryingForeignRepo::class, $repos[1]['class']);
	}
}
