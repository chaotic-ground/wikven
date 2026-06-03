<?php

namespace MediaWiki\Extension\Wikven;

use ContentHandler;
use Maintenance;
use MediaWiki\Revision\SlotRecord;
use StubGlobalUser;
use Title;
use User;
use WikiRevision;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

class ImportWikitext extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription('Import *.wikitext files from the given path');
	}

	/**
	 * @return bool Whether every file was imported successfully.
	 */
	public function execute() {
		global $wgWikvenSourceDirectory;
		$sourceDirectory = rtrim($wgWikvenSourceDirectory, '/');

		$user = User::newSystemUser(User::MAINTENANCE_SCRIPT_USER, ['steal' => true]);
		StubGlobalUser::setUser($user);

		$ok = true;
		foreach (glob("$sourceDirectory/*.wikitext") as $filename) {
			$title = Title::newFromText($this->filenameToTitle($filename));
			if (!$title) {
				$this->output('Invalid title: ' . basename($filename) . "\n");
				continue;
			}

			$text = file_get_contents($filename);
			$content = ContentHandler::makeContent($text, $title);

			// Import as an old revision (like core's importTextFiles.php with
			// --use-timestamp) so the source file's modification time becomes the
			// revision timestamp and the footer shows the real last-modified date
			// instead of the build time.
			$this->output("Saving... $title");
			$revision = new WikiRevision();
			$revision->setContent(SlotRecord::MAIN, $content);
			$revision->setTitle($title);
			$revision->setUserObj($user);
			$revision->setComment('');
			$revision->setTimestamp(wfTimestamp(TS_UNIX, filemtime($filename)));

			if ($revision->importOldRevision()) {
				$this->output(" done\n");
			} else {
				$this->output(" failed\n");
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * @param string $name
	 * @return string
	 */
	private function filenameToTitle($name) {
		$name = basename($name);
		$name = preg_replace('/\.wikitext$/', '', $name);
		return $name;
	}
}

$maintClass = ImportWikitext::class;
require_once RUN_MAINTENANCE_IF_MAIN;
