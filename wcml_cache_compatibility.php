<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('ALWCMLCacheCompatibility')) :

class ALWCMLCacheCompatibility {
	const INTEGRATION_KEY = 'wcml_cookie_storage';

	public function registerHooks() {
		if (!$this->isEnabled()) {
			return;
		}
		add_filter('wcml_user_store_strategy', array($this, 'useCookieStorage'), 10, 2);
	}

	public function useCookieStorage($strategy = null, $context = null) {
		if (function_exists('is_admin') && is_admin() && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') {
			return 'wc-session';
		}
		return 'cookie';
	}

	private function isEnabled() {
		return isset($GLOBALS['al_cache_runtime_integrations'])
			&& is_array($GLOBALS['al_cache_runtime_integrations'])
			&& in_array(self::INTEGRATION_KEY, $GLOBALS['al_cache_runtime_integrations'], true);
	}
}
endif;
