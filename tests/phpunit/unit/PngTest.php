<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Png;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Png
 */
class PngTest extends MediaWikiUnitTestCase {
	private const SIGNATURE = "\x89PNG\r\n\x1a\n";

	/** Assemble a chunk the way a PNG encoder does: length, type, payload, CRC of type+payload. */
	private function chunk(string $type, string $payload = ''): string {
		return pack('N', strlen($payload)) . $type . $payload . pack('N', crc32($type . $payload));
	}

	/** A text chunk, whose payload is the keyword, a NUL, then the value. */
	private function text(string $keyword, string $value): string {
		return $this->chunk('tEXt', "$keyword\0$value");
	}

	/** A PNG carrying everything ImageMagick writes into a thumbnail, stamped at $written. */
	private function thumbnail(string $written, string $sourceMtime): string {
		return (
			self::SIGNATURE
			. $this->chunk('IHDR', str_repeat("\x01", 13))
			. $this->chunk('tIME', pack('nC5', 2026, 8, 9, 15, 13, 28))
			. $this->chunk('IDAT', 'not really deflate data')
			. $this->text('comment', 'File source: File:logo.png.html')
			. $this->text('date:create', $written)
			. $this->text('date:modify', $written)
			. $this->text('date:timestamp', $written)
			. $this->text('software', 'https://imagemagick.org')
			. $this->text('Thumb::MTime', $sourceMtime)
			. $this->text('Thumb::Size', '6395B')
			. $this->text('Thumb::Image::Width', '160')
			. $this->chunk('IEND')
		);
	}

	/**
	 * The point of the class: the same image written at two different moments, from a source file
	 * whose own mtime also moved, comes out as the same bytes.
	 */
	public function testTwoWritesOfTheSameImageBecomeIdentical() {
		$first = $this->thumbnail('2026-08-09T15:12:08+00:00', '1786291435');
		$second = $this->thumbnail('2026-08-09T15:15:33+00:00', '1786291559');
		$this->assertNotSame($first, $second, 'the inputs differ to begin with');

		$this->assertSame(
			Png::withoutTimestamps($first),
			Png::withoutTimestamps($second)
		);
	}

	public function testClockChunksAreDropped() {
		$stripped = Png::withoutTimestamps($this->thumbnail('2026-08-09T15:12:08+00:00', '1786291435'));
		$this->assertStringNotContainsString('date:create', $stripped);
		$this->assertStringNotContainsString('date:modify', $stripped);
		$this->assertStringNotContainsString('date:timestamp', $stripped);
		$this->assertStringNotContainsString('Thumb::MTime', $stripped);
		$this->assertStringNotContainsString('tIME', $stripped);
	}

	/** Everything that describes the image, rather than when it was made, survives. */
	public function testDescriptiveChunksAreKept() {
		$stripped = Png::withoutTimestamps($this->thumbnail('2026-08-09T15:12:08+00:00', '1786291435'));
		$this->assertStringStartsWith(self::SIGNATURE, $stripped);
		$this->assertStringContainsString('IHDR', $stripped);
		$this->assertStringContainsString('IDAT', $stripped);
		$this->assertStringContainsString('File source: File:logo.png.html', $stripped);
		$this->assertStringContainsString('software', $stripped);
		$this->assertStringContainsString('Thumb::Size', $stripped);
		$this->assertStringContainsString('Thumb::Image::Width', $stripped);
		$this->assertStringEndsWith($this->chunk('IEND'), $stripped);
	}

	/** Chunks are removed whole, so the CRC of every chunk left behind still matches its bytes. */
	public function testRemainingChunksKeepValidChecksums() {
		$stripped = Png::withoutTimestamps($this->thumbnail('2026-08-09T15:12:08+00:00', '1786291435'));
		$offset = strlen(self::SIGNATURE);
		$seen = [];
		while ($offset < strlen($stripped)) {
			$size = unpack('N', substr($stripped, $offset, 4))[1];
			$type = substr($stripped, $offset + 4, 4);
			$payload = substr($stripped, $offset + 8, $size);
			$crc = unpack('N', substr($stripped, $offset + 8 + $size, 4))[1];
			$this->assertSame(crc32($type . $payload), $crc, "CRC of the $type chunk");
			$seen[] = $type;
			$offset += 12 + $size;
		}
		$this->assertSame(strlen($stripped), $offset, 'the chunks account for every byte');
		$this->assertSame(['IHDR', 'IDAT', 'tEXt', 'tEXt', 'tEXt', 'tEXt', 'IEND'], $seen);
	}

	public function testStrippingIsIdempotent() {
		$once = Png::withoutTimestamps($this->thumbnail('2026-08-09T15:12:08+00:00', '1786291435'));
		$this->assertSame($once, Png::withoutTimestamps($once));
	}

	/** A PNG with nothing to drop is returned unchanged rather than rebuilt differently. */
	public function testAPngWithoutTimestampsIsUnchanged() {
		$png =
			self::SIGNATURE
			. $this->chunk('IHDR', str_repeat("\x01", 13))
			. $this->chunk('IDAT', 'data')
			. $this->chunk('IEND');
		$this->assertSame($png, Png::withoutTimestamps($png));
	}

	/**
	 * Anything the parser cannot account for is refused, so storeImages falls back to copying the
	 * file as it found it rather than writing a mangled one.
	 *
	 * @dataProvider provideUnhandledInput
	 */
	public function testUnhandledInputIsRefused(string $label, string $data) {
		$this->assertNull(Png::withoutTimestamps($data), $label);
	}

	public static function provideUnhandledInput(): array {
		$signature = self::SIGNATURE;
		$ihdr = pack('N', 13) . 'IHDR' . str_repeat("\x01", 13) . pack('N', 0);
		$iend = pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
		return [
			'empty' => ['empty input', ''],
			'jpeg' => ['a JPEG', "\xff\xd8\xff\xe0" . str_repeat("\x00", 64)],
			'svg' => ['an SVG', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
			'signature only' => ['the signature alone', $signature],
			'truncated chunk' => [
				'a chunk shorter than its length claims',
				$signature . pack('N', 999) . 'IDATshort'
			],
			'no IEND' => ['a file that never reaches IEND', $signature . $ihdr],
			'trailing bytes' => ['bytes past IEND', $signature . $ihdr . $iend . 'extra']
		];
	}
}
