<?php

namespace MediaWiki\Extension\Wikven;

/**
 * What wikven calls itself on the servers it fetches from.
 *
 * A bake reaches other people's machines: it asks Commons for the thumbnail of every image a page
 * embeds and downloads each one, and it clones or downloads whatever third-party extensions and
 * skins the site declares. All of that arrives on somebody else's logs, and what it says there is
 * the only chance they have of telling this traffic apart from anything else -- of knowing which
 * tool made it, which version, and where to go about it. Wikimedia asks for exactly that in its
 * User-Agent policy, and it is the party wikven talks to most.
 *
 * Left alone, none of it says wikven. MediaWiki's HttpRequestFactory signs a request "MediaWiki/"
 * and its own version, which names the library doing the sending and not the thing that asked for
 * it: an operator reading that has no way to reach anyone, and every wikven build in the world
 * looks like a wiki. So requests carry this instead, and the shape is the one the policy asks for
 * -- the tool, its version, where it lives, and the library underneath:
 *
 *     Wikven/0.1.0 (+https://github.com/chaotic-ground/wikven) MediaWiki/1.46.0
 *
 * The version is read from extension.json rather than written here twice, so a release moves it
 * without anyone remembering to. The class is dependency-free on purpose: fetchExtensions runs
 * before wikven's autoloader exists and loads it by path, the same way it loads Attempts.
 */
class UserAgent {
	/**
	 * Where the tool lives.
	 *
	 * The address a server operator has for whoever is making the requests, which for a tool with
	 * no operator of its own is where its issues are read.
	 */
	private const URL = 'https://github.com/chaotic-ground/wikven';

	/** Built once: it names a release and a running MediaWiki, and neither changes mid-build. */
	private static ?string $string = null;

	/** The string every request wikven makes goes out under. */
	public static function string(): string {
		if (self::$string === null) {
			self::$string =
				'Wikven/'
				. self::version()
				. ' (+'
				. self::URL
				. ')'
				. ( defined('MW_VERSION') ? ' MediaWiki/' . MW_VERSION : '' );
		}
		return self::$string;
	}

	/**
	 * The same, after whatever the client would have said on its own.
	 *
	 * For a client that is not wikven's to speak for entirely. git is the one: its own string is
	 * "git/2.43.0", and some proxies pass HTTP git traffic only when the User-Agent still looks
	 * like a git client's, so replacing it outright would break a fetch on networks nobody here
	 * can see. Adding to it says who is driving without taking that away.
	 */
	public static function after(string $client): string {
		$client = trim($client);
		return $client === '' ? self::string() : $client . ' ' . self::string();
	}

	/** The version this copy of wikven is, or "dev" for a checkout that has no release in it. */
	private static function version(): string {
		$file = __DIR__ . '/../extension.json';
		$manifest = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
		$version = is_array($manifest) ? trim((string)( $manifest['version'] ?? '' )) : '';
		return $version !== '' ? $version : 'dev';
	}
}
