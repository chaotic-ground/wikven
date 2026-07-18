<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

class Rename extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Replace the "ns:" prefix with readable text');
	}

	public function execute() {
		global $wgWikvenHtmlDirectory;
		$path = $wgWikvenHtmlDirectory;
		if (str_ends_with($path, '/')) {
			$path = rtrim($path, '/');
		}

		foreach (glob("$path/ns*") as $filename) {
			$basename = basename($filename);
			if (!preg_match('/^ns(\d+)%3A/', $basename, $matches)) {
				$this->output("$basename is not matched\n");
				continue;
			}

			$ns = $matches[1];
			if (!ctype_digit($ns)) {
				$this->output("$ns is a number\n");
				continue;
			}

			$nsText = MediaWikiServices::getInstance()
				->getContentLanguage()
				->getNsText((int)$ns);
			$newName = preg_replace("/^ns$ns%3A/", "$nsText:", $basename);
			$newName = ltrim($newName, ':');
			rename("$path/$basename", "$path/$newName");
		}

		foreach (glob("$path/*") as $filename) {
			$basename = basename($filename);
			if (!str_contains($basename, '.')) {
				continue;
			}
			$newName = preg_replace('/%2E/', '.', $basename);
			rename("$path/$basename", "$path/$newName");
		}

		// Subpage titles (e.g. "Manual/Config") cache to a flat "Manual%2FConfig.html", but links to
		// them keep the slash. Move each into a real subdirectory and rebase its root-relative
		// references by that depth, so links and files agree when served from a static host.
		foreach (glob("$path/*%2F*") as $filename) {
			$basename = basename($filename);
			$depth = substr_count($basename, '%2F');
			$destination = "$path/" . str_replace('%2F', '/', $basename);
			$directory = dirname($destination);
			if (!wfMkdirParents($directory)) {
				$this->fatalError("Wikven: could not create directory $directory");
			}
			file_put_contents($destination, RelativeUrl::reparent(file_get_contents($filename), $depth), LOCK_EX);
			unlink($filename);
		}
	}
}

$maintClass = Rename::class;
require_once RUN_MAINTENANCE_IF_MAIN;
