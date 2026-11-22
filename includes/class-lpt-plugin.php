<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin parts together.
 */
final class LPT_Plugin {

	private static ?self $instance = null;

	private LPT_Tags           $tags;
	private LPT_GitHub_Updater $updater;

	/**
	 * Single plugin instance.
	 */
	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->tags    = new LPT_Tags();
		$this->updater = new LPT_GitHub_Updater();
	}

	/**
	 * Registers every hook the plugin needs.
	 */
	public function init(): void {
		$this->tags->register_hooks();
		$this->updater->register_hooks();
	}
}
