<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Whether this build can render Lua, and what to say when it cannot.
 *
 * Scribunto is ordinary equipment on a MediaWiki wiki, and wikven's two products disagree about it.
 * The image compiles luasandbox into its PHP and bundles the extension, so a site that lists
 * Scribunto bakes Lua there. The standalone binary has neither: static-php-cli, which builds its PHP,
 * offers no Lua extension among the hundred and thirty it supports, and Scribunto's other engine
 * shells out to a lua binary that a single executable does not carry.
 *
 * Until now that disagreement was silent, and silence was the worst of it. Measured on a bake of one
 * page invoking one module, every one of these exited 0:
 *
 *   Scribunto listed, engine present     rendered "Lua says hello"
 *   not listed, module named bare        rendered "{{#invoke:Greet|hello}}", module file ignored
 *   not listed, module named .wikitext   rendered "{{#invoke:Greet|hello}}", module published as
 *                                        Module%3AGreet.html, its Lua source now a page of the site
 *
 * So a reader got braces where the page meant to say something, and in one case the module's source
 * became a page. A build that stops is better than either, which is what this is for.
 */
class Scribunto {
	/** The extension a site lists to ask for Lua. */
	public const EXTENSION = 'Scribunto';

	/** Namespace prefixes a module source file carries. Canonical only: see modulePages(). */
	public const MODULE_PREFIXES = ['Module:'];

	/**
	 * The source files that are Lua modules.
	 *
	 * Read off the file name rather than asked of MediaWiki, because the question is put when
	 * Scribunto may not be loaded, and NS_MODULE does not exist until it is. That is also why the
	 * prefix is the canonical one: without the extension there is no localised namespace name to
	 * compare against, so a site whose module files are named in another language is not recognised
	 * here. Documented as the convention rather than guessed at.
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
	 * What is wrong with this combination, or null if nothing is.
	 *
	 * Two rules, and both are about a mismatch rather than about Lua itself:
	 *
	 * - a site that asks for Scribunto where no engine can run it gets a build that says so, instead
	 *   of pages with braces in them. This is the standalone binary, and the message names it;
	 * - a site with module files that does not ask for Scribunto gets the same treatment, since
	 *   without it those files are either ignored or published, and the pages that invoke them are
	 *   rendered as their own source.
	 *
	 * A site with no modules that lists Scribunto anyway is left alone under the image: nothing is
	 * broken, the extension simply has nothing to do.
	 *
	 * @param bool $listed Whether the site lists Scribunto in extensions.
	 * @param bool $engineAvailable Whether a Lua engine can run here.
	 * @param string[] $modulePages Module source files found, from modulePages().
	 * @return ?string The message for a fatal error, or null to carry on.
	 */
	public static function problem(bool $listed, bool $engineAvailable, array $modulePages): ?string {
		if ($listed && !$engineAvailable) {
			return (
				'Wikven: this site lists '
				. self::EXTENSION
				. ' and no Lua engine is available here.'
				. ' The Docker image has one; the standalone binary has none, so it cannot bake a site'
				. ' that uses Lua modules. Bake this one with the image, or drop '
				. self::EXTENSION
				. ' from extensions and the Module: pages with it.'
			);
		}
		if (!$listed && $modulePages !== []) {
			$count = count($modulePages);
			$shown = implode(', ', array_slice($modulePages, 0, 3));
			if ($count > 3) {
				$shown .= ', ...';
			}
			return (
				"Wikven: the source has $count Lua module file(s) ($shown) and "
				. self::EXTENSION
				. ' is not in extensions. Without it a {{#invoke:}} is left in the page as its own'
				. ' source text, and a module named with the .wikitext marker is exported as a page.'
				. ' Add '
				. self::EXTENSION
				. ' to extensions, or remove those files.'
			);
		}
		return null;
	}
}
