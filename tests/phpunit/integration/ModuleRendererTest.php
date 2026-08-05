<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\ModuleRenderer;
use MediaWiki\Request\FauxRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\FileModule;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWikiIntegrationTestCase;
use RuntimeException;

/**
 * ResourceLoader reads module versions, dependencies and message blobs from the database, and
 * both paths under test here refuse to make up an answer when it is unreachable.
 *
 * @group Database
 * @covers \MediaWiki\Extension\Wikven\ModuleRenderer
 */
class ModuleRendererTest extends MediaWikiIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		// Hooks\Main's constructor reads WikvenHtmlDirectory, which WikvenSettings.php defines for
		// a build but extension.json does not declare, so a bare wfLoadExtension() wiki (what the
		// phpunit job installs) throws from every GetLocalURL hook -- including the ones
		// ResourceLoader runs while calculating module versions. respond() catches that and dumps
		// the backtrace into the response body, which is precisely the debris this class asserts
		// render() no longer produces; give the wiki the value so the comparison is between two
		// healthy responses rather than against error output.
		$this->setMwGlobals('wgWikvenHtmlDirectory', $this->getNewTempDirectory());
	}

	/** Every response shape the build dumps, as (modules, only, extra query) for makeLoaderQuery. */
	public static function provideResponseShapes(): array {
		return [
			'styles' => [['mediawiki.skinning.interface'], 'styles', []],
			// An ImageModule: its CSS is what carries the load.php image= url()s.
			'styles of an image module' => [['oojs-ui.styles.icons-editing-core'], 'styles', []],
			// A WikiModule, empty on a fresh wiki: the site.styles file buildStyles renders.
			'styles of a wiki module' => [['site.styles'], 'styles', []],
			'styles of an unregistered module' => [['wikven.test.absent'], 'styles', []],
			'no modules' => [[], 'styles', []],
			'raw startup' => [['startup'], 'scripts', ['raw' => '1']],
			'combined bundle' => [['jquery', 'mediawiki.base', 'mediawiki.util'], null, []],
			'combined, unregistered module' => [['wikven.test.absent'], null, []]
		];
	}

	/**
	 * The build swapped ResourceLoader::respond() (load.php's entry point, which emits HTTP
	 * headers) for ModuleRenderer::render(). Everything the static site serves is one of these
	 * responses, so the two must produce the same bytes for every shape the build dumps -- a
	 * quieter bake that changes the CSS or JS would be worse than the noise it removes.
	 *
	 * @dataProvider provideResponseShapes
	 */
	public function testRenderMatchesRespond(array $modules, ?string $only, array $extra) {
		$rl = $this->getServiceContainer()->getResourceLoader();
		$query = ResourceLoader::makeLoaderQuery(
			$modules,
			'en',
			'fallback',
			null,
			null,
			Context::DEBUG_OFF,
			$only,
			false,
			null,
			$extra
		);

		$expected = $this->viaRespond($rl, new Context($rl, new FauxRequest($query)));
		$actual = ModuleRenderer::render($rl, new Context($rl, new FauxRequest($query)));

		$this->assertSame(strlen($expected), strlen($actual), 'response length');
		$this->assertSame(sha1($expected), sha1($actual), 'response bytes');
	}

	/** The image endpoint is the other response the build dumps, via AssetLocalizer. */
	public function testRenderMatchesRespondForImages() {
		$rl = $this->getServiceContainer()->getResourceLoader();
		$query = [
			'modules' => 'oojs-ui.styles.icons-editing-core',
			'image' => 'edit',
			'format' => 'original',
			'lang' => 'en',
			'skin' => 'fallback'
		];
		if (!( new Context($rl, new FauxRequest($query)) )->getImageObj()) {
			$this->markTestSkipped('this MediaWiki has no oojs-ui "edit" icon to render');
		}

		$expected = $this->viaRespond($rl, new Context($rl, new FauxRequest($query)));
		$actual = ModuleRenderer::render($rl, new Context($rl, new FauxRequest($query)));

		$this->assertStringContainsString('<svg', $actual, 'an image was rendered');
		$this->assertSame(sha1($expected), sha1($actual), 'image bytes');
	}

	/**
	 * The point of the change: respond() calls header() for Content-Type, ETag, Cache-Control and
	 * Expires, which warns once per call under a maintenance script that has already written to
	 * stdout. render() must not reach any of them.
	 */
	public function testRenderSendsNoHeaders() {
		$rl = $this->getServiceContainer()->getResourceLoader();
		$query = ResourceLoader::makeLoaderQuery(
			['mediawiki.skinning.interface'],
			'en',
			'fallback',
			null,
			null,
			Context::DEBUG_OFF,
			'styles'
		);

		$warnings = [];
		$collect = static function (int $no, string $message) use (&$warnings): bool {
			$warnings[] = $message;
			return true;
		};

		ob_start();
		set_error_handler($collect, E_WARNING);
		try {
			$body = ModuleRenderer::render($rl, new Context($rl, new FauxRequest($query)));
		} finally {
			restore_error_handler();
			$printed = (string)ob_get_clean();
		}
		// respond() writes the response to the output stream on its way to sending headers;
		// render() only returns it, which is what keeps it away from header().
		$this->assertSame('', $printed, 'nothing written to the output stream');
		$this->assertNotSame('', $body, 'the response came back as a return value');
		$this->assertSame([], $warnings, 'no PHP warning raised while rendering');

		// Under PHPUnit the response is buffered, so header() has nothing to complain about and
		// the check above cannot tell a fixed call from a lucky one. Where output has really been
		// flushed -- a bake, which is the reported case -- respond() warns and render() does not.
		if (!headers_sent()) {
			return;
		}
		set_error_handler($collect, E_WARNING);
		try {
			ob_start();
			$rl->respond(new Context($rl, new FauxRequest($query)));
			ob_end_clean();
		} finally {
			restore_error_handler();
		}
		$this->assertNotSame([], $warnings, 'respond() is what used to warn');
	}

	/**
	 * The one place the two paths are meant to differ, pinned on purpose.
	 *
	 * A module that throws is caught by makeModuleResponse(), which logs it and leaves an "error"
	 * load state. respond() then writes the exception text and its backtrace into the response --
	 * so the build used to dump a CSS or JS asset with a stack trace inside it and carry on.
	 * render() stops instead.
	 */
	public function testRenderThrowsOnAModuleThatCannotBeBuilt() {
		$rl = $this->getServiceContainer()->getResourceLoader();
		$rl->register('wikven.test.broken', [
			'factory' => static function () {
				return new class extends FileModule {
					/** @inheritDoc */
					public function getModuleContent(Context $context): array {
						throw new RuntimeException('this module cannot be built');
					}
				};
			}
		]);
		$query = ResourceLoader::makeLoaderQuery(
			['wikven.test.broken'],
			'en',
			'fallback',
			null,
			null,
			Context::DEBUG_OFF,
			null
		);

		// What the build used to write to disk.
		$dumped = $this->viaRespond($rl, new Context($rl, new FauxRequest($query)));
		$this->assertStringContainsString(
			'this module cannot be built',
			$dumped,
			'respond() puts the exception in the response body'
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('wikven.test.broken');
		ModuleRenderer::render($rl, new Context($rl, new FauxRequest($query)));
	}

	/** Private modules carry per-user data; respond() refuses them and so must the dump. */
	public function testRenderRefusesPrivateModules() {
		$rl = $this->getServiceContainer()->getResourceLoader();
		$rl->register('wikven.test.private', [
			'class' => FileModule::class,
			'group' => 'private'
		]);
		$query = ResourceLoader::makeLoaderQuery(
			['wikven.test.private'],
			'en',
			'fallback',
			null,
			null,
			Context::DEBUG_OFF,
			null
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('wikven.test.private');
		ModuleRenderer::render($rl, new Context($rl, new FauxRequest($query)));
	}

	/**
	 * respond() writes its response to stdout; capture it the way the build used to.
	 * The header() warnings it raises here are the very thing under test, so swallow those
	 * (and only those) rather than let them fail the comparison.
	 */
	private function viaRespond(ResourceLoader $rl, Context $context): string {
		set_error_handler(static function (int $no, string $message): bool {
			return str_contains($message, 'Cannot modify header information');
		}, E_WARNING);
		try {
			ob_start();
			$rl->respond($context);
			return (string)ob_get_clean();
		} finally {
			restore_error_handler();
		}
	}
}
