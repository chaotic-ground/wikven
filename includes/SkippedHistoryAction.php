<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Actions\Action;

/**
 * The history action, registered so it can render nothing.
 *
 * rebuildFileCache.php caches two actions for every page it walks: ?action=view, the export, and
 * ?action=history, which is not. The tree it writes is deleted at the end of the pass -- a query,
 * a list and a full skin render per page, thrown away each time (#408).
 *
 * Core offers no way to ask for one and not the other, and $wgActions['history'] = false is worse
 * than useless: Action::factory() returns false and show() is called on it. So the action stays
 * registered, costing nothing.
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
