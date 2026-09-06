<?php

namespace MediaWiki\Extension\Wikven;

/** The CSS files a build dumps into the export directory. */
class Stylesheet {
	/**
	 * Write one stylesheet out, and say what went wrong when it did not reach the disk.
	 *
	 * The caller is meant to stop the build rather than move on to the next module: a bake that
	 * filled the disk used to leave the site unstyled and say so only through wfDebug(), which goes
	 * nowhere.
	 *
	 * @param string $filename Where the stylesheet goes.
	 * @param string $text The CSS to write there.
	 * @return string|null A message naming the file, or null once the file is on the disk.
	 */
	public static function write(string $filename, string $text): ?string {
		if (file_put_contents($filename, $text, LOCK_EX) === false) {
			return "Wikven: could not write $filename";
		}
		return null;
	}
}
