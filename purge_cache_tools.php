<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('ALPurgeCacheTools')) :

class ALPurgeCacheTools {
	public $bvinfo;
	public $bvapi;
	private $plugin_file;
	private $max_input_length;

	function __construct($bvinfo, $bvapi, $plugin_file, $max_input_length) {
		$this->bvinfo = $bvinfo;
		$this->bvapi = $bvapi;
		$this->plugin_file = $plugin_file;
		$this->max_input_length = $max_input_length;
	}

	public function enqueueAssets() {
		$config = array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('al_purge_cache_urls'),
			'currentUrl' => $this->currentRequestUrl(),
			'emptyMessage' => 'Enter at least one URL or path.',
			'maxInputLength' => $this->max_input_length,
			'maxInputLengthMessage' => 'The URL list is too large. Please enter fewer URLs.',
			'workingMessage' => 'Purging cache...',
			'successMessage' => 'Cache purge has been initiated.',
			'errorMessage' => 'Unable to purge cache. Please try again.',
			'submitLabel' => 'Purge URLs'
		);

		wp_register_script('al-purge-cache-tools', plugins_url('js/purge-cache-tools.js', $this->plugin_file), array(), $this->bvinfo->version, true);
		wp_add_inline_script('al-purge-cache-tools', 'window.ALPurgeCacheTools = ' . wp_json_encode($config) . ';', 'before');
		wp_enqueue_script('al-purge-cache-tools');

		wp_register_style('al-purge-cache-tools', plugins_url('css/purge-cache-tools.css', $this->plugin_file), array(), $this->bvinfo->version);
		wp_enqueue_style('al-purge-cache-tools');
	}

	public function renderModal() {
		$max_purge_urls_input_length = $this->max_input_length;
		require dirname($this->plugin_file) . '/admin/components/purge-cache-modal.php';
	}

	public function handleAjax($can_purge_cache) {
		if (!$can_purge_cache) {
			wp_send_json_error(array('message' => 'You do not have permission to purge cache.'), 403);
		}

		$nonce = ALHelper::getRawParam('POST', 'nonce');
		if (!$nonce || !wp_verify_nonce($nonce, 'al_purge_cache_urls')) {
			wp_send_json_error(array('message' => 'Security check failed. Please refresh the page and try again.'), 403);
		}

		$scope = ALHelper::getStringParamSanitized('POST', 'scope', 'text');
		$raw_urls = ALHelper::getRawParam('POST', 'urls');
		if ($scope !== 'full' && $scope !== 'urls') {
			if (is_string($raw_urls) && trim($raw_urls) !== '') {
				$scope = 'urls';
			} else {
				wp_send_json_error(array('message' => 'Invalid purge request.'), 422);
			}
		}

		$purge_options = array();
		if ($scope === 'urls') {
			if (!is_string($raw_urls)) {
				wp_send_json_error(array('message' => 'Enter at least one URL or path.'), 422);
			}
			if (strlen($raw_urls) > $this->max_input_length) {
				wp_send_json_error(array('message' => 'The URL list is too large. Please enter fewer URLs.'), 422);
			}
			$purge_options['clear_cached_urls'] = $raw_urls;
		} else {
			$purge_options['purge_all'] = true;
		}

		$resp = $this->submitPurgeCache($purge_options);
		$message = $this->handlePurgeCacheErrors($resp);
		$response_code = is_wp_error($resp) ? 500 : wp_remote_retrieve_response_code($resp);
		if ($response_code >= 200 && $response_code < 300) {
			wp_send_json_success(array('message' => $message));
		}
		wp_send_json_error(array('message' => $message), 422);
	}

	public function submitPurgeCache($purge_options) {
		$info = array('purge_options' => $purge_options);
		return $this->bvapi->pingbv('/bvapi/purge_cache', $info);
	}

	public function handlePurgeCacheErrors($resp) {
		$message = 'Something went wrong on our side. We were unable to clear the cache. Please retry after some time or contact us.';
		if (is_wp_error($resp)) {
			return $resp->get_error_message();
		}
		if ($resp && is_array($resp)) {
			$body = wp_remote_retrieve_body($resp);
			$response_code = wp_remote_retrieve_response_code($resp);
			if ($body) {
				return $body;
			} elseif ($response_code >= 200 && $response_code < 300) {
				return "Cache purge has been initiated.";
			}
		}
		return $message;
	}

	private function currentRequestUrl() {
		$request_uri = ALHelper::getRawParam('SERVER', 'REQUEST_URI');
		$host = ALHelper::getRawParam('SERVER', 'HTTP_HOST');
		$scheme = is_ssl() ? 'https' : 'http';
		return esc_url_raw($scheme . '://' . $host . $request_uri);
	}
}
endif;
