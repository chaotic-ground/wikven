<?php

namespace MediaWiki\Extension\Wikven\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\Wikven\SkippedHistoryAction;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Article;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\Wikven\SkippedHistoryAction
 */
class SkippedHistoryActionTest extends MediaWikiUnitTestCase {
	public function testItAnswersToHistory() {
		$this->assertSame('history', $this->action($this->createMock(OutputPage::class))->getName());
	}

	public function testShowingItRendersNothing() {
		// Disabling the output is the whole of the saving: rebuildFileCache.php calls
		// OutputPage::output() on the same context right after, which then returns at once.
		$output = $this->createMock(OutputPage::class);
		$output->expects($this->once())->method('disable');

		$this->action($output)->show();
	}

	private function action(OutputPage $output): SkippedHistoryAction {
		$context = $this->createMock(IContextSource::class);
		$context->method('getOutput')->willReturn($output);
		return new SkippedHistoryAction($this->createMock(Article::class), $context);
	}
}
