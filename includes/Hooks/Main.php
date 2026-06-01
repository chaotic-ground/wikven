<?php

namespace MediaWiki\Extension\Wikven\Hooks;

use Config;
use FauxRequest;
use Html;
use OutputPage;
use ResourceLoader;
use ResourceLoaderContext;
use Title;

class Main implements
	\MediaWiki\Hook\GetLocalURLHook,
	\MediaWiki\Hook\OutputPageAfterGetHeadLinksArrayHook
{
	/** @var ResourceLoaderContext */
	private $rlClientContext;

	/**
	 * @var string The path to the directory contains html files.
	 */
	private $htmlDirectory;

	/**
	 * @var string The path to the directory contains style files.
	 */
	private $styleDirectory;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->htmlDirectory = $config->get( 'WikvenHtmlDirectory' );
		$this->styleDirectory = $config->get( 'WikvenStyleDirectory' );
	}

	/** @inheritDoc */
	public function onGetLocalURL( $title, &$url, $query ) {
		if ( MW_ENTRY_POINT != 'cli' ) {
			return;
		}
		if ( $title->getInterwiki() ) {
			return;
		}
		global $wgWikvenEditUrl, $wgWikvenHistoryUrl;
		$name = Title::makeName( $title->getNamespace(), $title->getDBkey() );
		if ( preg_match( '/action=([^&]+)/', $query, $matches ) ) {
			$action = $matches[1];
			if ( $action === 'edit' && $wgWikvenEditUrl ) {
				$name = str_replace( '_', '%20', $name );
				$url = str_replace( '$1', $name, $wgWikvenEditUrl );
			} elseif ( $action === 'history' && $wgWikvenHistoryUrl ) {
				$name = str_replace( '_', '%20', $name );
				$url = str_replace( '$1', $name, $wgWikvenHistoryUrl );
			} else {
				$url = "./$name.html";

			}
		} else {
			$url = "./$name.html";
		}
	}

	/** @inheritDoc */
	public function onOutputPageAfterGetHeadLinksArray( &$tags, $out ) {
		// Remove unreachable links, for example, api calls.
		foreach ( [
			'alternative-edit',
			'opensearch',
			'rsd',
			'universal-edit-button',
			] as $key ) {
			unset( $tags[$key] );
		}

		// Links static stylesheet files
		$moduleStyles = $out->getModuleStyles( true );
		$rl = $out->getResourceLoader();
		$context = $this->getRlClientContext( $out );
		$moduleStyles = array_filter( $moduleStyles,
			static function ( $name ) use ( $rl ) {
				$module = $rl->getModule( $name );
				if ( !$module ) {
					return false;
				}
				if ( in_array( $module->getGroup(), [ 'site', 'noscript', 'private', 'user' ] ) ) {
					return false;
				}
				return true;
			}
		);
		foreach ( $moduleStyles as $name ) {
			$module = $out->getResourceLoader()->getModule( $name );
			$group = $module->getGroup();
			if ( !$module->shouldEmbedModule( $context ) ) {
				if ( $group !== 'user' || !$module->isKnownEmpty( $context ) ) {
					$path = './' . $this->styleDirectory . "/$name.css";
					$tags[$name] = Html::linkedStyle( $path );
					$this->addStyleToList( $name );
				}
			}
		}
	}

	/**
	 * @param string $name
	 */
	private function addStyleToList( $name ) {
		if ( MW_ENTRY_POINT != 'cli' ) {
			return;
		}

		$path = $this->htmlDirectory;
		if ( str_ends_with( $path, '/' ) ) {
			$path = rtrim( $path, '/' );
		}
		$path .= '/' . $this->styleDirectory;
		if ( !is_dir( $path ) ) {
			mkdir( $path, 0777, true );
		}
		if ( !file_exists( "$path/$name" ) ) {
			touch( "$path/$name.css" );
		}
	}

	/**
	 * @param OutputPage $output
	 * @return ResourceLoaderContext
	 */
	private function getRlClientContext( $output ) {
		if ( !$this->rlClientContext ) {
			$query = ResourceLoader::makeLoaderQuery(
				// modules; not relevant
				[],
				$output->getLanguage()->getCode(),
				$output->getSkin()->getSkinName(),
				null,
				// version; not relevant
				null,
				// inDebugMode
				null,
				// only; not relevant
				null,
				// printable
				false,
				$output->getRequest()->getBool( 'handheld' )
			);
			$this->rlClientContext = new ResourceLoaderContext(
				$output->getResourceLoader(),
				new FauxRequest( $query )
			);
		}
		return $this->rlClientContext;
	}
}
