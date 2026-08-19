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
		$this->assertNull(Scribunto::problem(true, true));
	}

	public function testNothingIsWrongWithNoLuaAtAll() {
		$this->assertNull(Scribunto::problem(false, false));
		$this->assertNull(Scribunto::problem(false, true));
	}

	public function testListingScribuntoWithNoEngineIsRefused() {
		$problem = Scribunto::problem(true, false);
		$this->assertNotNull($problem);
		$this->assertStringContainsString('no Lua engine', $problem);
		$this->assertStringContainsString('standalone binary', $problem, 'the message names the product');
	}

	/**
	 * The site asked for Lua and cannot have it either way, so the modules make no difference to the
	 * answer: this is the one case that ends the build.
	 */
	public function testTheEngineComplaintDoesNotDependOnTheModules() {
		$this->assertNotNull(Scribunto::problem(true, false));
		$this->assertNull(Scribunto::warning(true, ['Module:Greet']), 'and it is not also warned about');
	}

	public function testModulesWithoutScribuntoAreWarnedAbout() {
		$warning = Scribunto::warning(false, ['Module:Greet']);
		$this->assertNotNull($warning);
		$this->assertStringContainsString('1 Lua module file(s)', $warning);
		$this->assertStringContainsString('Module:Greet', $warning, 'and says which');
	}

	/**
	 * The whole point of the change: a source tree with modules and no Scribunto still bakes. The
	 * warning says what those files come to; it does not decide for the site.
	 */
	public function testModulesWithoutScribuntoAreNotAProblem() {
		$this->assertNull(Scribunto::problem(false, true));
		$this->assertNull(Scribunto::problem(false, false));
	}

	public function testItNamesAtMostThreeModules() {
		$many = ['Module:A', 'Module:B', 'Module:C', 'Module:D'];
		$warning = Scribunto::warning(false, $many);
		$this->assertStringContainsString('4 Lua module file(s)', $warning);
		$this->assertStringContainsString('Module:A, Module:B, Module:C, ...', $warning);
		$this->assertStringNotContainsString('Module:D', $warning, 'the count carries the rest');
	}

	/**
	 * A site with no engine and no Scribunto is the ordinary case for the binary, and it must not be
	 * told anything: most sites have no Lua and the binary is the product they are told to use.
	 */
	public function testTheBinaryIsQuietForASiteWithoutLua() {
		$this->assertNull(Scribunto::problem(false, false));
		$this->assertNull(Scribunto::warning(false, []));
	}
}
