<?php

namespace MediaWiki\Extension\Wikven;

/** Rewrites PNG bytes for a reproducible export: drops the chunks that record a clock. */
class Png {
	/** The 8 bytes every PNG starts with. */
	private const SIGNATURE = "\x89PNG\r\n\x1a\n";

	/**
	 * Return the PNG without the chunks whose payload is a timestamp.
	 *
	 * A thumbnail of unchanged source differs between bakes because ImageMagick stamps what it
	 * writes: a tIME chunk, date:create/date:modify/date:timestamp text chunks, and, from the
	 * -thumbnail operator, Thumb::MTime holding the source file's mtime. MediaWiki removes only
	 * Thumb::URI (BitmapHandler, T108616) and offers no way to pass -strip, so the export strips
	 * them on its way out instead.
	 *
	 * Only clocks are dropped. The comment naming the source file, the colour profile, the rest of
	 * the Thumb::* family (size, dimensions, mimetype) and the image data are all kept, and
	 * removing whole chunks leaves every remaining CRC valid.
	 *
	 * @param string $data Raw file contents.
	 * @return string|null The rewritten PNG, or null if this is not a PNG whose chunks parse
	 *   cleanly, in which case the caller should use the bytes unchanged.
	 */
	public static function withoutTimestamps(string $data): ?string {
		if (!str_starts_with($data, self::SIGNATURE)) {
			return null;
		}
		$total = strlen($data);
		$offset = strlen(self::SIGNATURE);
		$out = self::SIGNATURE;
		// Each chunk is a 4-byte length, a 4-byte type, that many bytes of payload, and a 4-byte CRC.
		while (( $offset + 12 ) <= $total) {
			$size = unpack('N', substr($data, $offset, 4))[1];
			$type = substr($data, $offset + 4, 4);
			$end = $offset + 12 + $size;
			if ($end > $total) {
				return null;
			}
			if (!self::isTimestamp($type, substr($data, $offset + 8, $size))) {
				$out .= substr($data, $offset, $end - $offset);
			}
			$offset = $end;
			if ($type === 'IEND') {
				// Bytes past IEND mean this is not a plain PNG; leave the file alone.
				return $offset === $total ? $out : null;
			}
		}
		return null;
	}

	/** Whether a chunk records a clock: tIME, or a text chunk keyed date:* or Thumb::MTime. */
	private static function isTimestamp(string $type, string $payload): bool {
		if ($type === 'tIME') {
			return true;
		}
		if (!in_array($type, ['tEXt', 'zTXt', 'iTXt'], true)) {
			return false;
		}
		$keyword = strstr($payload, "\0", true);
		if (!is_string($keyword)) {
			return false;
		}
		return str_starts_with($keyword, 'date:') || $keyword === 'Thumb::MTime';
	}
}
