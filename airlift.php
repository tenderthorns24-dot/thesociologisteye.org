<?php
/*
Plugin Name: AirLift
Plugin URI: https://airlift.net
Description: Easiest way to speed up your website
Author: AirLift
Author URI: https://airlift.net
Version: 6.71
Network: True
License: GPLv2 or later
License URI: [http://www.gnu.org/licenses/gpl-2.0.html](http://www.gnu.org/licenses/gpl-2.0.html)
 */

/*  Copyright 2017  AirLift  (email : support@airlift.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/* Global response array */

if (!defined('ABSPATH')) exit;
##OLDWPR##

require_once dirname( __FILE__ ) . '/wp_settings.php';
require_once dirname( __FILE__ ) . '/wp_site_info.php';
require_once dirname( __FILE__ ) . '/wp_db.php';
require_once dirname( __FILE__ ) . '/wp_api.php';
require_once dirname( __FILE__ ) . '/wp_actions.php';
require_once dirname( __FILE__ ) . '/info.php';
require_once dirname( __FILE__ ) . '/account.php';
require_once dirname( __FILE__ ) . '/helper.php';
require_once dirname( __FILE__ ) . '/wp_file_system.php';
##WP_2FA_REQUIRE_FILE##
##WP_LOGIN_WHITELABEL_REQUIRE_FILE##
require_once dirname( __FILE__ ) . '/wp_cache.php';



$bvsettings = new ALWPSettings();
$bvsiteinfo = new ALWPSiteInfo();
$bvdb = new ALWPDb();


$bvapi = new ALWPAPI($bvsettings);
$bvinfo = new ALInfo($bvsettings);
$wp_action = new ALWPAction($bvsettings, $bvsiteinfo, $bvapi);

register_uninstall_hook(__FILE__, array('ALWPAction', 'uninstall'));
register_activation_hook(__FILE__, array($wp_action, 'activate'));
register_deactivation_hook(__FILE__, array($wp_action, 'deactivate'));


add_action('wp_footer', array($wp_action, 'footerHandler'), 100);
add_action('al_clear_bv_services_config', array($wp_action, 'clear_bv_services_config'));

add_action('al_clear_cache_config', array($wp_action, 'clear_cache_config'));

add_filter( 'rocket_set_wp_cache_constant', '__return_false');
add_filter( 'rocket_generate_advanced_cache_file', '__return_false');
add_filter( 'wpmeteor_enabled', '__return_false' );
add_filter( 'wpmeteor_exclude', '__return_true' );
add_filter( 'jetpack_photon_skip_for_url', '__return_true', 20);
add_filter( 'jetpack_photon_skip_image', '__return_true' , 20);
add_filter( 'nitropack_can_serve_cache', '__return_false' , 20);
add_filter('phastpress_disable', '__return_true');
if(! defined( 'LITESPEED_DISABLE_ALL' )) {
	define( 'LITESPEED_DISABLE_ALL', true );
}

#W3 Total Cache Handling
if (! defined('DONOTCACHEPAGE')) {
	define( 'DONOTCACHEPAGE', true );
}
if (! defined('DONOTMINIFY')) {
	define( 'DONOTMINIFY', true );
}
function w3tc_stop_lazyload() {
	return array('enabled' => false);
}
add_filter( 'w3tc_lazyload_can_process', 'w3tc_stop_lazyload', 100);
if (! defined('DONOTCDN')) {
	define('DONOTCDN', true);
}

if (! defined('DONOTLAZYLOAD')) {
	define('DONOTLAZYLOAD', true);
}

if (! defined('DONOTROCKETOPTIMIZE')) {
	define('DONOTROCKETOPTIMIZE', true);
}
#handling of wp optimize plugin
if (! defined('WPO_ADVANCED_CACHE')) {
	define('WPO_ADVANCED_CACHE', false);
}
#handling of tenweb plugin
if (! defined('TWO_INCOMPATIBLE_ERROR')) {
	define('TWO_INCOMPATIBLE_ERROR', true);
}


##WPCLIMODULE##
require_once dirname( __FILE__ ) . '/public_url_purge.php';
$al_public_url_purge = new ALPublicUrlPurge($bvsettings, $bvdb, $bvapi);
$al_public_url_purge->registerHooks();

require_once dirname( __FILE__ ) . '/integrations/plugins/elementor_cache_compatibility.php';
$al_elementor_cache_compatibility = new ALElementorCacheCompatibility($bvsettings);
$al_elementor_cache_compatibility->registerHooks();
require_once dirname( __FILE__ ) . '/integrations/plugins/wcml_cache_compatibility.php';
$al_wcml_cache_compatibility = new ALWCMLCacheCompatibility();
$al_wcml_cache_compatibility->registerHooks();

add_action('init', function () use ($bvsettings, $bvsiteinfo) {
		if ((is_admin() || is_admin_bar_showing()) && is_user_logged_in() && (current_user_can('editor') || current_user_can('administrator'))) {
			require_once dirname( __FILE__ ) . '/purge_cache_admin_bar.php';
			$al_purge_cache_admin_bar = new ALPurgeCacheAdminBar($bvsettings, $bvsiteinfo);
			if (is_admin()) {
				$al_purge_cache_admin_bar->registerAdminHooks();
			} else {
				$al_purge_cache_admin_bar->registerFrontendHooks();
			}
		}
});

if (is_admin()) {
	require_once dirname( __FILE__ ) . '/wp_admin.php';
	$wpadmin = new ALWPAdmin($bvsettings, $bvsiteinfo);
	add_action('admin_init', array($wpadmin, 'initHandler'));
	add_filter('all_plugins', array($wpadmin, 'initWhitelabel'));
	add_filter('plugin_row_meta', array($wpadmin, 'hidePluginDetails'), 10, 2);
	add_filter('debug_information', array($wpadmin, 'handlePluginHealthInfo'), 10, 1);
	if ($bvsiteinfo->isMultisite()) {
		add_action('network_admin_menu', array($wpadmin, 'menu'));
	} else {
		add_action('admin_menu', array($wpadmin, 'menu'));
	}
	add_filter('plugin_action_links', array($wpadmin, 'settingsLink'), 10, 2);
	add_action('admin_head', array($wpadmin, 'removeAdminNotices'), 3);

	##MG_AJAX_ACTIONS##
	add_action('admin_enqueue_scripts', array($wpadmin, 'enqueue_deactivation_feedback_assets'));
	add_action('admin_footer', array($wpadmin, 'add_deactivation_feedback_dialog'));

	add_action('admin_notices', array($wpadmin, 'activateWarning'));
	add_action('admin_enqueue_scripts', array($wpadmin, 'alsecAdminMenu'));
		add_action('admin_enqueue_scripts', array($wpadmin, 'alSecretKeyScript'));
}

if ((array_key_exists('bvreqmerge', $_POST)) || (array_key_exists('bvreqmerge', $_GET))) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
	$_REQUEST = array_merge($_GET, $_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
}

##REMOVE_BV_PRELOAD_MODULE##
##PHP_ERROR_MONITORING_MODULE##
if ($bvinfo->hasValidDBVersion()) {
	##ACTLOGMODULE##
	##MAINTENANCEMODULE##
}

if (ALHelper::getRawParam('REQUEST', 'bvplugname') == "airlift") {
	require_once dirname( __FILE__ ) . '/callback/base.php';
	require_once dirname( __FILE__ ) . '/callback/response.php';
	require_once dirname( __FILE__ ) . '/callback/request.php';
	require_once dirname( __FILE__ ) . '/recover.php';

	$pubkey = ALHelper::getRawParam('REQUEST', 'pubkey');
	$pubkey = isset($pubkey) ? ALAccount::sanitizeKey($pubkey) : '';
	$rcvracc = ALHelper::getRawParam('REQUEST', 'rcvracc');

	if (isset($rcvracc)) {
		$bvctag = ALHelper::getRawParam('REQUEST', 'bvctag');
		$bvctag = isset($bvctag) ? ALAccount::sanitizeKey($bvctag) : null;
		$account = ALRecover::find($bvsettings, $pubkey, $bvctag);
	} else {
		$account = ALAccount::find($bvsettings, $pubkey);
	}

	$request = new ALCallbackRequest($account, $_REQUEST, $bvsettings); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$response = new ALCallbackResponse($request->bvb64cksize);

	if ($request->authenticate() === 1) {
		$bv_frm_tstng = ALHelper::getRawParam('REQUEST', 'bv_frm_tstng');
		if (isset($bv_frm_tstng)) {
			##FORM_TESTING##
		} else {
			##BVBASEPATH##

			require_once dirname( __FILE__ ) . '/callback/handler.php';

			$params = $request->processParams($_REQUEST); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ($params === false) {
				$response->terminate($request->corruptedParamsResp());
			}
			$request->params = $params;
			$callback_handler = new ALCallbackHandler($bvdb, $bvsettings, $bvsiteinfo, $request, $account, $response);
			if ($request->is_aftershutdown) {
				$callback_handler->deferExecutionUntilShutdown();
			} else if ($request->is_afterload) {
				add_action('wp_loaded', array($callback_handler, 'execute'));
			} else if ($request->is_admin_ajax) {
				add_action('wp_ajax_bvadm', array($callback_handler, 'bvAdmExecuteWithUser'));
				add_action('wp_ajax_nopriv_bvadm', array($callback_handler, 'bvAdmExecuteWithoutUser'));
			} else {
				$callback_handler->execute();
			}
		}
	} else {
		$response->terminate($request->authFailedResp());
	}
} else {
	if ($bvinfo->hasValidDBVersion()) {
		##PROTECTMODULE##
		if ($bvinfo->isDynSyncModuleEnabled()) {
		require_once dirname( __FILE__ ) . '/wp_dynsync.php';
		$bvconfig = $bvinfo->config;
		$dynsync = new BVWPDynSync($bvdb, $bvsettings, $bvconfig['dynsync']);
		$dynsync->init();
	}

	}
	$bv_site_settings = $bvsettings->getOption('bv_site_settings');
	if (is_array($bv_site_settings)) {
		if (isset($bv_site_settings['wp_auto_updates'])) {
			$wp_auto_updates = $bv_site_settings['wp_auto_updates'];
			if (array_key_exists('block_auto_update_core', $wp_auto_updates)) {
				add_filter('auto_update_core', '__return_false' );
			}
			if (array_key_exists('block_auto_update_theme', $wp_auto_updates)) {
				add_filter('auto_update_theme', '__return_false' );
				add_filter('themes_auto_update_enabled', '__return_false' );
			}
			if (array_key_exists('block_auto_update_plugin', $wp_auto_updates)) {
				add_filter('auto_update_plugin', '__return_false' );
				add_filter('plugins_auto_update_enabled', '__return_false' );
			}
			if (array_key_exists('block_auto_update_translation', $wp_auto_updates)) {
				add_filter('auto_update_translation', '__return_false' );
			}
		}

	}

	if (is_admin()) {
		add_filter('site_transient_update_plugins', array($wpadmin, 'hidePluginUpdate'));
	}

	$bv_is_third_party_host = $bvsettings->getOption('bv_is_third_party_host');
if (isset($bv_is_third_party_host) && $bv_is_third_party_host === "true") {
		require_once dirname( __FILE__ ) . "/buffer/third_party_caching.php";
		$thirdpartycache = new ALThirdPartyCache();
		$thirdpartycache->startThirdPartyHostCaching();
}

}

##WP2FAMODULE##
##WP_LOGIN_WHITELABEL_MODULE##
##CLEAR_WP_2FA_CONFIG_ACTION##
add_action('plugins_loaded', function() { 
	if (! defined('AIRLIFT_PLUGIN_LOADED') ) { 
		define('AIRLIFT_PLUGIN_LOADED', true); 
	} 
});
