<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\RetryingForeignRepo;
use MediaWiki\Http\MWHttpRequest;
use MediaWiki\Status\Status;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use RuntimeException;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * The repository as MediaWiki drives it: its own httpGet(), not just the retry helper.
 *
 * @covers \MediaWiki\Extension\Wikven\RetryingForeignRepo
 */
class RetryingForeignRepoTest extends MediaWikiIntegrationTestCase {
	use MockHttpTrait;

	/**
	 * A repository with its own empty cache, so one test's lookups cannot answer another's.
	 *
	 * @param array $extra Repository settings on top of the defaults, as a site's own
	 *   $wgForeignFileRepos entry would carry.
	 */
	private function newRepo(array $extra = []): RetryingForeignRepo {
		return new RetryingForeignRepo(
			$extra
			+ [
				'name' => 'testcommons',
				'apibase' => 'https://commons.example.org/w/api.php',
				'url' => 'https://upload.example.org/commons',
				'thumbUrl' => 'https://upload.example.org/commons/thumb',
				'hashLevels' => 2,
				'apiThumbCacheExpiry' => 0,
				'wanCache' => WANObjectCache::newEmpty(),
				'backend' => new FSFileBackend([
					'name' => 'testcommons-backend',
					'domainId' => 'testcommons',
					'basePath' => $this->getNewTempDirectory()
				])
			]
		);
	}

	/**
	 * Make every HTTP request answer with the next of $responses, and count the requests made.
	 *
	 * @param array[] $responses Each either ['fail'] or ['body' => string, 'lastModified' => string].
	 * @param int &$made Set to the number of requests the repository made.
	 */
	private function answerWith(array $responses, &$made): void {
		$made = 0;
		// installMockHttp builds a real HttpRequestFactory and fails the test by name on any request
		// path this mock does not answer.
		$this->installMockHttp(function () use ($responses, &$made) {
			$response = $responses[min($made, count($responses) - 1)];
			$made++;
			if (!isset($response['body'])) {
				return $this->makeFakeTimeoutRequest();
			}
			if (!isset($response['lastModified'])) {
				return $this->makeFakeHttpRequest($response['body'], 200);
			}
			return $this->answering($response['body'], ['Last-Modified' => $response['lastModified']]);
		});
	}

	/**
	 * A successful response carrying headers, answered the way MWHttpRequest answers them.
	 *
	 * getResponseHeader() is documented case-insensitive and the real class implements it that
	 * way, lowercasing the name it is asked for. MockHttpTrait's fake lowercases the name it was
	 * *given* and compares that to the name asked for, so it answers a lowercase ask and nothing
	 * else -- and core asks for "Last-Modified". Every header a test hands it therefore reads back
	 * as absent, which is not a fake of MWHttpRequest but of a different class. This is one, for
	 * the one method that differs; the rest of the response is still the trait's.
	 *
	 * @param string $body The response body.
	 * @param array<string,string> $headers The response headers, in any case.
	 */
	private function answering(string $body, array $headers): MWHttpRequest {
		$response = $this->createNoOpMock(
			MWHttpRequest::class,
			['execute', 'setHeader', 'getStatus', 'getContent', 'getResponseHeaders', 'getResponseHeader']
		);
		$response->method('execute')->willReturn(Status::newGood(200));
		$response->method('getStatus')->willReturn(200);
		$response->method('getContent')->willReturn($body);
		$response->method('getResponseHeaders')->willReturn($headers);
		$response->method('getResponseHeader')
			->willReturnCallback(
				static function (string $name) use ($headers): ?string {
					foreach ($headers as $header => $value) {
						if (strcasecmp($header, $name) === 0) {
							return $value;
						}
					}
					return null;
				}
			);
		return $response;
	}

	/** An imageinfo response body, with a thumbnail URL unless $thumbUrl is null. */
	private function imageInfo(?string $thumbUrl): string {
		$info = $thumbUrl !== null ? ['thumburl' => $thumbUrl] : [];
		return json_encode(['query' => ['pages' => [['imageinfo' => [$info]]]]]);
	}

	public function testARequestThatFailsIsRepeatedUntilItAnswers() {
		$this->answerWith([['fail'], ['body' => 'answered', 'lastModified' => 'Mon, 03 Aug 2026 00:00:00 GMT']], $made);

		$mtime = false;
		$body = $this->newRepo()->httpGet('https://commons.example.org/w/api.php', 'default', [], $mtime);

		$this->assertSame('answered', $body);
		$this->assertSame(2, $made, 'the failed request should have been made again');
		// $mtime is written by the innermost call; it only reaches the caller if the closure
		// wrapping that call captured it by reference.
		$this->assertSame(wfTimestamp(TS_UNIX, 'Mon, 03 Aug 2026 00:00:00 GMT'), (string)$mtime);
	}

	public function testARequestIsGivenUpOnAfterThreeAttempts() {
		$this->answerWith([['fail']], $made);

		$started = microtime(true);
		$mtime = false;
		$body = $this->newRepo()->httpGet('https://commons.example.org/w/api.php', 'default', [], $mtime);
		$elapsed = microtime(true) - $started;

		$this->assertFalse($body, 'the caller still sees the failure once the attempts run out');
		$this->assertSame(3, $made);
		$this->assertGreaterThan(2.5, $elapsed, 'the retries should have waited 1s and then 2s');
	}

	public function testAThumbnailUrlTheRemoteSuppliesIsPassedStraightThrough() {
		$url = 'https://upload.example.org/commons/thumb/a/ab/Bakery_oven.jpg/32px-Bakery_oven.jpg';
		$this->answerWith([['body' => $this->imageInfo($url)]], $made);

		$this->assertSame($url, $this->newRepo()->getThumbUrlFromCache('Bakery oven.jpg', 32, -1));
	}

	public function testAThumbnailTheRemoteCannotSupplyEndsTheBuildWithAnAnswerableMessage() {
		// The remote answers, but with no thumburl: core would hand that false to
		// ForeignAPIFile::transform(), whose error path dies with "Need to set language
		// before accessing" several frames away from anything that names the file.
		$this->answerWith([['body' => $this->imageInfo(null)]], $made);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Xclamation SVG.svg');
		$this->newRepo()->getThumbUrlFromCache('Xclamation SVG.svg', 32, -1);
	}

	/**
	 * Every lookup this repository makes says which tool made it and where to go about it.
	 *
	 * Core would sign them "MediaWiki/" and its version, which names the library rather than
	 * the thing that asked, and Commons is the server a bake asks most: one page with ten
	 * images is ten lookups. UserAgent holds the string; this is the wiring that carries it.
	 */
	public function testALookupSaysWhichToolIsAskingAndWhereToGoAboutIt() {
		$agents = [];
		$this->installMockHttp(function ($url, $options) use (&$agents) {
			$agents[] = (string)( $options['userAgent'] ?? '' );
			return $this->makeFakeHttpRequest(
				$this->imageInfo('https://upload.example.org/commons/thumb/32px-Bakery_oven.jpg'),
				200
			);
		});

		$this->newRepo()->getThumbUrlFromCache('Bakery oven.jpg', 32, -1);

		$this->assertNotSame([], $agents, 'the lookup should have made a request');
		foreach ($agents as $agent) {
			$this->assertStringStartsWith('Wikven/', $agent);
			$this->assertStringContainsString('(+https://github.com/chaotic-ground/wikven)', $agent);
			$this->assertStringContainsString('ForeignAPIRepo/', $agent);
		}
	}

	/**
	 * And keeps everything core said, including what a site asked for: the tool goes in front
	 * of that string rather than in place of it, so the library, the class and a site's own
	 * contact are all still there.
	 */
	public function testTheStringCoreBuildsIsKeptBehindIt() {
		$agent = $this->newRepo(['userAgent' => 'Example Wiki (admin@example.org)'])->getUserAgent();

		$this->assertStringStartsWith('Wikven/', $agent);
		$this->assertStringContainsString('MediaWiki/', $agent);
		$this->assertStringContainsString('ForeignAPIRepo/', $agent);
		$this->assertStringContainsString('Example Wiki (admin@example.org)', $agent);
	}

	public function testTheMessageSaysWhichRepositoryAndSizeAndWhatToDo() {
		$this->answerWith([['body' => $this->imageInfo(null)]], $made);

		try {
			$this->newRepo()->getThumbUrlFromCache('Xclamation SVG.svg', 32, 64);
			$this->fail('a thumbnail the remote cannot supply should end the build');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('testcommons', $e->getMessage());
			$this->assertStringContainsString('32x64', $e->getMessage());
			$this->assertStringContainsString('CA certificates', $e->getMessage());
		}
	}
}
