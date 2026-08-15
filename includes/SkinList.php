<?php

namespace MediaWiki\Extension\Wikven;

use MediaWiki\MediaWikiServices;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;

/** Each enabled skin's copy of the current page: the switcher, wherever a skin can show it. */
class SkinList {
	/**
	 * The href is the root-relative form every other link is written in ("./x" from the main skin's
	 * output, "../x" from a skin subdirectory), so rename.php reparents these by the page's own
	 * depth along with the rest and a subpage's links stay correct.
	 *
	 * @return list<array{id:string,text:string,href:?string,active:bool}> The current skin's own
	 *   entry has no href. Empty when there is nothing to switch between.
	 */
	public static function entries(Skin $skin): array {
		global $wgWikvenSkins, $wgWikvenMainSkin;

		$skins = $wgWikvenSkins ?? [];
		$title = $skin->getTitle();
		if (count($skins) < 2 || !$title || !$title->canExist()) {
			return [];
		}

		$current = $skin->getSkinName();
		$page = Title::makeName($title->getNamespace(), $title->getDBkey()) . '.html';
		$root = $current === $wgWikvenMainSkin ? './' : '../';

		$entries = [];
		foreach ($skins as $target) {
			$directory = $target === $wgWikvenMainSkin ? '' : "$target/";
			$entries[] = [
				'id' => "t-wikven-skin-$target",
				'text' => self::label($skin, $target),
				'href' => $target === $current ? null : $root . $directory . $page,
				'active' => $target === $current
			];
		}
		return $entries;
	}

	/** A skin's human-readable name; getInstalledSkins() only resolves an explicit displayname. */
	private static function label(Skin $skin, string $name): string {
		$message = $skin->msg("skinname-$name");
		if (!$message->isDisabled() && $message->exists()) {
			return $message->text();
		}
		$installed = MediaWikiServices::getInstance()->getSkinFactory()->getInstalledSkins();
		return $installed[$name] ?? ucwords(str_replace('-', ' ', $name));
	}
}
