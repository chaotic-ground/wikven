<?php

namespace MediaWiki\Extension\Wikven\Comments;

/**
 * Reads a source file into the comments it holds and the lines of code they sit among.
 *
 * PHP's own tokenizer does the parsing, so what counts as a comment here is what the language says
 * is one, and a "//" inside a string or a regular expression is not mistaken for a note.
 */
class Reader {
	/** @var int[] Tokens that may stand between a docblock and the thing it documents. */
	private const MODIFIERS = [T_ABSTRACT, T_FINAL, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_READONLY, T_VAR];

	/** @var int[] Tokens that introduce a type, and so a class docblock. */
	private const TYPES = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

	/** @var Comment[] */
	public array $comments = [];

	/** @var int Lines carrying at least one token that is neither comment nor whitespace. */
	public int $code = 0;

	public static function of(string $file): self {
		$reader = new self();
		$reader->read($file, (string)file_get_contents($file));

		return $reader;
	}

	private function read(string $file, string $source): void {
		$tokens = token_get_all($source);
		$codeLines = [];
		$seenDeclaration = false;
		$noteEnd = null;
		$noteWords = 0;
		$noteStart = 0;

		foreach ($tokens as $index => $token) {
			if (is_string($token) || $token[0] === T_WHITESPACE) {
				continue;
			}
			[$id, $text, $line] = $token;
			$isNote = $id === T_COMMENT && !str_starts_with($text, '/*');

			// A run of // lines is one note: split across lines by the print width, not by the
			// writer, and read as the paragraph it was written as.
			if ($isNote && $noteEnd === ( $line - 1 )) {
				$noteEnd = $line;
				$noteWords += self::words($text);
				continue;
			}
			if ($noteEnd !== null) {
				$this->comments[] = new Comment($file, Comment::NOTE, $noteStart, $noteWords);
				$noteEnd = null;
			}
			if ($isNote) {
				$noteStart = $line;
				$noteEnd = $line;
				$noteWords = self::words($text);
				continue;
			}
			if ($id === T_DOC_COMMENT || $id === T_COMMENT) {
				$kind = self::kind($tokens, $index, $seenDeclaration);
				$this->comments[] = new Comment($file, $kind, $line, self::words($text));
				continue;
			}
			if ($id !== T_OPEN_TAG && $id !== T_CLOSE_TAG && $id !== T_INLINE_HTML) {
				$seenDeclaration = true;
				$codeLines[$line] = true;
				for ($i = 1; $i <= substr_count($text, "\n"); $i++) {
					$codeLines[$line + $i] = true;
				}
			}
		}
		if ($noteEnd !== null) {
			$this->comments[] = new Comment($file, Comment::NOTE, $noteStart, $noteWords);
		}
		$this->code = count($codeLines);
	}

	/**
	 * What a block comment documents, read from the next token that is not whitespace or a modifier.
	 *
	 * @param array $tokens
	 * @param int $index Where the comment sits among them.
	 * @param bool $seenDeclaration Whether anything has been declared yet, which is what tells a
	 *   file's own header from a docblock that happens to stand first.
	 */
	private static function kind(array $tokens, int $index, bool $seenDeclaration): string {
		for ($i = $index + 1; $i < count($tokens); $i++) {
			$next = $tokens[$i];
			if (is_string($next)) {
				break;
			}
			if ($next[0] === T_WHITESPACE || $next[0] === T_ATTRIBUTE || in_array($next[0], self::MODIFIERS, true)) {
				continue;
			}
			if (in_array($next[0], self::TYPES, true)) {
				return Comment::TYPE;
			}
			if ($next[0] === T_FUNCTION || $next[0] === T_CONST || $next[0] === T_VARIABLE) {
				return Comment::MEMBER;
			}
			break;
		}

		return $seenDeclaration ? Comment::MEMBER : Comment::FILE;
	}

	/**
	 * Words of prose in a comment, with the markers and the @tag blocks taken out.
	 *
	 * A tag and the indented lines under it are the signature written a second time. Counting them
	 * would make somebody choose between documenting a parameter and explaining a decision, which
	 * is not a choice any budget should ask for.
	 */
	public static function words(string $text): int {
		$body = preg_replace('#^[ \t]*(/\*+|\*+/|\*|//+|\#+)#m', '', $text);
		$prose = [];
		$inTag = false;
		foreach (explode("\n", (string)$body) as $line) {
			if (preg_match('/^\s*@[a-zA-Z-]+/', $line) === 1) {
				$inTag = true;
				continue;
			}
			if ($inTag && preg_match('/^\s\s+\S/', $line) === 1) {
				continue;
			}
			$inTag = false;
			$prose[] = $line;
		}
		$words = preg_split('/\s+/', implode(' ', $prose), -1, PREG_SPLIT_NO_EMPTY);

		return count(array_filter($words, static function (string $word): bool {
			return preg_match('/[\p{L}\p{N}]/u', $word) === 1;
		}));
	}
}
