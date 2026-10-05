<?php

namespace MediaWiki\Extension\Wikven\Output;

use MediaWiki\Request\WebRequest;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\ResourceLoader;

/**
 * A ResourceLoader context read in a direction its language does not read.
 *
 * load.php no longer takes a direction, so this is the one way to flip styles without a whole
 * language. The direction joins the hash because a module keeps what it rendered by that alone.
 */
class DirectionalContext extends Context {
	/**
	 * @param ResourceLoader $resourceLoader
	 * @param WebRequest $request The load.php request to answer.
	 * @param string $direction "ltr" or "rtl".
	 */
	public function __construct(ResourceLoader $resourceLoader, WebRequest $request, string $direction) {
		parent::__construct($resourceLoader, $request);
		$this->direction = $direction;
	}

	public function getHash(): string {
		return parent::getHash() . '|' . $this->getDirection();
	}
}
