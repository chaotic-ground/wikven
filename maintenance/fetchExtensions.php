<?php

namespace MediaWiki\Extension\Wikven;

use Maintenance;
use MediaWiki\Settings\Source\Format\JsonFormat;
use MediaWiki\Settings\Source\Format\YamlFormat;

$IP = strval(getenv('MW_INSTALL_PATH')) !== ''
	? getenv('MW_INSTALL_PATH')
	: realpath(__DIR__ . '/../../../');

require_once "$IP/maintenance/Maintenance.php";

/**
 * Fetch third-party extensions and skins before MediaWiki loads them.
 *
 * The `extensions:` and `skins:` lists in .wikven.yaml stay plain name lists, the
 * same shape MediaWiki's own settings format accepts. A name that is not bundled
 * in the image is fetched here, from a source declared in the `WikvenRepositories`
 * config map. Each entry picks a method by which key it carries:
 *
 *   - `tarball:`    download and extract a tarball (e.g. from ExtensionDistributor,
 *                   whose tarballs already bundle their dependencies).
 *   - `repository:` git clone (plus optional `reference:` tag/branch, and
 *                   `composer: true` to run composer inside the cloned directory).
 *   - `package:`    a Composer "vendor/name:constraint" installed via the core
 *                   composer.local.json (resolved by composer-merge-plugin).
 *
 * Whether a name is an extension or a skin is inferred from which list it appears
 * in, so it is never written twice. This runs after install but before
 * WikvenSettings is wired into LocalSettings, so its skins/extensions loops do not
 * yet fire and no "not bundled" warning is logged while a component is still being
 * fetched.
 */
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
				// Composer chooses the install directory from the package, so there
				// is nothing to place by hand; just collect the requirement.
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
				// Already bundled in the image, or cloned by an earlier run. The
				// bundled copy wins; we never overwrite it.
				$this->output("Wikven: $kind '$name' already present, skipping.\n");
				continue;
			}

			if (isset($spec['tarball'])) {
				$this->fetchTarball((string)$spec['tarball'], $dest, $name, $kind);
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

	/**
	 * Read and merge default.yaml + the site's .wikven.yaml/.json, exactly as
	 * WikvenSettings.php does, so the fetched set matches what will be loaded.
	 *
	 * @param string $IP
	 * @return array
	 */
	private function loadConfig($IP) {
		$yaml = new YamlFormat();
		$config = $yaml->decode(file_get_contents("$IP/extensions/Wikven/default.yaml"));

		$siteFile = null;
		if (file_exists('/workspace/src/.wikven.yaml')) {
			$siteFile = '/workspace/src/.wikven.yaml';
		} elseif (file_exists('/workspace/src/.wikven.json')) {
			$siteFile = '/workspace/src/.wikven.json';
		}
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
	 * @param array $list
	 * @return array<string,true> Set of the string names in $list.
	 */
	private function nameSet(array $list) {
		$set = [];
		foreach ($list as $entry) {
			if (is_string($entry)) {
				$set[$entry] = true;
			}
		}
		return $set;
	}

	/**
	 * @param string $name
	 * @param array<string,true> $extensionNames
	 * @param array<string,true> $skinNames
	 * @return array{0:string,1:string} [base directory, kind]
	 */
	private function target($name, array $extensionNames, array $skinNames) {
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
	private function splitPackage($package) {
		$pos = strpos($package, ':');
		if ($pos === false) {
			return [$package, '*'];
		}
		return [substr($package, 0, $pos), substr($package, $pos + 1)];
	}

	private function fetchTarball($url, $dest, $name, $kind) {
		$this->output("Wikven: downloading $kind '$name' from $url\n");
		$tmp = tempnam(sys_get_temp_dir(), 'wikven');
		$this->run(['curl', '-sSL', '-f', '-o', $tmp, '--', $url], "download $kind '$name'");
		if (!mkdir($dest, 0777, true) && !is_dir($dest)) {
			$this->fatalError("Wikven: could not create '$dest'.");
		}
		$this->run(['tar', '-xzf', $tmp, '-C', $dest, '--strip-components=1'], "extract $kind '$name'");
		unlink($tmp);
	}

	private function fetchGit(array $spec, $dest, $name, $kind) {
		$repo = (string)$spec['repository'];
		$cmd = ['git', 'clone', '--depth', '1'];
		if (!empty($spec['reference'])) {
			// --branch takes a tag or a branch (not an arbitrary commit SHA).
			$cmd[] = '--branch';
			$cmd[] = (string)$spec['reference'];
		}
		array_push($cmd, '--', $repo, $dest);
		$this->output(
			"Wikven: cloning $kind '$name' from $repo"
			. ( !empty($spec['reference']) ? " @ {$spec['reference']}" : '' )
			. "\n"
		);
		$this->run($cmd, "clone $kind '$name'");

		if (!empty($spec['composer'])) {
			$this->run(
				['composer', 'update', '--no-dev', '--no-interaction', '--working-dir=' . $dest],
				"composer install for $kind '$name'"
			);
		}
	}

	/**
	 * @param string $IP
	 * @param array<string,string> $packages package name => constraint
	 */
	private function installPackages($IP, array $packages) {
		// Core's composer.json merges composer.local.json via composer-merge-plugin,
		// so registering the requirement there and running composer update at the
		// root pulls each package (and its dependencies) into place.
		$localFile = "$IP/composer.local.json";
		$local = is_file($localFile)
			? ( json_decode(file_get_contents($localFile), true) ?: [] )
			: [];
		$local['require'] = array_merge($local['require'] ?? [], $packages);
		file_put_contents(
			$localFile,
			json_encode($local, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
		);

		$this->run(
			['composer', 'update', '--no-dev', '--no-interaction', '--working-dir=' . $IP],
			'composer update'
		);
	}

	/**
	 * Run an external command, aborting the build if it fails. A declared
	 * third-party dependency that cannot be fetched must fail loudly rather than
	 * silently produce a wiki missing the feature.
	 *
	 * @param string[] $cmd
	 * @param string $what
	 */
	private function run(array $cmd, $what) {
		$shell = implode(' ', array_map('escapeshellarg', $cmd));
		$ret = 0;
		passthru($shell, $ret);
		if ($ret !== 0) {
			$this->fatalError("Wikven: failed to $what (exit $ret).");
		}
	}
}

$maintClass = FetchExtensions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
