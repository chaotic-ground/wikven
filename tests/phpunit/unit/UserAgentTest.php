<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\UserAgent;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\UserAgent
 */
class UserAgentTest extends MediaWikiUnitTestCase {
	/** The version a release moves, read from the same file the class reads. */
	private function version(): string {
		$manifest = json_decode(file_get_contents(__DIR__ . '/../../../extension.json'), true);
		return (string)$manifest['version'];
	}

	/**
	 * What the string is for: an operator reading their logs can tell which tool made the request,
	 * which version of it, and where to go about it.
	 */
	public function testItNamesTheToolItsVersionAndWhereToFindIt() {
		$agent = UserAgent::string();
		$this->assertStringStartsWith('Wikven/' . $this->version() . ' ', $agent);
		$this->assertStringContainsString('(+https://github.com/chaotic-ground/wikven)', $agent);
	}

	/** The version is not written here twice: a release moves extension.json and this follows. */
	public function testTheVersionIsTheOneTheManifestCarries() {
		$this->assertStringStartsNotWith('Wikven/dev', UserAgent::string());
	}

	/**
	 * git signs its own requests, and some proxies only carry git traffic that still looks like
	 * git's, so wikven is added after that string rather than in place of it.
	 */
	public function testAClientKeepsItsOwnStringInFront() {
		$this->assertSame('git/2.43.0 ' . UserAgent::string(), UserAgent::after('git/2.43.0'));
		$this->assertSame(UserAgent::string(), UserAgent::after('  '));
	}
}
