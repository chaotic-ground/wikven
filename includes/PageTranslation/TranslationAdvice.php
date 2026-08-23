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
 * So the same findings are also gathered into one comment: grouped by what went wrong, each group
 * saying what to run, and the whole thing ending on what does and does not fail the build.
 *
 * Every word of it is a message in i18n/, because the person being advised is by definition
 * someone working in another language: the comment can be rendered once per language, so a
 * contributor who sent a Korean translation reads the advice in Korean under the English. The
 * messages are reached through a callable rather than wfMessage() directly, so the shape of the
 * comment is tested without a wiki behind it.
 */
class TranslationAdvice {
	/**
	 * Hidden in the comment body so the action can find its own comment again and edit it, rather
	 * than leaving a new one on every push.
	 */
	public const MARKER = '<!-- wikven-check-translations -->';

	/**
	 * Marks the body a clean run writes, which the action treats differently: it replaces a
	 * complaint it left earlier, and is not worth a comment of its own on a change that never had
	 * one.
	 */
	public const CLEAR_MARKER = '<!-- wikven-check-translations:clear -->';

	/** What separates one language's rendering of the comment from the next. */
	private const SEPARATOR = "\n---\n\n";

	/**
	 * The kinds of finding, in the order they are shown: the ones that stop a page being translated
	 * at all first, then the ones about a translation of a page that is otherwise fine.
	 *
	 * Each names two messages, a heading and the advice under it. The advice names the command,
	 * because the command is the part a contributor cannot guess.
	 */
	private const KINDS = ['parse', 'reserved', 'unmarked', 'disagree', 'orphan', 'stale', 'untranslated'];

	/** Kinds that are a translation falling behind rather than a page nobody can translate. */
	private const TRANSLATION_KINDS = ['stale', 'untranslated'];

	/** @var callable(string,string,list<string>):string Message key, language code and parameters. */
	private $message;

	/** @param callable(string,string,list<string>):string $message */
	public function __construct(callable $message) {
		$this->message = $message;
	}

	/** The advice as a wiki renders it: messages from i18n/, in whichever language is asked for. */
	public static function usingMessages(): self {
		return new self(static function (string $key, string $language, array $parameters): string {
			return wfMessage($key, ...$parameters)->inLanguage($language)->text();
		});
	}

	/**
	 * The comment for a run that found something, or null for one that found nothing.
	 *
	 * @param list<array{kind:string,file:string,unit?:string,lang?:string,detail?:string}> $findings
	 * @param list<string> $languages Rendered once each, in this order.
	 */
	public function comment(array $findings, array $languages = ['en']): ?string {
		$grouped = self::group($findings);
		if ($grouped === []) {
			return null;
		}
		return self::MARKER
		. "\n"
		. $this->inEachLanguage(
			$languages,
			function (string $language) use ($grouped): string {
				return $this->body($grouped, $language);
			}
		);
	}

	/**
	 * The comment a run leaves once everything it complained about is fixed.
	 *
	 * Edited over the old body rather than deleted, so the thread keeps its place in the
	 * conversation and a reader who followed a notification finds the answer rather than a gap.
	 *
	 * @param list<string> $languages
	 */
	public function allClear(array $languages = ['en']): string {
		return self::MARKER
		. "\n"
		. self::CLEAR_MARKER
		. "\n"
		. $this->inEachLanguage(
			$languages,
			function (string $language): string {
				return $this->heading($language) . $this->msg('wikven-translations-all-clear', $language) . "\n";
			}
		);
	}

	/**
	 * One rendering per language, minus any that came out the same as one already there.
	 *
	 * A language with no translation of these messages falls back to English and would otherwise
	 * say everything twice, which reads as a bug rather than as the honest "not translated yet".
	 *
	 * @param list<string> $languages
	 * @param callable(string):string $render
	 */
	private function inEachLanguage(array $languages, callable $render): string {
		$renderings = [];
		foreach ($languages as $language) {
			$rendering = $render($language);
			if (!in_array($rendering, $renderings, true)) {
				$renderings[] = $rendering;
			}
		}
		return implode(self::SEPARATOR, $renderings);
	}

	/**
	 * The comment in one language: what was found, under a heading per kind, then what it costs.
	 *
	 * @param array<string,array<string,list<array{unit?:string,lang?:string,detail?:string}>>> $grouped
	 */
	private function body(array $grouped, string $language): string {
		$body = $this->heading($language) . $this->msg('wikven-translations-lead', $language) . "\n";
		foreach (self::KINDS as $kind) {
			if (!isset($grouped[$kind])) {
				continue;
			}
			$body .=
				"\n### "
				. $this->msg("wikven-translations-$kind-heading", $language)
				. "\n\n"
				. $this->msg("wikven-translations-$kind-advice", $language)
				. "\n\n";
			foreach ($grouped[$kind] as $file => $findings) {
				$notes = [];
				foreach ($findings as $finding) {
					$notes[] = $this->note($finding, $language);
				}
				$body .= '- `' . $file . '` — ' . implode('; ', $notes) . "\n";
			}
		}

		// Which of the two closing lines is honest depends on what was found, not on how the check
		// was configured: staleness never gates, and a broken page gates only where the workflow
		// asked it to.
		$onlyTranslations = array_diff(array_keys($grouped), self::TRANSLATION_KINDS) === [];
		$closing = $onlyTranslations ? 'wikven-translations-nothing-fails' : 'wikven-translations-can-fail';
		return (
			$body
			. "\n"
			. $this->msg($closing, $language)
			. ' '
			. $this->msg(
				'wikven-translations-documentation',
				$language,
				[$this->msg('wikven-translations-documentation-url', $language)]
			)
			. "\n"
		);
	}

	/** The comment's own title, which is also what tells a reader whose language a rendering is. */
	private function heading(string $language): string {
		return '## ' . $this->msg('wikven-translations-title', $language) . "\n\n";
	}

	/**
	 * What one finding is called on its file's line: a unit by number and language, and anything
	 * else by the message the check itself produced.
	 *
	 * @param array{unit?:string,lang?:string,detail?:string} $finding
	 */
	private function note(array $finding, string $language): string {
		if (!isset($finding['unit'])) {
			return (string)( $finding['detail'] ?? '' );
		}
		return (
			isset($finding['lang'])
				? $this->msg('wikven-translations-unit', $language, [$finding['unit'], $finding['lang']])
				: $this->msg('wikven-translations-unit-plain', $language, [$finding['unit']])
		);
	}

	/**
	 * @param string $key Message key.
	 * @param string $language Language code to render it in.
	 * @param list<string> $parameters
	 */
	private function msg(string $key, string $language, array $parameters = []): string {
		return ( $this->message )($key, $language, $parameters);
	}

	/**
	 * Findings by kind, then by file, each file's findings in the order they were found, so one
	 * line can carry several units of the same file without repeating the file name.
	 *
	 * @param list<array{kind:string,file:string,unit?:string,lang?:string,detail?:string}> $findings
	 * @return array<string,array<string,list<array{unit?:string,lang?:string,detail?:string}>>>
	 */
	private static function group(array $findings): array {
		$grouped = [];
		foreach ($findings as $finding) {
			if (!in_array($finding['kind'], self::KINDS, true)) {
				continue;
			}
			$grouped[$finding['kind']][$finding['file']][] = $finding;
		}
		return $grouped;
	}
}
