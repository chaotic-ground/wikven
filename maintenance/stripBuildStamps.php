<?php

namespace MediaWiki\Extension\Wikven;

use FilesystemIterator;
use Maintenance;
use MediaWiki\Extension\Wikven\Build\BuildStamps;
use MediaWiki\Extension\Wikven\Build\ChartIds;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Drop what a rendered page records about the run that rendered it rather than about itself. */
class StripBuildStamps extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Remove per-build metadata from the rendered pages.');
	}

	public function execute() {
		$dir = rtrim((string)$this->getConfig()->get('WikvenHtmlDirectory'), '/');
		if ($dir === '' || !is_dir($dir)) {
			return;
		}

		$changed = 0;
		// A configured directory containing a glob metacharacter would make a glob pattern built from it
		// match nothing, silently leaving a per-build request id in every page.
		foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'html') {
				continue;
			}
			$html = (string)file_get_contents($file->getPathname());
			// Chart's renderer numbers a drawing's ids per process, so the same chart comes back
			// numbered differently on every build; ChartIds gives them numbers of the page's own.
			$stripped = ChartIds::renumber(BuildStamps::strip($html));
			if ($stripped !== $html) {
				file_put_contents($file, $stripped, LOCK_EX);
				$changed++;
			}
		}
		$this->output("Stripped build stamps from $changed page(s)\n");
	}
}

$maintClass = StripBuildStamps::class;
require_once RUN_MAINTENANCE_IF_MAIN;
