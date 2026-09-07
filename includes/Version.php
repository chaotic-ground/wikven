<?php

namespace MediaWiki\Extension\Wikven;

/**
 * The version a loaded component declares for itself.
 *
 * Read from ExtensionRegistry's credits, where a manifest's "version" lands. Kept apart from the
 * hook that serves {{WIKVENVERSION}} so it can be asked of a plain array in a test.
 */
class Version {
	/**
	 * @param array $things ExtensionRegistry::getAllThings(), keyed by component name.
	 * @param string $name The component to ask about, as its manifest names it.
	 * @return string The declared version, or '' where the component is absent or declares none.
	 *   Empty is not an error to report: a page rendering {{WIKVENVERSION}} on a wiki that has not
	 *   loaded Wikven is asking a question with no answer, and there is nobody in a parser run to
	 *   tell.
	 */
	public static function of(array $things, string $name): string {
		$version = $things[$name]['version'] ?? null;
		return is_string($version) ? $version : '';
	}
}
