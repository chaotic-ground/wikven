<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Whether this build can render Lua, and what to say when it cannot.
 *
 * Scribunto is ordinary equipment on a MediaWiki wiki, and wikven's two products disagree about it.
 * The image compiles luasandbox into its PHP and bundles the extension, so a site that lists
 * Scribunto bakes Lua there. The standalone binary has no such extension -- static-php-cli, which
 * builds its PHP, offers no Lua extension among the hundred and thirty it supports -- and reaches
 * Lua only through Scribunto's other engine, which shells out to a lua binary. It gets one on 64-bit
 * x86 Linux, where the interpreter Scribunto itself ships will run; anywhere else the site has to
 * name one of its own.
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
 * became a page. Neither is worth being quiet about, and they are not worth the same answer either:
 * a site that asked for Scribunto where it cannot run is told no, and a site that never asked for it
 * is only told what its Module: files are doing. See problem() and warning().
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
	 * Why this build cannot do what the site asked for, or null if it can.
	 *
	 * One rule, and it is about a request this build cannot honour: a site that lists Scribunto where
	 * no engine can run it. That is the standalone binary, and the message names it. The alternative
	 * is a published site with braces where the pages meant to say something, which is worse than
	 * being told no.
	 *
	 * A site with no modules that lists Scribunto anyway is left alone: nothing is broken, the
	 * extension simply has nothing to do.
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
	 * A source tree with module files and no Scribunto in extensions is not an error. The site never
	 * asked for Lua, and what those files are is a guess read off a name: they may be on their way in,
	 * on their way out, or kept for something else entirely. Deciding that a file called Module:
	 * means the bake must not happen is deciding for the site, so this only says what it sees --
	 * the invocations stay in the pages as their own source text, and a module named with the
	 * .wikitext marker is exported -- and lets the bake go on.
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
