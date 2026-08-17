<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\Actions\Action;

/**
 * The history action, registered so it can render nothing.
 *
 * rebuildFileCache.php caches two actions for every page it walks, unconditionally: ?action=view,
 * which is the export, and ?action=history, which is not. Nothing ever asks the export for a local
 * history page -- Hooks\Main::onGetLocalURL sends a history request to $wgWikvenHistoryUrl, and
 * Hooks\Hider removes the link entirely when that is not configured -- and the tree it writes is
 * deleted at the end of the pass. It was a database query, a formatted list and a full skin render
 * per page, in every pass, on every bake, thrown away in full each time (#408).
 *
 * Core offers no way to ask for one action and not the other: the script has no --actions option,
 * and turning history off with $wgActions['history'] = false is worse than useless, since
 * Action::factory() then returns false and the script calls show() on it. So the action stays
 * registered, as something that costs nothing to run: OutputPage::output() returns immediately
 * once disabled, so the script's capture comes back empty, and HTMLFileCache::saveToFileCache()
 * writes nothing for output under 512 bytes. No render, no file, no history/ tree.
 *
 * Only the bake loads this. A wiki serving the history action wants core's own HistoryAction.
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
