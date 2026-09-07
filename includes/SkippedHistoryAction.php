<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Actions\Action;

/**
 * The history action, registered so it can render nothing.
 *
 * rebuildFileCache.php caches two actions per page, and the ?action=history tree is deleted at the
 * end of the pass -- a query, a list and a full skin render each, thrown away (#408).
 *
 * $wgActions['history'] = false is worse: Action::factory() returns false and show() is called.
 */
class SkippedHistoryAction extends Action {
	/** @inheritDoc */
	public function getName() {
		return 'history';
	}

	/** @inheritDoc */
	public function show() {
		$this->getOutput()->disable();
	}
}
