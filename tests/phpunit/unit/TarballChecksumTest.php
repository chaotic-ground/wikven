<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\TarballChecksum;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\TarballChecksum
 */
class TarballChecksumTest extends MediaWikiUnitTestCase {
	/** sha256 of the string "wikven", which the fixtures below are written with. */
	private const WIKVEN = '4aeacdc4e8c0957fd033b0ee00b8fcf38bbcd9513b6160c1150864b498b68dd2';

	private string $file;

	protected function setUp(): void {
		parent::setUp();
		$this->file = sys_get_temp_dir() . '/wikven-tarball-' . getmypid() . '-' . uniqid();
		file_put_contents($this->file, 'wikven');
	}

	protected function tearDown(): void {
		if (is_file($this->file)) {
			unlink($this->file);
		}
		parent::tearDown();
	}

	public function testASpecPinningNothingWantsNothing() {
		$this->assertNull(TarballChecksum::wanted(['tarball' => 'https://example.test/x.tar.gz']));
	}

	/**
	 * Told apart from pinning nothing on purpose: a spec whose sha256 is unreadable has said
	 * something and got it wrong, and the build stops on it rather than fetching unverified.
	 */
	public function testASpecPinningSomethingUnreadableWantsAnEmptyString() {
		$this->assertSame('', TarballChecksum::wanted(['sha256' => ['not', 'a', 'string']]));
		$this->assertFalse(TarballChecksum::isValid(''));
	}

	public function testACheckSumIsTakenAsWrittenOnceLowercasedAndTrimmed() {
		$upper = strtoupper(self::WIKVEN);

		$this->assertSame(self::WIKVEN, TarballChecksum::wanted(['sha256' => $upper]));
		$this->assertSame(self::WIKVEN, TarballChecksum::wanted(['sha256' => "  $upper\n"]));
	}

	public function testSixtyFourHexCharactersIsAChecksum() {
		$this->assertTrue(TarballChecksum::isValid(self::WIKVEN));
	}

	public function testAnythingElseIsNot() {
		$this->assertFalse(TarballChecksum::isValid(''), 'empty');
		$this->assertFalse(TarballChecksum::isValid(substr(self::WIKVEN, 0, 63)), 'one short');
		$this->assertFalse(TarballChecksum::isValid(self::WIKVEN . 'a'), 'one long');
		$this->assertFalse(TarballChecksum::isValid(str_repeat('g', 64)), 'not hex');
		$this->assertFalse(TarballChecksum::isValid(strtoupper(self::WIKVEN)), 'wanted() lowercases first');
		$this->assertFalse(TarballChecksum::isValid('sha256:' . self::WIKVEN), 'carries a prefix');
	}

	public function testAFileThatHashesToThePinMatchesIt() {
		$this->assertTrue(TarballChecksum::matches(self::WIKVEN, $this->file));
	}

	/**
	 * The promise this exists to hold. Configuration says "the build aborts on a mismatch", and a
	 * tarball is the one fetch method whose source can change without the site's configuration
	 * changing -- so a comparison that stopped saying no would let a build run code nobody chose,
	 * with the documentation still promising it could not.
	 */
	public function testAFileThatDoesNotIsRefused() {
		file_put_contents($this->file, 'wikven, tampered with');

		$this->assertFalse(TarballChecksum::matches(self::WIKVEN, $this->file));
	}

	/** Including when the difference is one character, which is the interesting mismatch. */
	public function testOneCharacterOfDifferenceIsAMismatch() {
		$almost = substr(self::WIKVEN, 0, 63) . ( self::WIKVEN[63] === 'a' ? 'b' : 'a' );

		$this->assertFalse(TarballChecksum::matches($almost, $this->file));
	}

	/** A download that produced no file answers to no pin, rather than raising on hash_file. */
	public function testAFileThatIsNotThereMatchesNothing() {
		unlink($this->file);

		$this->assertFalse(TarballChecksum::matches(self::WIKVEN, $this->file));
	}
}
