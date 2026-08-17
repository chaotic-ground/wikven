<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Extension\Wikven\Scribunto;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\Scribunto
 */
class ScribuntoTest extends MediaWikiUnitTestCase {
	public function testItFindsModuleFilesByTheirPrefix() {
		$paths = ['index.wikitext', 'Module:Greet', 'Module:Order.wikitext', 'Template:prevnext.wikitext'];
		$this->assertSame(
			['Module:Greet', 'Module:Order.wikitext'],
			Scribunto::modulePages($paths),
			'both namings count, and nothing else does'
		);
	}

	public function testAPageMerelyMentioningModulesIsNotOne() {
		$paths = ['Modules of wikven.wikitext', 'docs/Module:Nested', 'Talk:Module:Greet.wikitext'];
		$this->assertSame(
			[],
			Scribunto::modulePages($paths),
			'the prefix has to start the path, so a nested or prefixed name is somebody else'
		);
	}

	public function testALocalisedPrefixCanBeAskedFor() {
		$paths = ['모듈:Greet', 'Module:Greet'];
		$this->assertSame(['모듈:Greet'], Scribunto::modulePages($paths, ['모듈:']));
	}

	public function testNothingIsWrongWhenTheEngineIsThere() {
		$this->assertNull(Scribunto::problem(true, true, ['Module:Greet']));
	}

	public function testNothingIsWrongWithNoLuaAtAll() {
		$this->assertNull(Scribunto::problem(false, false, []));
		$this->assertNull(Scribunto::problem(false, true, []));
	}

	public function testListingScribuntoWithNoEngineIsRefused() {
		$problem = Scribunto::problem(true, false, []);
		$this->assertNotNull($problem);
		$this->assertStringContainsString('no Lua engine', $problem);
		$this->assertStringContainsString('standalone binary', $problem, 'the message names the product');
	}

	public function testTheEngineComplaintWinsOverTheOther() {
		$problem = Scribunto::problem(true, false, ['Module:Greet']);
		$this->assertStringContainsString('no Lua engine', $problem, 'the one the site cannot fix by listing');
	}

	public function testModulesWithoutScribuntoAreRefused() {
		$problem = Scribunto::problem(false, true, ['Module:Greet']);
		$this->assertNotNull($problem);
		$this->assertStringContainsString('1 Lua module file(s)', $problem);
		$this->assertStringContainsString('Module:Greet', $problem, 'and says which');
	}

	public function testItNamesAtMostThreeModules() {
		$many = ['Module:A', 'Module:B', 'Module:C', 'Module:D'];
		$problem = Scribunto::problem(false, true, $many);
		$this->assertStringContainsString('4 Lua module file(s)', $problem);
		$this->assertStringContainsString('Module:A, Module:B, Module:C, ...', $problem);
		$this->assertStringNotContainsString('Module:D', $problem, 'the count carries the rest');
	}

	/**
	 * A site with no engine and no Scribunto is the ordinary case for the binary, and it must not be
	 * told anything: most sites have no Lua and the binary is the product they are told to use.
	 */
	public function testTheBinaryIsQuietForASiteWithoutLua() {
		$this->assertNull(Scribunto::problem(false, false, []));
	}
}
