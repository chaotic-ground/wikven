<?php

namespace MediaWiki\Extension\Wikven\PageTranslation;

/**
 * What `translate check` found, written for the person who wrote the translation.
 *
 * The check's own output is GitHub Actions annotations, the right shape for someone reading a
 * diff and the wrong one for someone meeting this workflow. Every word here is a message in
 * i18n/, reached through a callable so the comment can be tested without a wiki.
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
	 * The kinds of finding, in the order shown: the ones that stop a page being translated at all
	 * first, then the ones about a translation that is merely behind.
	 *
	 * Each names a heading and the advice under it.
	 */
	private const KINDS = [
		'parse',
		'reserved',
		'unmarked',
		'disagree',
		'markup',
		'orphan',
		'standalone',
		'stale',
		'untranslated'
	];

	/**
	 * Kinds that never fail the check, whatever the workflow asked for: a translation behind its
	 * source or naming a unit it lost, and a subpage read as a page of its own.
	 */
	private const NON_GATING_KINDS = ['orphan', 'standalone', 'stale', 'untranslated'];

	/** @var callable(string,string,list<string>):string Message key, language code and parameters. */
	private $message;

	/**
	 * The paths the comment is about, or null for all of them.
	 *
	 * @var list<string>|null
	 */
	private ?array $paths = null;

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
	 * The same advice, but only about the paths a change touches.
	 *
	 * A translation counts as touched when its own file was; one whose source page was, only where
	 * it fails the check, since translating is not the source editor's job.
	 *
	 * @param list<string> $paths As the findings name their files: repo-relative, in the same
	 *   shape --path-prefix produces.
	 */
	public function about(array $paths): self {
		$scoped = clone $this;
		$scoped->paths = array_values($paths);
		return $scoped;
	}

	/**
	 * The languages of the translations the change touches: what its author writes in.
	 *
	 * Not the findings' languages: editing an English page puts its Korean translation behind,
	 * and its author may not read Korean. Unscoped, the findings are all there is.
	 *
	 * @param array<string,string> $translations Language code by translation file.
	 * @param list<array<string,string>> $findings
	 * @return list<string> Sorted, each once.
	 */
	public function languagesFor(array $translations, array $findings): array {
		if ($this->paths === null) {
			$languages = array_column($findings, 'lang');
		} else {
			$languages = [];
			foreach ($this->paths as $path) {
				if (isset($translations[$path])) {
					$languages[] = $translations[$path];
				}
			}
		}
		$languages = array_values(array_unique($languages));
		sort($languages);
		return $languages;
	}

	/**
	 * The comment for a run that found something, or null for one that found nothing.
	 *
	 * A finding carries a kind and a file, then whichever of source, unit, lang, line and detail
	 * its kind has to say.
	 *
	 * @param list<array<string,string>> $findings
	 * @param list<string> $languages Rendered once each, in this order.
	 */
	public function comment(array $findings, array $languages = ['en']): ?string {
		$grouped = self::group($this->inScope($findings));
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
	 * The body of a run that found nothing, which is how a consumer tells that from a finding.
	 *
	 * CLEAR_MARKER on its own line is what says so; the prose below is for a consumer that keeps
	 * its comment.
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
				return (
					$this->heading($language)
					. $this->msg($this->key('wikven-translations-all-clear'), $language)
					. "\n"
				);
			}
		);
	}

	/**
	 * One rendering per language, minus any that came out the same as one already there.
	 *
	 * A language with no translation of these messages falls back to English and would otherwise
	 * say everything twice.
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
	 * @param array<string,array<string,list<array{unit?:string,lang?:string,line?:string,detail?:string}>>> $grouped
	 */
	private function body(array $grouped, string $language): string {
		$body = $this->heading($language) . $this->msg($this->key('wikven-translations-lead'), $language) . "\n";
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
		$nothingGates = array_diff(array_keys($grouped), self::NON_GATING_KINDS) === [];
		$closing = $nothingGates ? 'wikven-translations-nothing-fails' : 'wikven-translations-can-fail';
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
	 * @param array{unit?:string,lang?:string,line?:string,detail?:string} $finding
	 */
	private function note(array $finding, string $language): string {
		// A finding about a place rather than a unit says where, since that is what a reader opens
		// the file to look at.
		if (isset($finding['line'])) {
			return $this->msg('wikven-translations-line', $language, [$finding['line']]);
		}
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
	 * The message for a comment about everything, or the one that says it is about a change.
	 *
	 * Two messages rather than one hedged wording, because a comment that has been narrowed and one
	 * that has not are saying different things.
	 */
	private function key(string $key): string {
		return $this->paths === null ? $key : "$key-scoped";
	}

	/**
	 * The findings a scoped comment is about; all of them where nothing was scoped.
	 *
	 * @param list<array<string,string>> $findings
	 * @return list<array<string,string>>
	 */
	private function inScope(array $findings): array {
		if ($this->paths === null) {
			return $findings;
		}
		$paths = $this->paths;
		return array_values(array_filter($findings, static function (array $finding) use ($paths): bool {
			if (in_array($finding['file'], $paths, true)) {
				return true;
			}
			// A translation is also this change's when its source page is, but only where it fails
			// the check: editing an English page is what puts its translations behind, and that
			// is the translation system working.
			if (
				isset($finding['source'])
				&& in_array($finding['source'], $paths, true)
				&& !in_array($finding['kind'], self::NON_GATING_KINDS, true)
			) {
				return true;
			}
			// And the other way round: a source page nobody can read is why a translation of it
			// renders as English, so whoever touched that translation is owed the reason. The
			// translations of docs/Page.wikitext are the files under docs/Page/.
			$directory = preg_replace('/\.wikitext$/', '/', $finding['file']);
			if ($directory === $finding['file']) {
				return false;
			}
			foreach ($paths as $path) {
				if (str_starts_with($path, $directory)) {
					return true;
				}
			}
			return false;
		}));
	}

	/**
	 * Findings by kind, then by file, each file's findings in the order they were found, so one
	 * line can carry several units of the same file without repeating the file name.
	 *
	 * @param list<array{kind:string,file:string,unit?:string,lang?:string,line?:string,detail?:string}> $findings
	 * @return array<string,array<string,list<array{unit?:string,lang?:string,line?:string,detail?:string}>>>
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
