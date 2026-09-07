<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Joins a web path onto the directory it should name, or refuses it for climbing out.
 *
 * Two steps take a path out of content and look for a file at it, and both come from a site
 * author, so concatenating one hands them the filesystem.
 *
 * The path is judged, not where the filesystem would take it.
 */
class ContainedPath {
	/**
	 * The file a web path names under a root directory, or null where it does not stay under it.
	 *
	 * Null is not "missing", which is the caller's question: it means the path was never this
	 * directory's to answer for.
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
		// On segments rather than as a substring: "/..%2Fx" is one segment and no climb, while
		// "/a/../../x" is two and reaches above $root however deep it is.
		$segments = explode('/', $path);
		array_shift($segments);
		foreach ($segments as $segment) {
			if ($segment === '..' || $segment === '') {
				return null;
			}
		}
		return $root . $path;
	}
}
