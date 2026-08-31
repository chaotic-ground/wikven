<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Version;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * A parse needs a database whatever it is parsing: expanding any variable at all makes core ask
 * the page for its language, and a page's language comes from its content model, which is a row.
 *
 * @covers \MediaWiki\Extension\Wikven\Hooks\Versioner
 * @group Database
 */
class VersionerTest extends MediaWikiIntegrationTestCase {
	/**
	 * The wiring, which a unit test cannot reach: Wikven.magic.php has to map the text
	 * WIKVENVERSION to the id, GetMagicVariableIDs has to list that id, and only then is
	 * ParserGetVariableValueSwitch asked for a value. Miss any one of the three and a page
	 * renders the braces, or the empty string, with nothing said.
	 */
	public function testAPageGetsTheVersionThisWikvenDeclares() {
		$declared = Version::of(ExtensionRegistry::getInstance()->getAllThings(), 'Wikven');

		$this->assertNotSame('', $declared, 'extension.json declares the version release-please bumps');
		$this->assertSame($declared, $this->expand('{{WIKVENVERSION}}'));
	}

	/** It reads as a variable wherever a variable is read, a template parameter's default included. */
	public function testItExpandsWhereverAVariableDoes() {
		$declared = Version::of(ExtensionRegistry::getInstance()->getAllThings(), 'Wikven');

		$this->assertSame("wikven $declared", $this->expand('wikven {{WIKVENVERSION}}'));
	}

	/** Expanded rather than parsed: what is under test is the value, not how a skin draws it. */
	private function expand(string $wikitext): string {
		return $this->getServiceContainer()
			->getParserFactory()
			->getInstance()
			->preprocess(
				$wikitext,
				Title::newFromText('Versioner test'),
				ParserOptions::newFromAnon()
			);
	}
}
