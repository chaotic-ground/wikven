<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Actions\HistoryAction;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Wikven\Build\PreviewPages;
use MediaWiki\Extension\Wikven\Output\TitleName;
use MediaWiki\Page\Article;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Render the pages a skin preview lists beyond the content; see PreviewPages.
 *
 * Each is written where the file cache would have put it, so the passes after this one treat it
 * as they treat every other page, and rename.php gives it the name the links already say.
 */
class RenderPreviewPages extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Render the special pages, histories and diffs a skin preview lists');
	}

	public function execute() {
		$entries = PreviewPages::entries();
		if ($entries === []) {
			return;
		}
		$dir = rtrim((string)$this->getConfig()->get('WikvenHtmlDirectory'), '/');
		foreach ($entries as $entry) {
			[$text, $params] = PreviewPages::parse($entry);
			$title = Title::newFromText($text);
			$query = PreviewPages::query($params);
			if ($title === null || $query === null) {
				$this->fatalError("Wikven: WikvenPreviewExtraPages lists '$entry', which names no page.");
			}
			$html = $this->render($entry, $title, $params);
			[, $dbkey] = TitleName::of($title);
			// The file cache's own spelling of a name, which OutputName::fromCache() reads back.
			$key = 'ns' . $title->getNamespace() . ':' . PreviewPages::dbkey($dbkey, $query);
			file_put_contents("$dir/" . str_replace('.', '%2E', urlencode($key)) . '.html', $html, LOCK_EX);
		}
		$this->output('Wikven: rendered ' . count($entries) . " page(s) the preview lists\n");
	}

	/** One listed page, as a reader asking for it would have got it. */
	private function render(string $entry, Title $title, array $params): string {
		$main = RequestContext::getMain();
		$mainRequest = $main->getRequest();
		$mainTitle = $main->getTitle();

		$request = new FauxRequest(['title' => $title->getPrefixedDBkey()] + $params);
		$context = new RequestContext();
		$context->setRequest($request);
		$context->setTitle($title);
		// Skins and extensions that read the main context rather than the one they were handed.
		$main->setRequest($request);
		$main->setTitle($title);

		// HistoryAction saves itself into the file cache's history/ tree, which this export deletes.
		$noFileCache = $this->getServiceContainer()
			->getHookContainer()
			->scopedRegister('HTMLFileCache::useFileCache', static function (): bool {
				return false;
			});
		ob_start();
		try {
			$this->show($entry, $title, $params, $context);
			$output = $context->getOutput();
			if ($output->getRedirect() !== '') {
				$this->fatalError(
					"Wikven: WikvenPreviewExtraPages lists '$entry', which redirects to "
					. $output->getRedirect()
					. '; list the page it goes to instead.'
				);
			}
			$output->output();
		} finally {
			$html = (string)ob_get_clean();
			unset($noFileCache);
			$main->setRequest($mainRequest);
			$main->setTitle($mainTitle);
		}
		if ($html === '') {
			$this->fatalError("Wikven: WikvenPreviewExtraPages lists '$entry', which rendered nothing.");
		}
		return $html;
	}

	private function show(string $entry, Title $title, array $params, RequestContext $context): void {
		$services = $this->getServiceContainer();
		if ($title->getNamespace() === NS_SPECIAL) {
			$factory = $services->getSpecialPageFactory();
			[$name] = $factory->resolveAlias($title->getDBkey());
			if ($name === null || $factory->getPage($name) === null) {
				$this->fatalError("Wikven: WikvenPreviewExtraPages lists '$entry', which names no special page.");
			}
			$factory->executePath($title, $context);
			return;
		}

		$article = Article::newFromTitle($title, $context);
		$context->setWikiPage($article->getPage());
		$action = $params['action'] ?? 'view';
		if ($action === 'view') {
			// A diff is a view too: Article::view() reads "diff" and "oldid" from the request.
			$article->view();
			return;
		}
		// Built by hand because the registered one is SkippedHistoryAction, which renders nothing.
		$handler = $action === 'history'
			? new HistoryAction($article, $context)
			: $services->getActionFactory()->getAction($action, $article, $context);
		if (!$handler) {
			$this->fatalError("Wikven: WikvenPreviewExtraPages lists '$entry', whose action nothing answers.");
		}
		$handler->setHookContainer($services->getHookContainer());
		$handler->show();
	}
}

$maintClass = RenderPreviewPages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
