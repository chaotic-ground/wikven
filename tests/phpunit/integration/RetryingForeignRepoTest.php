<?php

namespace MediaWiki\Extension\Wikven\Tests\Integration;

use MediaWiki\Extension\Wikven\RetryingForeignRepo;
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

	/** A repository with its own empty cache, so one test's lookups cannot answer another's. */
	private function newRepo(): RetryingForeignRepo {
		return new RetryingForeignRepo([
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
		]);
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
			$headers = isset($response['lastModified'])
				? ['Last-Modified' => $response['lastModified']]
				: [];
			return $this->makeFakeHttpRequest($response['body'], 200, $headers);
		});
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
