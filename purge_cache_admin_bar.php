<?php

if (!defined('ABSPATH')) exit;
require_once dirname( __FILE__ ) . '/admin/purge_cache_tools.php';
if (!class_exists('ALPurgeCacheAdminBar')) :

class ALPurgeCacheAdminBar {
	const MAX_PURGE_URLS_INPUT_LENGTH = 65535;
	public $settings;
	public $siteinfo;
	public $bvinfo;
	public $bvapi;
	private $purge_cache_tools;

	function __construct($settings, $siteinfo) {
		$this->settings = $settings;
		$this->siteinfo = $siteinfo;
		$this->bvapi = new ALWPAPI($settings);
		$this->bvinfo = new ALInfo($this->settings);
		$this->purge_cache_tools = new ALPurgeCacheTools($this->bvinfo, $this->bvapi, __FILE__, self::MAX_PURGE_URLS_INPUT_LENGTH);
	}

	public function registerFrontendHooks() {
		add_action('admin_bar_menu', array($this, 'createAlAdminMenu'), 2000);
		add_action('wp_enqueue_scripts', array($this, 'enqueuePurgeCacheTools'));
		add_action('wp_footer', array($this, 'renderPurgeCacheModal'));
	}

	public function registerAdminHooks() {
		add_action('admin_init', array($this, 'purgeCache'));
		add_action('admin_bar_menu', array($this, 'createAlAdminMenu'), 2000);
		add_action('wp_ajax_al_purge_urls', array($this, 'ajaxPurgeUrls'));
		add_action('admin_enqueue_scripts', array($this, 'enqueuePurgeCacheTools'));
		add_action('admin_footer', array($this, 'renderPurgeCacheModal'));
	}

	public function getAdminHeaderName() {
		$admin_header_name = 'AirLift Options';
		$whitelabel_info = $this->bvinfo->getPluginWhitelabelInfo($this->bvinfo->slug);
		if (is_array($whitelabel_info) && isset($whitelabel_info['admin_header_name'])) {
			$admin_header_name = $whitelabel_info['admin_header_name'];
		}
		return $admin_header_name;
	}

	public function createAlAdminMenu() {
		if (!$this->canPurgeCache() || !is_admin_bar_showing()) {
			return;
		}
		global $wp_admin_bar;
		$menu_id = 'airlift_options';
		$admin_header_name = $this->getAdminHeaderName();
		$wp_admin_bar->add_menu(array('id' => $menu_id, 'title' => $admin_header_name));
		if (!is_admin()) {
			$wp_admin_bar->add_menu(array('parent' => $menu_id, 'id' => 'al-purge-current-url', 'href' => '#', 'title' => 'Purge This Page'));
		}
		$wp_admin_bar->add_menu(array('parent' => $menu_id, 'id' => 'al-purge-urls', 'href' => '#', 'title' => 'Purge URLs'));
		$wp_admin_bar->add_menu(array('parent' => $menu_id, 'id' => 'al-purge-all-cache', 'href' => '#', 'title' => 'Purge All Cache'));
	}

	public function purgeCache() {
		if (!(isset($_REQUEST['bv_purge_cache']) && $this->canPurgeCache())) {
			return;
		}
		$nonce = ALHelper::getRawParam('REQUEST', '_wpnonce');
		if (!$nonce || !wp_verify_nonce($nonce, 'al_purge_cache')) {
			$message = 'Security check failed. Please refresh the page and try again.';
		} else {
			$resp = $this->purge_cache_tools->submitPurgeCache(array('purge_all' => true));
			$message = $this->purge_cache_tools->handlePurgeCacheErrors($resp);
		}
		wp_register_script( 'bv-purge-cache', '' );
		wp_enqueue_script( 'bv-purge-cache' );

		wp_add_inline_script(
			'bv-purge-cache',
			'window.addEventListener("load", function(){ alert(' . wp_json_encode($message) . ');});'
		);
	}

	public function enqueuePurgeCacheTools() {
		if (!$this->canPurgeCache() || !is_admin_bar_showing()) {
			return;
		}
		$this->purge_cache_tools->enqueueAssets();
	}

	public function renderPurgeCacheModal() {
		if (!$this->canPurgeCache() || !is_admin_bar_showing()) {
			return;
		}
		$this->purge_cache_tools->renderModal();
	}

	public function ajaxPurgeUrls() {
		$this->purge_cache_tools->handleAjax($this->canPurgeCache());
	}

	private function canPurgeCache() {
		return current_user_can('editor') || current_user_can('administrator');
	}
}
endif;
