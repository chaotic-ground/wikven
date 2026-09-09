<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use MediaWiki\Hook\SetupAfterCacheHook;
use MediaWiki\HookContainer\HookContainer;

/**
 * Keeps a page-description lookup off pages an export never holds.
 *
 * WikiSEO gives every saved page a deferred update that asks the API for a description. A
 * translation unit is Translate's storage, and a bake saves a thousand of them.
 *
 * Registered rather than declared: taking back what another handler adds means running after it,
 * and a registered handler is ordered last.
 */
class Sparer implements SetupAfterCacheHook {
	/** Named as a string: WikiSEO is a site's to load, so the class may not be here to reference. */
	private const DESCRIPTION_UPDATE = 'MediaWiki\\Extension\\WikiSEO\\DeferredDescriptionUpdate';

	private HookContainer $hookContainer;

	/** @var class-string|string The update to drop; a seam, so the rule can be tested without WikiSEO. */
	private string $updateClass;

	public function __construct(HookContainer $hookContainer, string $updateClass = self::DESCRIPTION_UPDATE) {
		$this->hookContainer = $hookContainer;
		$this->updateClass = $updateClass;
	}

	/** @inheritDoc */
	public function onSetupAfterCache(): void {
		$this->hookContainer->register('RevisionDataUpdates', [$this, 'onRevisionDataUpdates']);
	}

	/**
	 * Drop the description update where the page it would describe is not one the export has.
	 *
	 * @param \MediaWiki\Title\Title $title
	 * @param \MediaWiki\Revision\RenderedRevision $renderedRevision
	 * @param \DeferrableUpdate[] &$updates
	 */
	public function onRevisionDataUpdates($title, $renderedRevision, &$updates): void {
		if (!defined('NS_TRANSLATIONS') || $title->getNamespace() !== NS_TRANSLATIONS) {
			return;
		}
		$kept = [];
		foreach ($updates as $update) {
			if (get_class($update) !== $this->updateClass) {
				$kept[] = $update;
			}
		}
		$updates = $kept;
	}
}
