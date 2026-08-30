<?php

namespace MediaWiki\Extension\Wikven;

/**
 * Who this build is for: a site being published, or a skin being looked at.
 *
 * Wikven has opinions about the chrome around a page, and they are a reader's opinions: a static
 * export has no account to log into, no watchlist to add to, no talk page to post on and no server
 * to search with, so the menus offering those are emptied, the tabs are replaced with links to the
 * source files, and a footer link to the repository goes in. That is right for a published site,
 * where every affordance left standing is one that has to work.
 *
 * It is wrong for the other thing a bake is useful for. A skin author who points wikven at a
 * handful of pages to see their skin rendered wants to see *their skin* -- the personal menu it
 * draws, the tabs it lays out, the toolbox it styles -- and what they get instead is wikven's
 * reading of it, with most of that taken away. The skin is the subject, and the tool has been
 * editing the subject.
 *
 * So the chrome layer can be turned off, and this is the switch. What it does not touch is
 * everything that makes the output a working set of files: links are still rewritten to "./X.html",
 * stylesheets and scripts are still baked to local copies, the canonical link still points at the
 * main skin's copy. Those are not opinions about how a page should look; without them there is no
 * preview to look at.
 *
 * Two per-skin fixes also stay on, because they are of the same kind: Citizen's service worker,
 * which registers against a load.php the export does not have, and its search shortcuts, which
 * reach for modules the export does not ship. Both exist to stop the page asking for something
 * that is not there, which is as true of a preview as of a site.
 */
class BuildFor {
	/** A site to publish: the chrome is trimmed to what a static host can stand behind. */
	public const SITE = 'site';

	/** A skin to look at: the chrome is left as the skin drew it. */
	public const SKIN_PREVIEW = 'skin-preview';

	/**
	 * Every audience a build can be for, in the spelling a site writes.
	 *
	 * Two of them, and the pair is why this is not a flag. They are not independent switches a
	 * site turns on together; they are answers to one question, and the setting asks that question
	 * once. A third would be a third value rather than a second boolean nobody knows how to
	 * combine with the first.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return [self::SITE, self::SKIN_PREVIEW];
	}

	/**
	 * The audience this build is for, falling back to a site.
	 *
	 * An unrecognised value reads as a site, which is the reading that publishes something a
	 * static host can answer for; SiteConfig::lint() has already named it by then. Read from the
	 * global rather than injected config, because the hooks that ask are the ones that already
	 * read $wgWikvenEditUrl and its neighbours that way.
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
