<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('ALSpeedCallback')) :
require_once dirname( __FILE__ ) . '/../../wp_cache.php';
class ALSpeedCallback extends ALCallbackBase {

	const SPEED_WING_VERSION = 1.7;
	public $settings;
	public $bvinfo;
	public $db;

	public function __construct($callback_handler) {
		$this->settings = $callback_handler->settings;
		$this->bvinfo = new ALInfo($callback_handler->settings);
		$this->db = $callback_handler->db;
	}

	public function get_third_party_urls_to_exclude($params) {
		$post_ids_to_reject = array();
		$request_urls_to_reject = array();

		if (empty($params)) {
			return $request_urls_to_reject;
		}

		if (class_exists('WooCommerce') && function_exists('wc_get_page_id') && isset($params["woocommerce_keys_to_ignore"])) {
			$keys_to_check = $params["woocommerce_keys_to_ignore"];
			foreach ($keys_to_check as $key) {
				array_push($post_ids_to_reject, wc_get_page_id($key));
			}
		}

		if (function_exists('bigcommerce_init') && isset($params["big_woocommerce_keys_to_ignore"])) {
			$keys_to_check = $params["big_woocommerce_keys_to_ignore"];
			foreach ($keys_to_check as $key) {
				array_push($post_ids_to_reject, $this->settings->getOption($key));
			}
		}

		foreach ($post_ids_to_reject as $post_id) {
			if (!$post_id || $post_id <= 0 || (int) $this->settings->getOption('page_on_front') === $post_id ||
				'publish' !== get_post_status($post_id)) {
				continue;
			}
			array_push($request_urls_to_reject, wp_parse_url(get_permalink($post_id), PHP_URL_PATH));
		}

		return $request_urls_to_reject;
	}

	public function arrayContainsValue($params, $key, $value) {
			return isset($params[$key]) && is_array($params[$key]) && in_array($value, $params[$key]);
	}

	public function clearVarnishCache($params) {
		$request_args = isset($params['request_args']) ? $params['request_args'] : null;
		$purge_url = isset($params['purge_url']) ? $params['purge_url'] : false;
		if ($request_args && $purge_url) {
			$remote_info = wp_remote_request($purge_url, $request_args);
			
			// Handle WP_Error
			if (is_wp_error($remote_info)) {
				return [
					'error' => 'Request failed: ' . $remote_info->get_error_message(),
					'response' => ['code' => 0]
				];
			}
			
			// Extract and return essential response data
			$response_code = wp_remote_retrieve_response_code($remote_info);
			$response_message = wp_remote_retrieve_response_message($remote_info);
			
			return [
				'response' => [
					'code' => $response_code,
					'message' => $response_message
				]
			];
		}
		return [
			'error' => 'Unable to clear varnish cache due to invalid params',
			'response' => ['code' => 0]
		];
	}

	public function updateBVTableContent($table, $value, $filter) {
		$this->db->query("UPDATE $table SET $value $filter;");
	}

	public function insertBVTableContent($table, $fields, $value) {
		$this->db->query("INSERT INTO $table $fields values $value;");
	}

	public function insertConfigRows($table, $fields, $values) {
		if (is_array($values)) {
			foreach ($values as $value) {
				$value = base64_decode($value);
				$this->insertBVTableContent($table, $fields, $value);
			}
		}
	}

	public function updateConfigRows($table, $value, $filters) {
		if (is_array($filters)) {
			foreach ($filters as $filter) {
				$filter = base64_decode($filter);
				$this->updateBVTableContent($table, $value, $filter);
			}
		}
	}

	public function deleteConfigRows($table, $rmfilters) {
		if (is_array($rmfilters)) {
			foreach ($rmfilters as $rmfilter) {
				$rmfilter = base64_decode($rmfilter);
				$this->db->deleteBVTableContent($table, $rmfilter);
			}
		}
	}

	public function clearKinstaCacheUrl($url) {
		$url = trailingslashit($url) . 'kinsta-clear-cache/';
		return wp_remote_get($url, ['blocking' => false, 'timeout'  => 0.01]);
	}

	public function clearHostCacheUrl($purge_params, $url) {
		if ($url) {
			$host_resp = array();
			switch ($purge_params['purge_host']) {
			case 'kinsta':
				$host_resp = $this->clearKinstaCacheUrl($url);
				break;
			case 'litespeed':
				do_action('litespeed_purge_url', $url);
				$host_resp['litespeed_url'] = true;
				break;
			case 'presslabs':
				global $nginx_purger;
				if (isset($nginx_purger) && is_object($nginx_purger) && method_exists($nginx_purger, 'purge_url')) {
					$nginx_purger->purge_url($url);
					$host_resp['presslabs_url'] = true;
				} else {
					$host_resp['presslabs_url'] = false;
				}
				break;
			}
			return $host_resp;
		}
		return false;
	}

	public function clearPublicUrlsHostCache($purge_host, $urls) {
		$urls = is_array($urls) ? array_values(array_unique(array_filter($urls, 'is_string'))) : array();
		if (empty($urls)) {
			return false;
		}

		$purge_host = is_string($purge_host) ? strtolower(trim($purge_host)) : '';
		if ($purge_host === '') {
			return $this->clearVarnishUrls($urls);
		}
		if (!in_array($purge_host, $this->supportedPurgeHosts(), true)) {
			return array('unsupported_host' => false);
		}
		if (in_array($purge_host, array('cloudways', 'flywheel', 'dreamhost', 'liquidweb'), true)) {
			return $this->clearVarnishUrls($urls);
		}
		if (in_array($purge_host, array('kinsta', 'litespeed', 'presslabs'), true)) {
			$responses = array();
			foreach ($urls as $url) {
				$responses[$url] = $this->clearHostCacheUrl(array('purge_host' => $purge_host), $url);
			}
			return $responses;
		}
		return $this->clearCompleteHostCache(array('purge_host' => $purge_host));
	}

	private function clearVarnishUrls($urls) {
		$responses = array();
		foreach ($urls as $url) {
			$parsed = wp_parse_url($url);
			if (!is_array($parsed) || empty($parsed['host'])) {
				$responses[$url] = false;
				continue;
			}
			$path = isset($parsed['path']) && $parsed['path'] !== '' ? $parsed['path'] : '/';
			if (isset($parsed['query']) && $parsed['query'] !== '') {
				$path .= '?' . $parsed['query'];
			}
			$responses[$url] = $this->clearVarnishCache(array(
				'purge_url' => 'http://127.0.0.1' . $path,
				'request_args' => array(
					'method' => 'PURGE',
					'headers' => array('Host' => $parsed['host'])
				)
			));
		}
		return $responses;
	}

	private function supportedPurgeHosts() {
		return array(
			'cloudways', 'flywheel', 'dreamhost', 'pagely', 'kinsta', 'savvii', 'wpengine',
			'liquidweb', 'siteground', 'pressable', 'automattic', 'pantheon', 'godaddy',
			'presslabs', 'onecom', 'o2switch', 'wpserveur', 'litespeed'
		);
	}

	public function clearCacheUrl($purge_url) {
		if (isset($purge_url)) {
			$parsed_url = parse_url($purge_url);
			if (!is_array($parsed_url)) {
				return false;
			}
			$query_value = isset($parsed_url["query"]) ? $parsed_url["query"] : "";
			if (empty($parsed_url["host"]) || empty($parsed_url["path"]) ||
					!$this->isValidCacheHost($parsed_url["host"]) || !$this->isValidCachePath($parsed_url["path"]) ||
					!$this->isValidCachePath($query_value)) {
				return false;
			}
			$query = $query_value !== "" ? "#" . $query_value : "";
			$path = empty($query) ? rtrim($parsed_url["path"], "/") : $parsed_url["path"];
			$cache_base_path = ALCacheHelper::getCacheBasePath();
			$delete_path = $cache_base_path . $parsed_url["host"] . $path . $query;
			return $this->deleteExactAirliftCacheFiles($cache_base_path, $delete_path);
		}
		return false;
	}

	public function clearCacheUrlPrefix($purge_url_prefix) {
		if (isset($purge_url_prefix)) {
			$parsed_url = parse_url($purge_url_prefix);
			if (!is_array($parsed_url) || !empty($parsed_url["query"]) || empty($parsed_url["host"]) ||
					empty($parsed_url["path"]) || !$this->isValidCacheHost($parsed_url["host"])) {
				return false;
			}
			$path = "/" . trim($parsed_url["path"], "/");
			if ($path === "/" || !$this->isValidCachePath($path)) {
				return false;
			}
			$cache_base_path = ALCacheHelper::getCacheBasePath();
			$delete_path = $cache_base_path . $parsed_url["host"] . $path;
			return $this->deleteAirliftCachePath($cache_base_path, $delete_path);
		}
		return false;
	}

	private function isValidCacheHost($host) {
		return is_string($host) && $host !== '' && strpos($host, "\0") === false &&
			strpos($host, '/') === false && strpos($host, '\\') === false && strpos($host, '..') === false;
	}

	private function isValidCachePath($path) {
		if (!is_string($path)) {
			return false;
		}
		$path = rawurldecode($path);
		return strpos($path, "\0") === false && strpos($path, '\\') === false &&
			preg_match('/[\x00-\x1F\x7F]/', $path) !== 1 && !in_array('..', explode('/', $path), true);
	}

	private function normalizeCachePath($path) {
		if (function_exists('wp_normalize_path')) {
			return wp_normalize_path($path);
		}
		return str_replace('\\', '/', $path);
	}

	private function deleteAirliftCachePath($cache_base_path, $delete_path) {
		if (!$this->isCachePathContained($cache_base_path, $delete_path)) {
			return false;
		}
		return ALWPFileSystem::getInstance()->rmdir($delete_path, true);
	}

	private function deleteExactAirliftCacheFiles($cache_base_path, $delete_path) {
		if (!is_dir($delete_path) || !$this->isCachePathContained($cache_base_path, $delete_path)) {
			return false;
		}

		$deleted = false;
		$cache_files = array_merge((array) glob($delete_path . '/index*.html'), (array) glob($delete_path . '/index*.html_gzip'));
		foreach ($cache_files as $cache_file) {
			if (!is_file($cache_file) || !$this->isExactAirliftCacheFile($cache_file)) {
				continue;
			}
			$deleted = ALWPFileSystem::getInstance()->removeFile($cache_file) || $deleted;
		}
		return $deleted;
	}

	private function isCachePathContained($cache_base_path, $delete_path) {
		$real_base_path = realpath($cache_base_path);
		$real_delete_path = realpath($delete_path);
		if ($real_base_path === false || $real_delete_path === false) {
			return false;
		}
		$normalized_base_path = rtrim($this->normalizeCachePath($real_base_path), '/');
		$normalized_delete_path = rtrim($this->normalizeCachePath($real_delete_path), '/');
		return $normalized_base_path !== '' && $normalized_delete_path !== '' &&
			$normalized_delete_path !== $normalized_base_path &&
			strpos($normalized_delete_path, $normalized_base_path . '/') === 0;
	}

	private function isExactAirliftCacheFile($cache_file) {
		return preg_match('/^index(?:-[A-Za-z0-9_-]+)*\.html(?:_gzip)?$/', basename($cache_file)) === 1;
	}

	public function clearPostsSpecificCache($purge_params) {
		$post_resp = array();
		if (isset($purge_params['clear_cached_post_ids']) && is_array($purge_params['clear_cached_post_ids'])) {
			$post_resp['post_ids_cache_purged'] = array();
			foreach ($purge_params['clear_cached_post_ids'] as $id) {
				$post_resp['post_ids_cache_purged'][$id] = array();
				$post_resp['post_ids_cache_purged'][$id][$id] = $this->clearCacheUrl(get_permalink($id));
				if (isset($purge_params['purge_host'])) {
					$post_resp['post_ids_cache_purged'][$id]['host_resp'] = $this->clearHostCacheUrl($purge_params, get_permalink($id));
				}
			}
		}
		if (isset($purge_params['clear_cached_urls']) && is_array($purge_params['clear_cached_urls'])) {
			$post_resp['urls_cache_purged'] = array();
			foreach ($purge_params['clear_cached_urls'] as $url) {
				$post_resp['urls_cache_purged'][$url] = array();
				$post_resp['urls_cache_purged'][$url][$url] = $this->clearCacheUrl($url);
				if (isset($purge_params['purge_host'])) {
					$post_resp['urls_cache_purged'][$url]['host_resp'] = $this->clearHostCacheUrl($purge_params, $url);
				}
			}
		}
		if (isset($purge_params['clear_cached_url_prefixes']) && is_array($purge_params['clear_cached_url_prefixes'])) {
			$post_resp['url_prefixes_cache_purged'] = array();
			foreach ($purge_params['clear_cached_url_prefixes'] as $url_prefix) {
				$post_resp['url_prefixes_cache_purged'][$url_prefix] = array();
				$post_resp['url_prefixes_cache_purged'][$url_prefix][$url_prefix] = $this->clearCacheUrlPrefix($url_prefix);
			}
		}
		return $post_resp;
	}

	public function clear_edge_cache() {
		if (!defined('SITE_API_BASE') || !defined('ATOMIC_SITE_ID') || !defined('ATOMIC_SITE_API_KEY')) {
			return [ 'success' => false, 'error' => 'Missing required constants.' ];
		}

		$site_api_base = SITE_API_BASE;
		$atomic_site_id = ATOMIC_SITE_ID;
		$atomic_site_api_key = ATOMIC_SITE_API_KEY;

		$base_endpoint = sprintf('%s/edge-cache/%s', $site_api_base, $atomic_site_id);
		$endpoint = sprintf('%s/purge', $base_endpoint);

		$base_args = [
			'timeout' => 5,
			'method'  => 'POST',
			'headers' => [ 'Auth' => $atomic_site_api_key ],
		];

		if (!function_exists('wp_remote_request')) {
			return [ 'success' => false, 'error' => 'Request function unavailable.' ];
		}

		$result = wp_remote_request($endpoint, $base_args);

		if (is_wp_error($result)) {
			return [ 'success' => false, 'error' => 'Request failed: ' . $result->get_error_message() ];
		}

		$http_code = wp_remote_retrieve_response_code($result);
		if (empty($http_code)) {
			return [ 'success' => false, 'error' => 'No response from server.' ];
		}

		$body = wp_remote_retrieve_body($result);
		if (empty($body)) {
			return [ 'success' => false, 'error' => 'Empty response body from server.' ];
		}

		$response = json_decode($body, false);
		if (json_last_error() !== JSON_ERROR_NONE || !is_object($response)) {
			return [ 'success' => false, 'error' => 'Invalid JSON response from server.' ];
		}

		if (!isset($response->message) || !isset($response->data)) {
			return [ 'success' => false, 'error' => 'Unexpected response format from server.' ];
		}

		if ($response->message === 'OK') {
			return [ 'success' => true, 'data' => $response->data ];
		}

		return [ 'success' => false, 'error' => 'Unexpected response: ' . $response->message ];
	}

	public function clear_batcache_cache() {
		global $batcache;
		$responses = [
			'batcache_host_flush' => false,
			'batcache_key_flush'  => false,
		];

		if (isset($batcache) && is_object($batcache) && method_exists($batcache, 'flush')) {
			$host = ALHelper::getRawParam('SERVER', 'HTTP_HOST');
			if (isset($host)) {
				$responses['batcache_host_flush'] = $batcache->flush('host', $host);
			}
			if (isset($batcache->key) && isset($batcache->group)) {
				$responses['batcache_key_flush'] = $batcache->flush($batcache->key, $batcache->group);
			}
		}
		return $responses;
	}

	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	// Socket connection required
	public function clear_siteground_dynamic_cache($hostname, $main_path, $url, $args, $request) {
		$host_resp = array();
		$site_tools_sock_file = '/chroot/tmp/site-tools.sock';

		if (!file_exists($site_tools_sock_file) || empty($args)) {
			$host_resp['cache_purge_status'] = false;
			$host_resp['site_tools_sock_file_found'] = false;
			return $host_resp;
		}
		$fp = stream_socket_client('unix://' . $site_tools_sock_file, $errno, $errstr, 5);
		if ( false === $fp ) {
			$host_resp['cache_purge_status'] = false;
			$host_resp['unix_socket_connection_success'] = false;
			return $host_resp;
		}

		$host_resp['unix_socket_connection_success'] = true;

		fwrite($fp, json_encode($request, JSON_FORCE_OBJECT) . "\n");

		$response = fgets($fp, 32 * 1024);

		fclose($fp);

		$result = @json_decode($response, true);

		if (false === $result || isset($result['err_code'])) {
			$host_resp['cache_purge_fail_resp'] = $result;
		}

		$host_resp['cache_purge_status'] = $result;

		return $host_resp;
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	private function sitegroundDynamicCacheParams() {
		$url = function_exists('home_url') ? home_url('/') : '';
		$hostname = is_string($url) && function_exists('wp_parse_url') ? wp_parse_url($url, PHP_URL_HOST) : '';
		if (!is_string($hostname) || $hostname === '') {
			return false;
		}
		$hostname = preg_replace('/^www\./i', '', $hostname);
		$args = array(
			'api' => 'domain-all',
			'cmd' => 'update',
			'settings' => array('json' => 1),
			'params' => array('flush_cache' => '1', 'id' => $hostname, 'path' => '/(.*)'),
		);
		return array(
			'hostname' => $hostname,
			'main_path' => '/(.*)',
			'url' => $url,
			'args' => $args,
			'request' => array('api' => $args['api'], 'cmd' => $args['cmd'], 'params' => $args['params'], 'settings' => $args['settings']),
		);
	}

	public function clearCompleteHostCache($purge_params) {
		$host_resp = array();
		if (!isset($purge_params['purge_host']) || !in_array($purge_params['purge_host'], $this->supportedPurgeHosts(), true)) {
			return array('unsupported_host' => false);
		}
		switch ($purge_params['purge_host']) {
		case 'cloudways':
		case 'flywheel':
		case 'dreamhost':
		case 'liquidweb':
			if (isset($purge_params['varnish_params'])) {
				$host_resp['varnish_cache'] = $this->clearVarnishCache($purge_params['varnish_params']);
			}
			break;
		case 'pagely':
			if (class_exists( 'PagelyCachePurge')) {
				$purger = new PagelyCachePurge();
				$purger->purgeAll();
				$host_resp['pagely_cache'] = true;
			} else {
				$host_resp['pagely_cache'] = false;
			}
			break;
		case 'kinsta':
			global $kinsta_cache;

			if (!empty($kinsta_cache->kinsta_cache_purge)) {
				$kinsta_cache->kinsta_cache_purge->purge_complete_caches();
				$host_resp['kinsta_cache'] = true;
			} else {
				$host_resp['kinsta_cache'] = false;
			}
			break;
		case 'savvii':
			do_action('warpdrive_domain_flush');
			$host_resp['savvii_cache'] = true;
			break;
		case 'wpengine':
			if (method_exists('WpeCommon', 'purge_varnish_cache')) {
				WpeCommon::purge_varnish_cache();
				$host_resp['wpengine_cache'] = true;
			} else {
				$host_resp['wpengine_cache'] = false;
			}
			break;
		case 'siteground':
			$dyn_cache_params = isset($purge_params['dynamic_cache_params']) ?
				$purge_params['dynamic_cache_params'] : $this->sitegroundDynamicCacheParams();
			if (is_array($dyn_cache_params)) {
				$hostname = $dyn_cache_params['hostname'];
				$main_path = $dyn_cache_params['main_path'];
				$url = $dyn_cache_params['url'];
				$args = $dyn_cache_params['args'];
				$request = $dyn_cache_params['request'];
				$host_resp['siteground_cache'] = $this->clear_siteground_dynamic_cache($hostname, $main_path, $url, $args, $request);
			} else {
				$host_resp['siteground_cache'] = false;
			}
			break;
		case 'pressable':
			$host_resp['pressable'] = $this->clear_batcache_cache();
			$host_resp['edge_cache_purge'] = $this->clear_edge_cache();
			break;
		case 'automattic':
			$host_resp['automattic'] = $this->clear_batcache_cache();
			$host_resp['edge_cache_purge'] = $this->clear_edge_cache();
			break;
		case 'pantheon':
			if (function_exists('pantheon_clear_edge_all')) {
				pantheon_clear_edge_all();
				$host_resp['edge_cache_purge'] = true;
			} else {
				$host_resp['edge_cache_purge'] = false;
			}

			if (function_exists('wp_cache_flush')) {
				$host_resp['pantheon_cache'] = wp_cache_flush();
			} else {
				$host_resp['pantheon_cache'] = false;
			}
			break;
		case 'godaddy':
			if (isset($GLOBALS['wpaas_cache_class']) && is_object($GLOBALS['wpaas_cache_class']) && method_exists($GLOBALS['wpaas_cache_class'], 'do_ban')) {
				$GLOBALS['wpaas_cache_class']->do_ban();
				$host_resp['godaddy_cache'] = true;
			} else {
				$host_resp['godaddy_cache'] = false;
			}
			break;
		case 'presslabs':
			global $nginx_purger;
			if (isset($nginx_purger) && is_object($nginx_purger) && method_exists($nginx_purger, 'purge_all')) {
				$nginx_purger->purge_all();
			} else {
				do_action('rt_nginx_helper_purge_all');
			}
			$host_resp['presslabs_cache'] = true;
			break;
		case 'onecom':
			if (function_exists('onecom_clear_cache')) {
				$host_resp['onecom_cache'] = onecom_clear_cache();
			} else {
				do_action('onecom_clear_cache');
				do_action('onecom_cache_purge_all');
				$host_resp['onecom_cache'] = true;
			}
			break;
		case 'o2switch':
			do_action('litespeed_purge_all');
			do_action('accelerate_wp_purge_all');
			$host_resp['o2switch_cache'] = true;
			break;
		case 'wpserveur':
			if (function_exists('wps_purge_cache')) {
				$host_resp['wpserveur_cache'] = wps_purge_cache();
			} else {
				do_action('wpserveur_purge_cache');
				$host_resp['wpserveur_cache'] = true;
			}
			break;
		case 'litespeed':
			do_action('litespeed_purge_all');
			$host_resp['litespeed_cache'] = true;
			break;
		}
		return $host_resp;
	}

	function process($request) {
		$params = $request->params;
		switch ($request->method) {
		case 'updtoptn':
			$option = 'alcacheconfig';
			$this->settings->updateOption($option, $params['config']);
			$resp = array("status" => $this->settings->getOption($option));
			break;
		case 'insrtcnf':
			$pdata = $params["pdata"];
			$pdata["config"] = $params["pdata"]["config"];
			$pdata["similar_post_ids"] = $params["pdata"]["similar_post_ids"];
			$pdata["similar_urls"] = isset($params["pdata"]["similar_urls"]) ? $params["pdata"]["similar_urls"] : "[]";
			$table = $this->db->getBVTable("airlift_config");
			$columns = " (url, similar_post_ids, similar_urls, category, category_value, config, time)";
			$values = " VALUES ( '" . $pdata["url"] . "', '" . $pdata["similar_post_ids"] . "', '" . addslashes($pdata["similar_urls"]) . "', " . $pdata["category"] . ", '" . $pdata["category_value"] . "', '" . addslashes($pdata["config"]) . "', " . time() . " )";
			$query = "INSERT INTO " . $table . $columns . $values;
			$status = $this->db->query($query);
			$resp = array("status" => $status);
			break;
		case 'remcnf':
			$pdata = $params["pdata"];
			$table = $this->db->getBVTable("airlift_config");
			if (is_array($pdata["key_value"])) {
				$escaped_key_values = array_map('addslashes', $pdata["key_value"]);
				$key_value_in_clause = "'" . implode("','", $escaped_key_values) . "'";
				$query = "DELETE FROM " . $table . " WHERE " . $pdata["key_name"] . " IN (" . $key_value_in_clause . ")";
				$resp = array("status" => $this->db->query($query));
			}
			break;
		case 'prge_cach':
			$resp = array();
			if (isset($params['purge_cache_params'])) {
				$purge_params = $params['purge_cache_params'];
			} else {
				$resp['invalid_params'] = true;
				break;
			}

			if (isset($purge_params['clear_cached_post_ids']) || isset($purge_params['clear_cached_urls']) || isset($purge_params['clear_cached_url_prefixes'])) {
				$post_resp = $this->clearPostsSpecificCache($purge_params);
				if (!empty($post_resp)) {
					$resp['post_resp'] = $post_resp;
				}
			}

			if (isset($purge_params['purge_all'])) {
				$host_resp = array();
				if (isset($purge_params['purge_host'])) {
					$host_resp = $this->clearCompleteHostCache($purge_params);
				} elseif (isset($purge_params['varnish_params'])) {
					#XNOTE: Handling for unknown hosts
					$host_resp['varnish_cache'] = $this->clearVarnishCache($purge_params['varnish_params']);
				}

				if (!empty($host_resp)) {
					$resp['host_resp'] = $host_resp;
				}
			}
			
			if ($this->arrayContainsValue($purge_params, 'options', 'purge_object')) {
				if (function_exists('wp_cache_flush')) {
					$resp['object_cache_purged'] = wp_cache_flush();
				} else {
					$resp['object_cache_purged'] = false;
				}
			}

			if ($this->arrayContainsValue($purge_params, 'options', 'purge_complete_cache_storage')) {
				$resp['purge_complete_cache_storage'] = ALWPFileSystem::getInstance()->rmdir(ALWPCache::$cache_path, true);
			}

			if ($this->arrayContainsValue($purge_params, 'options', 'purge_alcache')) {
				$resp['purge_alcache'] = ALWPFileSystem::getInstance()->rmdir(ALWPCache::$so_cache_path, true);
			}
			break;
		case "actvt_cach":
			$resp = array("status" => ALWPCache::enableCache($this->bvinfo));
			break;
		case "deactvt_cach":
			$resp = array("status" => ALWPCache::disableCache($this->bvinfo));
			break;
		case "gt_othr_excld_attrs":
			$resp = array("urls_to_exclude" => $this->get_third_party_urls_to_exclude($params));
			break;
		case "dsbl_et_cache":
			if (isset($params["et_metaname"]) && isset($params["et_option_name"]) &&
					isset($params["et_option_value"])) {
				$et_metaname = $params["et_metaname"];
				$et_option_name = $params["et_option_name"];
				$et_option_value = $params["et_option_value"];
				$et_settings = $this->settings->getOption($et_metaname);
				if (isset($et_settings) && is_array($et_settings)) {
					$et_settings[$et_option_name] = $et_option_value;
					$resp = array("status" => $this->settings->updateOption($et_metaname, $et_settings));
				}
			}
			break;
		}
		return $resp;
	}
}
endif;
