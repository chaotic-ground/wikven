<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What a build says about a category the site asked no page to be in.
 *
 * A tracking category is how MediaWiki reports something it will not refuse to render. A template
 * used without a required argument draws its placeholder; a Lua error prints in place of the
 * module's answer; a page whose template expansion ran past its limit is silently cut short. The
 * page still builds, and a static export publishes all of it without a word -- the source is
 * valid, the build succeeds, and the rendered text quietly is not what was written.
 *
 * Which of those a site treats as a fault is the site's own judgement, so nothing is listed by
 * default; WikvenFailOnCategories is where a site says. The rule here is only what to do with what
 * was found, and it is the whole of it: a named category with anything in it stops the build, and
 * says which pages put it there, because the category itself is not in the export to be looked at.
 */
class FailOnCategories {
	/** How many pages a complaint names before it stops and counts the rest. */
	private const NAMED = 8;

	/**
	 * One line per category that was asked to be empty and was not.
	 *
	 * @param array<string,string[]> $members Category name to the pages in it. A category with
	 *   nothing in it need not appear; one that does appear with an empty list is treated the same.
	 * @return string[] Empty where every category the site named is empty.
	 */
	public static function failures(array $members): array {
		$failures = [];
		foreach ($members as $category => $pages) {
			if ($pages === []) {
				continue;
			}
			$failures[] = sprintf(
				'Wikven: Category:%s holds %d page(s), and WikvenFailOnCategories says it must hold none: %s',
				$category,
				count($pages),
				self::name($pages)
			);
		}
		return $failures;
	}

	/**
	 * The pages, up to the point where a list stops helping.
	 *
	 * One source line can reach every skin's copy of a page and every language of it, so a category
	 * naming nine pages may be one mistake. The first few are what a reader needs to find it.
	 *
	 * @param string[] $pages
	 */
	private static function name(array $pages): string {
		$shown = array_slice($pages, 0, self::NAMED);
		$rest = count($pages) - count($shown);
		return implode(', ', $shown) . ( $rest > 0 ? " and $rest more" : '' );
	}
}
