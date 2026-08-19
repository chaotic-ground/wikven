<?php

namespace MediaWiki\Extension\Wikven;

use FilesystemIterator;
use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Settings\Source\Format\JsonFormat;
use MediaWiki\Settings\Source\Format\YamlFormat;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

// Wikven's autoloader is not active this early: making the extensions MediaWiki will load present is
// what this script is for, so it runs before that. Loaded directly for the same reason loadConfig()
// loads SiteConfig directly below, and the class is dependency-free so that is all it takes.
require_once __DIR__ . '/../includes/Attempts.php';

/** Fetch third-party extensions/skins declared in .wikven.yaml before MediaWiki loads them. */
class FetchExtensions extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Fetch third-party extensions and skins declared in .wikven.yaml.'
		);
	}

	public function execute() {
		$IP = $GLOBALS['IP'];

		$config = $this->loadConfig($IP);

		$repos = $config['config']['WikvenRepositories'] ?? [];
		if (!is_array($repos) || $repos === []) {
			return;
		}

		// Which list a name is in tells us where to put it.
		$extensionNames = $this->nameSet($config['extensions'] ?? []);
		$skinNames = $this->nameSet($config['skins'] ?? []);

		// Composer's superuser warning would otherwise abort under set -e.
		putenv('COMPOSER_ALLOW_SUPERUSER=1');

		$packages = [];
		foreach ($repos as $name => $spec) {
			if (!is_string($name) || !is_array($spec)) {
				$this->fatalError('Wikven: each WikvenRepositories entry must be a name mapped to a source.');
			}
			if ($name === '' || strpbrk($name, "/\\") !== false || str_starts_with($name, '.')) {
				$this->fatalError("Wikven: invalid component name '$name' in WikvenRepositories.");
			}

			[$baseDir, $kind] = $this->target($name, $extensionNames, $skinNames);

			if (isset($spec['package'])) {
				// Composer picks the install dir from the package; just collect the requirement.
				if (!is_string($spec['package']) || $spec['package'] === '') {
					$this->fatalError("Wikven: $kind '$name' has an empty 'package'.");
				}
				[$pkgName, $constraint] = $this->splitPackage($spec['package']);
				$packages[$pkgName] = $constraint;
				$this->output("Wikven: will install $kind '$name' as composer package $pkgName ($constraint)\n");
				continue;
			}

			$dest = "$baseDir/$name";
			if (is_dir($dest)) {
				// Already bundled or cloned by an earlier run; never overwrite it.
				$this->output("Wikven: $kind '$name' already present, skipping.\n");
				continue;
			}

			if (isset($spec['tarball'])) {
				$sha256 = $this->validatedSha256($spec, $name, $kind);
				$this->fetchTarball((string)$spec['tarball'], $dest, $name, $kind, $sha256);
			} elseif (isset($spec['repository'])) {
				$this->fetchGit($spec, $dest, $name, $kind);
			} else {
				$this->fatalError(
					"Wikven: $kind '$name' must declare one of: tarball, repository, package."
				);
			}
		}

		if ($packages !== []) {
			$this->installPackages($IP, $packages);
		}
	}

	/** Merge default.yml + site config like WikvenSettings.php, so fetches match the load. */
	private function loadConfig(string $IP): array {
		$yaml = new YamlFormat();
		$config = $yaml->decode(file_get_contents("$IP/extensions/Wikven/default.yml"));

		// Wikven's autoloader is not active yet; load the dependency-free helper directly.
		require_once "$IP/extensions/Wikven/includes/SiteConfig.php";
		$work = getenv('WIKVEN_WORKDIR');
		$src = $work !== false && $work !== '' ? "$work/src" : '/workspace/src';
		$siteFile = SiteConfig::locate($src)['path'];
		if ($siteFile !== null) {
			$format = str_ends_with($siteFile, '.json') ? new JsonFormat() : $yaml;
			$site = $format->decode(file_get_contents($siteFile));
			$config['config'] = array_merge($config['config'] ?? [], $site['config'] ?? []);
			$config['extensions'] = array_merge($config['extensions'] ?? [], $site['extensions'] ?? []);
			$config['skins'] = array_merge($config['skins'] ?? [], $site['skins'] ?? []);
		}

		return $config;
	}

	/**
	 * @return array<string,true> Set of the string names in $list.
	 */
	private function nameSet(array $list): array {
		$set = [];
		foreach ($list as $entry) {
			if (is_string($entry)) {
				$set[$entry] = true;
			}
		}
		return $set;
	}

	/**
	 * @return array{0:string,1:string} [base directory, kind]
	 */
	private function target(string $name, array $extensionNames, array $skinNames): array {
		$IP = $GLOBALS['IP'];
		if (isset($extensionNames[$name])) {
			return ["$IP/extensions", 'extension'];
		}
		if (isset($skinNames[$name])) {
			return ["$IP/skins", 'skin'];
		}
		$this->fatalError(
			"Wikven: WikvenRepositories entry '$name' is not listed in extensions: or skins:."
		);
	}

	/**
	 * @param string $package "vendor/name" or "vendor/name:constraint"
	 * @return array{0:string,1:string} [package name, version constraint]
	 */
	private function splitPackage(string $package): array {
		$pos = strpos($package, ':');
		if ($pos === false) {
			return [$package, '*'];
		}
		return [substr($package, 0, $pos), substr($package, $pos + 1)];
	}

	/** The lowercased, validated `sha256:` of a tarball spec, or null if absent. */
	private function validatedSha256(array $spec, string $name, string $kind): ?string {
		if (!isset($spec['sha256'])) {
			return null;
		}
		$sha = strtolower(trim((string)$spec['sha256']));
		if (!preg_match('/^[0-9a-f]{64}$/', $sha)) {
			$this->fatalError("Wikven: $kind '$name' has an invalid sha256 (expected 64 hex characters).");
		}
		return $sha;
	}

	/** The validated `commit:` SHA, or null; errors if combined with `reference:`. */
	private function validatedCommit(array $spec, string $name, string $kind): ?string {
		if (empty($spec['commit'])) {
			return null;
		}
		if (!empty($spec['reference'])) {
			$this->fatalError("Wikven: $kind '$name' sets both 'commit' and 'reference'; use one.");
		}
		$commit = strtolower(trim((string)$spec['commit']));
		if (!preg_match('/^[0-9a-f]{7,64}$/', $commit)) {
			$this->fatalError("Wikven: $kind '$name' has an invalid commit (expected a hex SHA).");
		}
		return $commit;
	}

	private function fetchTarball(string $url, string $dest, string $name, string $kind, ?string $sha256 = null): void {
		$this->output("Wikven: downloading $kind '$name' from $url\n");
		$tmp = tempnam(sys_get_temp_dir(), 'wikven');
		$this->download($url, $tmp, "download $kind '$name'");
		if ($sha256 !== null) {
			$actual = hash_file('sha256', $tmp);
			if (!hash_equals($sha256, (string)$actual)) {
				unlink($tmp);
				$this->fatalError(
					"Wikven: $kind '$name' tarball sha256 mismatch (expected $sha256, got $actual)."
				);
			}
			$this->output("Wikven: verified $kind '$name' tarball sha256\n");
		}
		if (!mkdir($dest, 0777, true) && !is_dir($dest)) {
			$this->fatalError("Wikven: could not create '$dest'.");
		}
		$this->run(['tar', '-xzf', $tmp, '-C', $dest, '--strip-components=1'], "extract $kind '$name'");
		unlink($tmp);
	}

	private function fetchGit(array $spec, string $dest, string $name, string $kind): void {
		$repo = (string)$spec['repository'];
		$commit = $this->validatedCommit($spec, $name, $kind);

		if ($commit !== null) {
			// `git clone --branch` rejects a SHA, so fetch it into a fresh repo and detach.
			$this->output("Wikven: cloning $kind '$name' from $repo @ $commit\n");
			$this->run(['git', 'init', '--quiet', '--', $dest], "init $kind '$name'");
			$this->run(['git', '-C', $dest, 'remote', 'add', 'origin', $repo], "configure $kind '$name'");
			// No reset: a fetch that failed leaves a repository another fetch is happy to reuse.
			$this->runOnNetwork(
				['git', '-C', $dest, 'fetch', '--depth', '1', 'origin', $commit],
				"fetch $kind '$name' @ $commit"
			);
			$this->run(['git', '-C', $dest, 'checkout', '--quiet', '--detach', 'FETCH_HEAD'], "checkout $kind '$name'");
		} else {
			$cmd = ['git', 'clone', '--depth', '1'];
			if (!empty($spec['reference'])) {
				// --branch takes a tag or a branch (not an arbitrary commit SHA).
				$cmd[] = '--branch';
				$cmd[] = (string)$spec['reference'];
			}
			array_push($cmd, '--', $repo, $dest);
			$ref = !empty($spec['reference']) ? " @ {$spec['reference']}" : '';
			$this->output("Wikven: cloning $kind '$name' from $repo$ref\n");
			// git clone refuses a destination that already has anything in it, and a clone that died
			// partway leaves one, so the leftovers go before the next attempt.
			$this->runOnNetwork($cmd, "clone $kind '$name'", static function () use ($dest) {
				self::removeTree($dest);
			});
		}

		if (!empty($spec['composer'])) {
			$this->runOnNetwork(
				['composer', 'update', '--no-dev', '--no-interaction', '--working-dir=' . $dest],
				"composer install for $kind '$name'"
			);
		}
	}

	private function installPackages(string $IP, array $packages): void {
		// Core merges composer.local.json via composer-merge-plugin; require there, then update.
		$localFile = "$IP/composer.local.json";
		$local = is_file($localFile)
			? ( json_decode(file_get_contents($localFile), true) ?: [] )
			: [];
		$local['require'] = array_merge($local['require'] ?? [], $packages);
		file_put_contents(
			$localFile,
			json_encode($local, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
		);

		$this->runOnNetwork(
			['composer', 'update', '--no-dev', '--no-interaction', '--working-dir=' . $IP],
			'composer update'
		);
	}

	/** Download $url to $dest, streaming the body to disk instead of buffering it in memory. */
	private function download(string $url, string $dest, string $what): void {
		$http = MediaWikiServices::getInstance()->getHttpRequestFactory();
		// Read out here: __METHOD__ inside the closure would name the closure, not this.
		$caller = __METHOD__;
		$httpStatus = 0;
		$ok = Attempts::until(
			function () use ($http, $url, $dest, $caller, &$httpStatus) {
				$req = $http->create($url, ['followRedirects' => true], $caller);
				// Opened per attempt, and truncating, so a retry replaces a partial body rather
				// than appending a second one to it.
				$fh = fopen($dest, 'wb');
				if ($fh === false) {
					$this->fatalError("Wikven: could not open '$dest' for writing.");
				}
				$req->setCallback(static function ($resource, $buffer) use ($fh) {
					return fwrite($fh, $buffer);
				});
				$status = $req->execute();
				fclose($fh);
				$httpStatus = $req->getStatus();
				return $status->isOK() && $httpStatus >= 200 && $httpStatus < 300;
			},
			Attempts::FETCH,
			function (int $attempt) use ($what, &$httpStatus) {
				$wait = Attempts::backoff($attempt);
				$this->output(
					"Wikven: failed to $what (HTTP $httpStatus); trying again in {$wait}s"
					. " (attempt $attempt of "
					. Attempts::FETCH
					. ")\n"
				);
				sleep($wait);
			}
		);
		if (!$ok) {
			unlink($dest);
			$this->fatalError("Wikven: failed to $what (HTTP $httpStatus).");
		}
	}

	/**
	 * Run an external command, aborting the build loudly if it fails.
	 *
	 * @param string[] $cmd
	 */
	private function run(array $cmd, string $what): void {
		$shell = implode(' ', array_map('escapeshellarg', $cmd));
		$ret = 0;
		passthru($shell, $ret);
		if ($ret !== 0) {
			$this->fatalError("Wikven: failed to $what (exit $ret).");
		}
	}

	/**
	 * Run a command that reaches the network, giving it more than one go before the build fails.
	 *
	 * Used for the fetching alone. The commands around it -- git init, a checkout, an extraction --
	 * work on what is already local, so one failure from those is a real one; see Attempts for why a
	 * fetch is different.
	 *
	 * @param string[] $cmd
	 * @param string $what What the command is doing, for the reports and the error.
	 * @param callable():void|null $reset Clear a half-finished attempt away, for a command that will
	 *   not run twice over its own leftovers.
	 */
	private function runOnNetwork(array $cmd, string $what, ?callable $reset = null): void {
		$shell = implode(' ', array_map('escapeshellarg', $cmd));
		$ret = 0;
		$ok = Attempts::until(
			static function () use ($shell, &$ret) {
				passthru($shell, $ret);
				return $ret === 0;
			},
			Attempts::FETCH,
			function (int $attempt) use ($what, $reset, &$ret) {
				$wait = Attempts::backoff($attempt);
				$this->output(
					"Wikven: failed to $what (exit $ret); trying again in {$wait}s"
					. " (attempt $attempt of "
					. Attempts::FETCH
					. ")\n"
				);
				if ($reset !== null) {
					$reset();
				}
				sleep($wait);
			}
		);
		if (!$ok) {
			$this->fatalError("Wikven: failed to $what (exit $ret).");
		}
	}

	/** Remove a directory and everything under it, so a failed fetch can be tried again. */
	private static function removeTree(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$entries = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($entries as $entry) {
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($dir);
	}
}

$maintClass = FetchExtensions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
