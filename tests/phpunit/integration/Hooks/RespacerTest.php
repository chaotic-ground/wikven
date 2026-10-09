<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration\Hooks;

use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * A parse needs a database whatever it is parsing; see VersionerTest.
 *
 * @covers \MediaWiki\Extension\Wikven\Hooks\Respacer
 * @group Database
 */
class RespacerTest extends MediaWikiIntegrationTestCase {
	/** Through core's own parse and output pipeline, so it also fails if extension.json drops the handler. */
	public function testCodeReadsAsWrittenWhileProseKeepsItsArmor() {
		$popts = ParserOptions::newFromAnon();
		$html = $this->getServiceContainer()
			->getParserFactory()
			->getInstance()
			->parse(
				"<pre>a != b</pre>\n\n<code>a != b</code> a !",
				Title::newFromText('Respacer test'),
				$popts
			)
			->runOutputPipeline($popts)
			->getContentHolderText();

		$this->assertStringContainsString('<pre>a != b</pre>', $html);
		$this->assertStringContainsString('<code>a != b</code>', $html);
		$this->assertMatchesRegularExpression('/a(\x{00A0}|&#160;)!/u', $html, 'prose keeps its armor');
	}
}
