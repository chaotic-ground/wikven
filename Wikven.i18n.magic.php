<?php

/**
 * Magic words Wikven adds to the parser.
 *
 * A page that wants to say which Wikven built it has nowhere else to read that from: the version is
 * in extension.json, which no page can open, and MediaWiki's own {{CURRENTVERSION}} answers for
 * MediaWiki rather than for what wrote the site. This is that variable's sibling.
 *
 * The first element of each entry is 1 for case-sensitive, matching every variable MediaWiki
 * spells in capitals.
 */

$magicWords = [];

$magicWords['en'] = [
	'wikvenversion' => [1, 'WIKVENVERSION'],
];
