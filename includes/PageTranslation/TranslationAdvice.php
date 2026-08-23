<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

/**
 * What `translate check` found, written for the person who wrote the translation.
 *
 * The check's own output is GitHub Actions annotations: one line per finding, on the file it
 * belongs to, which is the right shape for someone reading a diff and the wrong one for someone
 * meeting this workflow for the first time. An annotation says "Stale translation unit T:3 (ko)";
 * it does not say that a stamp is what makes a unit current, or which command writes one.
 *
 * So the same findings are also gathered here into one comment: grouped by what went wrong, each
 * group saying what to run, and the whole thing ending on what does and does not fail the build.
 * Pure string work over the findings array, so the wording is tested rather than inferred from a
 * workflow run.
 */
class TranslationAdvice {
	/**
	 * Hidden in the comment body so the action can find its own comment again and edit it, rather
	 * than leaving a new one on every push.
	 */
	public const MARKER = '<!-- wikven-check-translations -->';

	/**
	 * Marks the body a clean run writes, which the action treats differently: it replaces a
	 * complaint it left earlier, and is not worth a comment of its own on a pull request that never
	 * had one.
	 */
	public const CLEAR_MARKER = '<!-- wikven-check-translations:clear -->';

	/** Where the reader is sent for the whole story, once the comment has said the short version. */
	private const DOCUMENTATION = 'https://chaotic-ground.github.io/wikven/Translating.html';

	/**
	 * The groups, in the order they are shown: the ones that stop a page being translated at all
	 * first, then the ones about a translation of a page that is otherwise fine.
	 *
	 * Each is a heading and the advice under it. The advice names the command, because the command
	 * is the part a contributor cannot guess.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const GROUPS = [
		'parse' => [
			'The source page cannot be read',
			'Translate refuses the page, so nothing of it can be translated yet. The message is'
				. ' Translate\'s own; it usually points at a `<!--T:n-->` marker somewhere other than'
				. ' the start of its unit or the end of a line.'
		],
		'reserved' => [
			'A unit is numbered `T:title`',
			'That id belongs to the page\'s own title, which a translation carries instead of the'
				. ' page repeating it. Renumber that unit and leave `T:title` to the title.'
		],
		'unmarked' => [
			'Units have no `<!--T:n-->` marker',
			'A unit is translated by its number, so an unnumbered one cannot be. Run `translate mark`'
				. ' on the page; it keeps the numbers already there.'
		],
		'disagree' => [
			'wikven and Translate read the page differently',
			'The two disagree about where the units are, which means the export would translate'
				. ' something other than what Translate marked. Re-run `translate mark` and, if it'
				. ' persists, say so upstream: this is wikven\'s bug rather than yours.'
		],
		'orphan' => [
			'A translation names a unit the source does not have',
			'Its `<!--T:n-->` was renumbered or removed in the source, so the text under it reaches'
				. ' no one. Run `translate scaffold <language>` to add the markers the source has'
				. ' now, move the text under the right one, and delete what is left.'
		],
		'stale' => [
			'Translated, but not stamped as current',
			'A unit is current when its marker carries the source\'s hash: `<!--T:3 @a1b2c3d4-->`.'
				. ' Read each unit below against the source, then run `translate stamp` to write the'
				. ' stamps. Do not type a hash by hand.'
		],
		'untranslated' => [
			'Not translated yet',
			'These units are empty, which is not an error: the build renders them in the source'
				. ' language, and the page can be translated a few units at a time. Listed so that'
				. ' nothing is left behind by accident.'
		],
	];

	/**
	 * The comment for a run that found something, or null for one that found nothing.
	 *
	 * @param list<array{kind:string,file:string,unit?:string,lang?:string,detail?:string}> $findings
	 */
	public static function comment(array $findings): ?string {
		$grouped = self::group($findings);
		if ($grouped === []) {
			return null;
		}

		$body = self::MARKER . "\n## Translations in this pull request\n\n"
			. "`translate check` read the source pages and their translations, and has this to say."
			. " Every line is also an annotation on the file it belongs to.\n";
		foreach (self::GROUPS as $kind => [$heading, $advice]) {
			if (!isset($grouped[$kind])) {
				continue;
			}
			$body .= "\n### $heading\n\n$advice\n\n";
			foreach ($grouped[$kind] as $file => $notes) {
				$body .= '- `' . $file . '` — ' . implode('; ', $notes) . "\n";
			}
		}

		// Which of the two closing lines is honest depends on what was found, not on how the check
		// was configured: staleness never gates, and a broken page gates only where the workflow
		// asked it to.
		$onlyTranslations = array_diff(array_keys($grouped), ['stale', 'untranslated']) === [];
		$body .= "\n" . ( $onlyTranslations
			? 'None of this fails the check: a translation that is behind is the translation system'
				. ' working, and the export marks such a unit as outdated for the reader.'
			: 'A broken source page is the one thing here that can fail the check, and only where the'
				. ' workflow asked it to; a translation that is merely behind or missing never does.' );
		return $body . ' See [Translating](' . self::DOCUMENTATION . ") for the whole workflow.\n";
	}

	/**
	 * The comment a run leaves once everything it complained about is fixed.
	 *
	 * Edited over the old body rather than deleted, so the thread keeps its place in the
	 * conversation and a reader who followed a notification finds the answer rather than a gap.
	 */
	public static function allClear(): string {
		return self::MARKER . "\n" . self::CLEAR_MARKER . "\n## Translations in this pull request\n\n"
			. "`translate check` found nothing to report: every source page reads cleanly, and every"
			. " translation of one is stamped current.\n";
	}

	/**
	 * Findings by kind, then by file, each file's notes in the order they were found.
	 *
	 * A unit finding becomes "T:3 (ko)" and a page finding its own message, so one line can carry
	 * several units of the same file without repeating the file name.
	 *
	 * @param list<array{kind:string,file:string,unit?:string,lang?:string,detail?:string}> $findings
	 * @return array<string,array<string,list<string>>>
	 */
	private static function group(array $findings): array {
		$grouped = [];
		foreach ($findings as $finding) {
			$kind = $finding['kind'];
			if (!isset(self::GROUPS[$kind])) {
				continue;
			}
			$note = isset($finding['unit'])
				? 'T:' . $finding['unit'] . ( isset($finding['lang']) ? ' (' . $finding['lang'] . ')' : '' )
				: (string)( $finding['detail'] ?? '' );
			$grouped[$kind][$finding['file']][] = $note;
		}
		return $grouped;
	}
}
