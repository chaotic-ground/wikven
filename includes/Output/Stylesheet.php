<?php

namespace MediaWiki\Extension\Wikven\Output;

/** The CSS files a build dumps into the export directory. */
class Stylesheet {
	/**
	 * Write one stylesheet out, and say what went wrong.
	 *
	 * The caller is meant to stop the build: a bake that filled the disk used to leave the site
	 * unstyled and say so only through wfDebug().
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

	/**
	 * The file a page links a module's styles by, read in the given direction.
	 *
	 * A page read the other way from the site's language, such as an Arabic translation on an
	 * English site, links a copy CSSJanus flipped for it.
	 *
	 * @param string $module The module, e.g. "skins.vector.styles".
	 * @param string $pageDirection "ltr" or "rtl", as the page's language reads.
	 * @param string $siteDirection "ltr" or "rtl", as the site's language reads.
	 */
	public static function fileName(string $module, string $pageDirection, string $siteDirection): string {
		return $pageDirection === $siteDirection ? "$module.css" : "$module.$pageDirection.css";
	}

	/**
	 * Which module a stylesheet holds, and the direction it is to be rendered in.
	 *
	 * The undoing of fileName(). A module whose own name ends in ".rtl" is still that module.
	 *
	 * @param string $fileName The stylesheet's file name, e.g. "skins.vector.styles.rtl.css".
	 * @param callable(string):bool $isModule Whether ResourceLoader has a module by that name.
	 * @return array{module:string,direction:?string} The direction is null for the site's own.
	 */
	public static function module(string $fileName, callable $isModule): array {
		$name = basename($fileName, '.css');
		if (
			preg_match('/^(.+)\.(ltr|rtl)$/', $name, $m)
			&& !$isModule($name)
			&& $isModule($m[1])
		) {
			return ['module' => $m[1], 'direction' => $m[2]];
		}
		return ['module' => $name, 'direction' => null];
	}
}
