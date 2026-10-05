<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\Output\DirectionalContext;
use MediaWiki\Extension\Wikven\Output\ModuleRenderer;
use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\Wikven\Output\DirectionalContext
 */
class DirectionalContextTest extends MediaWikiIntegrationTestCase {
	/** An English site's Arabic page, which links styles CSSJanus flipped for it. */
	public function testStylesComeOutFlippedInTheDirectionAsked() {
		[$rl, $request] = $this->flippable();

		$css = ModuleRenderer::render($rl, new DirectionalContext($rl, $request, 'rtl'));

		$this->assertStringContainsString('margin-right:1px', $css);
		$this->assertStringNotContainsString('margin-left', $css);
	}

	/**
	 * A module keeps what it rendered by the context's hash, and a build renders the site's
	 * direction first: without the direction in the hash, the flipped copy is the unflipped one.
	 */
	public function testTheSitesDirectionRenderedFirstDoesNotStandInForTheOther() {
		[$rl, $request] = $this->flippable();

		$ltr = ModuleRenderer::render($rl, new Context($rl, $request));
		$rtl = ModuleRenderer::render($rl, new DirectionalContext($rl, $request, 'rtl'));

		$this->assertStringContainsString('margin-left:1px', $ltr);
		$this->assertStringContainsString('margin-right:1px', $rtl);
	}

	/** @return array{0:ResourceLoader,1:FauxRequest} A loader with one module to flip, and a request for its styles. */
	private function flippable(): array {
		$directory = $this->getNewTempDirectory();
		file_put_contents("$directory/flip.css", ".wikven-flip { margin-left: 1px; }\n");
		$rl = $this->getServiceContainer()->getResourceLoader();
		$rl->register('wikven.test.flip', [
			'localBasePath' => $directory,
			'remoteBasePath' => '/',
			'styles' => ['flip.css']
		]);
		$query = ResourceLoader::makeLoaderQuery(
			['wikven.test.flip'],
			'en',
			'fallback',
			null,
			null,
			Context::DEBUG_OFF,
			'styles'
		);
		return [$rl, new FauxRequest($query)];
	}
}
