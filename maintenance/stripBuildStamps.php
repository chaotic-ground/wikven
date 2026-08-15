<?php

namespace MediaWiki\Extension\Wikven;

use FilesystemIterator;
use Maintenance;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/** Drop the request ids, render ids and timestamps MediaWiki stamps into every rendered page. */
class StripBuildStamps extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Remove per-build metadata from the rendered pages.');
	}

	public function execute() {
		global $wgWikvenHtmlDirectory;
		$dir = rtrim((string)$wgWikvenHtmlDirectory, '/');
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
			$stripped = BuildStamps::strip($html);
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
