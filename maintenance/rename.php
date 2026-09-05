<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Move each cached page to the file the site is served from; see OutputName.
 *
 * The file cache names a page "ns<N>%3A<escaped dbkey>.html". OutputName says what that becomes --
 * a readable namespace, the dots and subpage slashes restored, and the rest of the escaping either
 * undone or left standing depending on the site's setting -- and every link the build wrote names
 * the same destination, because it asked the same class from the other side.
 */
class Rename extends Maintenance {
	/**
	 * How many pages this pass named, for the caller that has to say what the pass produced.
	 *
	 * The build reads it back out of the object it ran; see Build::renderSkin(), which is what
	 * turns a number nobody was checking into the pass's proof that it finished.
	 */
	public int $named = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription('Move each cached page to the file the site is served from');
	}

	public function execute() {
		global $wgWikvenHtmlDirectory;
		$path = rtrim($wgWikvenHtmlDirectory, '/');

		$namespaceText = MediaWikiServices::getInstance()->getContentLanguage()->getNsText(...);

		$moved = 0;
		foreach (glob("$path/*") ?: [] as $filename) {
			if (!is_file($filename)) {
				continue;
			}
			$basename = basename($filename);
			$name = OutputName::fromCache($basename, $namespaceText);
			// Anything the file cache did not write comes back as it went in and stays where it is.
			if ($name === $basename) {
				continue;
			}
			$this->place($path, $filename, $name);
			$moved++;
		}
		$this->named = $moved;
		$this->output("Wikven: named $moved cached page(s) as the site serves them\n");
	}

	/**
	 * Put one page where its name says it goes.
	 *
	 * A subpage title such as "Manual/Config" caches to a flat file and is exported into a real
	 * "Manual/" directory, so its root-relative references need a "../" per level to keep pointing
	 * at the same files; a page at the root is a plain move.
	 */
	private function place(string $path, string $filename, string $name): void {
		$destination = "$path/$name";
		$depth = substr_count($name, '/');
		if ($depth === 0) {
			rename($filename, $destination);
			return;
		}
		$directory = dirname($destination);
		if (!is_dir($directory) && !wfMkdirParents($directory)) {
			$this->fatalError("Wikven: could not create directory $directory");
		}
		$html = (string)file_get_contents($filename);
		file_put_contents($destination, RelativeUrl::reparent($html, $depth), LOCK_EX);
		unlink($filename);
	}
}

$maintClass = Rename::class;
require_once RUN_MAINTENANCE_IF_MAIN;
