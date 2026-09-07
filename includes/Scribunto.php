<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Whether this build can render Lua, and what to say when it cannot.
 *
 * Scribunto is ordinary equipment on a MediaWiki wiki, and wikven's two products disagree about
 * it: the image compiles luasandbox into its PHP and bundles the extension, while the standalone
 * binary reaches Lua only by shelling out to a lua binary, which it has on 64-bit x86 Linux and
 * nowhere else.
 *
 * That disagreement used to be silent -- a page invoking a module rendered
 * "{{#invoke:Greet|hello}}" and the bake exited 0. See problem() and warning().
 */
class Scribunto {
	/** The extension a site lists to ask for Lua. */
	public const EXTENSION = 'Scribunto';

	/** Namespace prefixes a module source file carries. Canonical only: see modulePages(). */
	public const MODULE_PREFIXES = ['Module:'];

	/**
	 * The source files that are Lua modules.
	 *
	 * Read off the file name rather than asked of MediaWiki, because NS_MODULE does not exist until
	 * Scribunto is loaded. That is also why the prefix is the canonical one, documented as a
	 * convention rather than guessed at.
	 *
	 * @param string[] $relativePaths Source paths, relative to the source directory.
	 * @param string[] $prefixes Namespace prefixes to count as modules.
	 * @return string[] Those of $relativePaths that name a module, in the order given.
	 */
	public static function modulePages(array $relativePaths, array $prefixes = self::MODULE_PREFIXES): array {
		$modules = [];
		foreach ($relativePaths as $path) {
			foreach ($prefixes as $prefix) {
				if (str_starts_with($path, $prefix)) {
					$modules[] = $path;
					break;
				}
			}
		}
		return $modules;
	}

	/**
	 * Why this build cannot do what the site asked for, or null if it can.
	 *
	 * One rule, about a request this build cannot honour: a site that lists Scribunto where no
	 * engine can run it. The alternative is a published site with braces where the pages meant to
	 * say something.
	 *
	 * @param bool $listed Whether the site lists Scribunto in extensions.
	 * @param bool $engineAvailable Whether a Lua engine can run here.
	 * @return ?string The message for a fatal error, or null to carry on.
	 */
	public static function problem(bool $listed, bool $engineAvailable): ?string {
		if ($listed && !$engineAvailable) {
			return (
				'Wikven: this site lists '
				. self::EXTENSION
				. ' and no Lua engine is available here.'
				. ' The Docker image compiles luasandbox into its PHP. The standalone binary carries no'
				. ' engine of its own and falls back to the lua interpreter '
				. self::EXTENSION
				. ' ships, which is built for 64-bit x86 Linux and did not run here.'
				. ' Install a Lua 5.1 interpreter and name it under'
				. ' ScribuntoEngineConf.luastandalone.luaPath, bake this site with the Docker image, or'
				. ' drop '
				. self::EXTENSION
				. ' from extensions and the Module: pages with it.'
			);
		}
		return null;
	}

	/**
	 * What the site should know about its Module: files, or null if there is nothing to say.
	 *
	 * A source tree with module files and no Scribunto in extensions is not an error: the site
	 * never asked for Lua, and what those files are is a guess. So this says what it sees and lets
	 * the bake go on.
	 *
	 * @param bool $listed Whether the site lists Scribunto in extensions.
	 * @param string[] $modulePages Module source files found, from modulePages().
	 * @return ?string The message to print, or null to say nothing.
	 */
	public static function warning(bool $listed, array $modulePages): ?string {
		if ($listed || $modulePages === []) {
			return null;
		}
		$count = count($modulePages);
		$shown = implode(', ', array_slice($modulePages, 0, 3));
		if ($count > 3) {
			$shown .= ', ...';
		}
		return (
			"Wikven: the source has $count Lua module file(s) ($shown) and "
			. self::EXTENSION
			. ' is not in extensions, so a {{#invoke:}} is left in the page as its own source text,'
			. ' and a module named with the .wikitext marker is exported as a page. Add '
			. self::EXTENSION
			. ' to extensions if those modules are meant to run.'
		);
	}
}
