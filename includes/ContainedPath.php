<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Joins a web path onto the directory it is supposed to name, or refuses it for climbing out.
 *
 * Two steps take a path out of content and look for a file at it: storeImages reads rendered HTML
 * for $wgUploadPath references, and AssetLocalizer reads dumped CSS for url()s. Both come from
 * something a site author wrote, so concatenating one onto a directory hands them the filesystem.
 *
 * The path is judged, not where the filesystem would take it: judging the latter would refuse the
 * symlinked skins/ of a MediaWiki someone develops against.
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
		// On segments rather than as a substring: "/..%2Fx" is one segment and no climb, while
		// "/a/../../x" is two of them and reaches above $root however deep $root is. The leading
		// empty one is the leading slash; an empty one after that is a path naming a directory
		// ("/images/") or a doubled slash, and names no file either way.
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
