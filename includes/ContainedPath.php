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
 *
 * The path is judged, not where the filesystem would take it. A symlink under the root pointing out
 * of it escapes this, and is left to: neither root holds anything the author of a path put there.
 * MediaWiki writes a file's contents into the upload directory rather than a link to them, and the
 * install root is the build's own. What a source tree can bring is refused where the source tree is
 * read, by ImageImport, which can name the file it refused -- while a check here would have refused
 * the symlinked skins/ and extensions/ of a MediaWiki someone develops against, silently, which is
 * the kind of failure this class exists against.
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
