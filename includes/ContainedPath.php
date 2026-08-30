<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Joins a web path onto the directory it is supposed to name, or refuses it for climbing out.
 *
 * Two steps take a path out of content and look for a file at it: storeImages reads the rendered
 * HTML for $wgUploadPath references, and AssetLocalizer reads dumped CSS for url()s under /skins,
 * /resources and /extensions. Both paths come from something a site author wrote -- a page's body,
 * or MediaWiki:Common.css -- so neither is the build's own, and concatenating one onto a directory
 * hands whoever wrote it every file the build can read: the copy lands in the output directory and
 * is published with the site.
 *
 * The answer is not to trust the path but to bound where it can reach, which is what this does.
 */
class ContainedPath {
	/**
	 * The file a web path names under a root directory, or null where it does not stay under it.
	 *
	 * Null is not "missing": a path that names nothing still comes back joined, because whether the
	 * file is there is the caller's question and its own to report. Null means the path was never
	 * this directory's to answer for.
	 *
	 * @param string $root Absolute path of the directory the web path is relative to.
	 * @param string $path A web path, leading slash and all, as it appeared in the content.
	 * @return string|null The absolute path to read, or null where it climbs out of $root.
	 */
	public static function under(string $root, string $path): ?string {
		$root = rtrim($root, '/');
		if ($root === '' || $path === '' || $path[0] !== '/') {
			return null;
		}
		// Lexical first, and on segments rather than a substring: "/..%2Fx" is one segment and no
		// climb, while "/a/../../x" is two of them and reaches above $root however deep $root is.
		if (in_array('..', explode('/', $path), true)) {
			return null;
		}
		$joined = $root . $path;
		// A symlink under $root can point anywhere, and only the filesystem knows where. Judged
		// only where both ends resolve; a path that resolves to nothing has nothing to escape with,
		// and is left to the caller to find missing.
		$realRoot = realpath($root);
		$real = realpath($joined);
		if ($realRoot !== false && $real !== false && !str_starts_with($real, rtrim($realRoot, '/') . '/')) {
			return null;
		}
		return $joined;
	}
}
