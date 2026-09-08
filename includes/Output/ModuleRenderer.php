<?php

namespace MediaWiki\Extension\Wikven\Output;

use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\Module;
use MediaWiki\ResourceLoader\ResourceLoader;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Throwable;

/** Renders ResourceLoader modules to a string, without the load.php HTTP response around them. */
class ModuleRenderer {
	/**
	 * Render the modules named by $context and return the response body.
	 *
	 * ResourceLoader::respond() sends response headers first, and the build drives it from CLI
	 * scripts that have already written to stdout. makeModuleResponse() is the body alone.
	 *
	 * @param ResourceLoader $rl
	 * @param Context $context Modules, language, skin and 'only' mode to render.
	 * @return string The rendered CSS, JS or image bytes.
	 */
	public static function render(ResourceLoader $rl, Context $context): string {
		$modules = [];
		$missing = [];
		foreach ($context->getModules() as $name) {
			$module = $rl->getModule($name);
			if (!$module) {
				// As in respond(): an unregistered module is reported in the body itself (a
				// "missing" load state, or a comment in a styles response), not thrown.
				$missing[] = $name;
				continue;
			}
			if ($module->getGroup() === Module::GROUP_PRIVATE) {
				// respond() refuses these ("Cannot build private module") because they carry
				// per-user data (T36907). Nothing in the build should ask for one, so stop
				// rather than write out a file whose only content is that error.
				throw new RuntimeException("Wikven: refusing to dump the private module '$name'.");
			}
			$modules[$name] = $module;
		}

		// Batches the version and message-blob lookups the modules would otherwise make one at a time,
		// as respond() does. respond() turns a failure here into an error comment; let it escape.
		$rl->preloadModuleInfo(array_keys($modules), $context);

		// makeModuleResponse() catches a throwing module, logs it and leaves an "error" load state in
		// the body; respond() would have written the exception into the dumped file. Watch the logger.
		$previousLogger = $rl->getLogger();
		$collector = new class extends AbstractLogger {
			/** @var string[] Messages logged for a module that could not be built. */
			public array $failures = [];

			/** @var LoggerInterface The logger this one stands in front of. */
			public LoggerInterface $inner;

			/**
			 * @param mixed $level
			 * @param string $message
			 * @param array $context
			 */
			public function log($level, $message, array $context = []): void {
				// ResourceLoader::outputErrorAndLog(), the one place a module-generation
				// exception is swallowed, always logs it with the exception attached.
				$levels = [
					LogLevel::WARNING,
					LogLevel::ERROR,
					LogLevel::CRITICAL,
					LogLevel::ALERT,
					LogLevel::EMERGENCY
				];
				$exception = $context['exception'] ?? null;
				if ($exception instanceof Throwable && in_array((string)$level, $levels, true)) {
					$this->failures[] = get_class($exception) . ': ' . $exception->getMessage();
				}
				$this->inner->log($level, $message, $context);
			}
		};
		$collector->inner = $previousLogger;

		$rl->setLogger($collector);
		try {
			$body = $rl->makeModuleResponse($context, $modules, $missing);
			if ($collector->failures !== []) {
				throw new RuntimeException(
					'Wikven: ResourceLoader failed to build ' . implode(', ', array_keys($modules)) . ":\n"
						. implode("\n", $collector->failures)
				);
			}
			return self::noteMissingModules($context, $missing) . $body;
		} finally {
			$rl->setLogger($previousLogger);
		}
	}

	/**
	 * The comment respond() would have prefixed for modules that are not registered.
	 *
	 * Nothing in the build asks for one, so this exists to keep a broken build's output what it
	 * always was.
	 *
	 * @param Context $context
	 * @param string[] $missing Requested module names that are not registered.
	 * @return string The comment, or '' when there is nothing to report.
	 */
	private static function noteMissingModules(Context $context, array $missing): string {
		if ($missing === []) {
			return '';
		}
		if ($context->shouldIncludeScripts() && !$context->getRaw()) {
			return '';
		}
		$states = array_fill_keys($missing, 'missing');
		// makeModuleResponse() silences encodeJson()'s warning here because the names came off a web
		// request, where invalid UTF-8 is a client error (T331641). The build's come from its registry.
		return ResourceLoader::makeComment('Problematic modules: ' . $context->encodeJson($states));
	}
}
