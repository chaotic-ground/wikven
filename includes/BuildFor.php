<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Who this build is for: a site being published, or a skin being looked at.
 *
 * For a site, wikven empties the menus for login, watchlist, talk and search and turns the tabs
 * into links to the source files. That is wrong where the skin is the subject. Link rewriting and
 * the two Citizen fixes stay on either way.
 */
class BuildFor {
	/** A site to publish: the chrome is trimmed to what a static host can stand behind. */
	public const SITE = 'site';

	/** A skin to look at: the chrome is left as the skin drew it. */
	public const SKIN_PREVIEW = 'skin-preview';

	/**
	 * Every audience a build can be for, in the spelling a site writes.
	 *
	 * Two of them, and the pair is why this is a value rather than a flag: they answer one
	 * question.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return [self::SITE, self::SKIN_PREVIEW];
	}

	/**
	 * The audience this build is for, falling back to a site.
	 *
	 * An unrecognised value reads as a site, which SiteConfig::lint() has already named. Read from
	 * the global, as the hooks that ask read $wgWikvenEditUrl.
	 */
	public static function current(): string {
		$configured = $GLOBALS['wgWikvenBuildFor'] ?? self::SITE;
		return in_array($configured, self::all(), true) ? (string)$configured : self::SITE;
	}

	/** Whether the chrome wikven would otherwise impose is to be left alone. */
	public static function skinPreview(): bool {
		return self::current() === self::SKIN_PREVIEW;
	}
}
