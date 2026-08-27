<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('ALWPAction')) :
	class ALWPAction {
		public $settings;
		public $siteinfo;
		public $bvinfo;
		public $bvapi;

		public function __construct($settings, $siteinfo, $bvapi) {
			$this->settings = $settings;
			$this->siteinfo = $siteinfo;
			$this->bvapi = $bvapi;
			$this->bvinfo = new ALInfo($settings);
		}
	
		public function activate() {
			if (!isset($_REQUEST['blogvaultkey'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				ALAccount::addAccount($this->settings, '22f7a2c5ee65cbcb920f3dced546595c', '81d75930eddc97357d30712a91ed95a6');
		ALAccount::updateApiPublicKey($this->settings, '22f7a2c5ee65cbcb920f3dced546595c');
			}
			if (ALAccount::isConfigured($this->settings)) {
				/* This informs the server about the activation */
				$info = array();
				$this->siteinfo->basic($info);
				$this->bvapi->pingbv('/bvapi/activate', $info);
			} else {
				ALAccount::setup($this->settings);
			}
		}

		public function deactivate() {
			$info = array();
			$this->siteinfo->basic($info);
			ALWPCache::disableCache($this->bvinfo);
			$this->process_deactivation_feedback($info);

			$this->bvapi->pingbv('/bvapi/deactivate', $info);
		}

		public static function uninstall() {
			##CLEARPTCONFIG##
			do_action('al_clear_dynsync_config');
			do_action('al_clear_cache_config');
			do_action('al_clear_bv_services_config');
			##CLEAR_WP_2FA_CONFIG##
			##REMOVE_BV_PRELOAD_ACTION##
			##CLEAR_PHP_ERROR_CONFIG##
		}

		public function clear_bv_services_config() {
			$this->settings->deleteOption($this->bvinfo->services_option_name);
		}

		##CLEAR_WP_2FA_CONFIG_FUNCTION##

		public function clear_cache_config() {
			ALWPCache::disableCache($this->bvinfo);
		}


		public function footerHandler() {
			$bvfooter = $this->settings->getOption($this->bvinfo->badgeinfo);
			if ($bvfooter) {
				echo '<div style="max-width:150px;min-height:70px;margin:0 auto;text-align:center;position:relative;">
					<a href='.esc_url($bvfooter['badgeurl']).' target="_blank" ><img src="'.esc_url(plugins_url($bvfooter['badgeimg'], __FILE__)).'" alt="'.esc_attr($bvfooter['badgealt']).'" /></a></div>';
			}
		}

		private function process_deactivation_feedback(&$info) {
			//phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if (!isset($_GET['bv_deactivation_assets']) || !is_string($_GET['bv_deactivation_assets'])) {
				return;
			}

			$deactivation_assets = wp_unslash($_GET['bv_deactivation_assets']);
			$info['deactivation_feedback'] = base64_encode($deactivation_assets);
			//phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		##REMOVE_BV_PRELOAD##
	}
endif;