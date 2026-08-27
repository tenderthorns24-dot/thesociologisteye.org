<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('ALElementorCacheCompatibility')) :

class ALElementorCacheCompatibility {
	const SOURCE_FILES_CLEAR_CACHE = 'elementor_core_files_clear_cache';
	const SOURCE_GLOBAL_CSS_UPDATED = 'elementor_global_css_updated';
	const SOURCE_GLOBAL_CSS_DELETED = 'elementor_global_css_deleted';

	public $settings;
	public $bvapi;
	private $purge_requested = false;

	function __construct($settings) {
		$this->settings = $settings;
		$this->bvapi = new ALWPAPI($settings);
	}

	public function registerHooks() {
		add_action('elementor/core/files/clear_cache', array($this, 'purgeOnFilesClearCache'), 10, 0);
		add_action('update_option__elementor_global_css', array($this, 'purgeOnGlobalCssUpdated'), 10, 0);
		add_action('delete_option__elementor_global_css', array($this, 'purgeOnGlobalCssDeleted'), 10, 0);
	}

	public function purgeOnFilesClearCache() {
		$this->submitPurge(self::SOURCE_FILES_CLEAR_CACHE);
	}

	public function purgeOnGlobalCssUpdated() {
		$this->submitPurge(self::SOURCE_GLOBAL_CSS_UPDATED);
	}

	public function purgeOnGlobalCssDeleted() {
		$this->submitPurge(self::SOURCE_GLOBAL_CSS_DELETED);
	}

	private function submitPurge($source) {
		if ($this->purge_requested || !$this->elementorUsesExternalCss()) {
			return;
		}

		$this->purge_requested = true;
		$this->bvapi->pingbv('/bvapi/purge_cache', array(
			'purge_options' => array(
				'purge_all' => true,
				'purge_cache_only' => true,
				'purge_source' => $source
			)
		));
	}

	private function elementorUsesExternalCss() {
		return get_option('elementor_css_print_method') !== 'internal';
	}
}
endif;
