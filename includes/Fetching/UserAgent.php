<?php

namespace MediaWiki\Extension\Wikven\Fetching;

/**
 * What wikven calls itself on the servers it fetches from.
 *
 * A bake reaches other people's machines, and their logs are their only chance of telling this
 * traffic apart. MediaWiki's own "MediaWiki/1.46.0" names the library rather than the tool, so
 * requests carry this instead:
 *
 *     Wikven/0.1.0 (+https://github.com/chaotic-ground/wikven) MediaWiki/1.46.0
 *
 * Dependency-free on purpose: fetchExtensions loads it by path.
 */
class UserAgent {
	/**
	 * Where the tool lives.
	 *
	 * The address a server operator has for whoever is making the requests, which for a tool with
	 * no operator of its own is where its issues are read.
	 */
	private const URL = 'https://github.com/chaotic-ground/wikven';

	/** Built once: it names a release, which does not change mid-build. */
	private static ?string $tool = null;

	/**
	 * The string a request goes out under where wikven is the whole of what is sending it.
	 *
	 * The library is named after the tool, which is the order the policy asks for.
	 */
	public static function string(): string {
		return defined('MW_VERSION') ? self::tool() . ' MediaWiki/' . MW_VERSION : self::tool();
	}

	/**
	 * wikven alone, for a client that names the library it is built on itself.
	 *
	 * ForeignAPIRepo is the one: it signs its requests "MediaWiki/1.46.0 (server)
	 * ForeignAPIRepo/2.1" and there is no sense in that carrying a second MediaWiki version
	 * behind it.
	 */
	public static function tool(): string {
		if (self::$tool === null) {
			self::$tool = 'Wikven/' . self::version() . ' (+' . self::URL . ')';
		}
		return self::$tool;
	}

	/**
	 * The same, after whatever the client would have said on its own.
	 *
	 * git is the one: some proxies pass its HTTP traffic only while the User-Agent still looks
	 * like a git client's.
	 */
	public static function after(string $client): string {
		$client = trim($client);
		return $client === '' ? self::string() : $client . ' ' . self::string();
	}

	/** The version this copy of wikven is, or "dev" for a checkout that has no release in it. */
	private static function version(): string {
		$file = __DIR__ . '/../../extension.json';
		$manifest = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
		$version = is_array($manifest) ? trim((string)( $manifest['version'] ?? '' )) : '';
		return $version !== '' ? $version : 'dev';
	}
}
