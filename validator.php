<?php

if (!class_exists('ALValidator')) :
	class ALValidator {
		public $cache_config;
		public $ignore_files;
		public $ignore_extensions;
		public $allowed_methods;
		public $cache_ssl;
		public $ignored_request_uri_regex;
		public $ignored_user_agents_for_optimization;
		public $allowed_user_agents_for_optimization;
		public $do_not_optimize;
		public $ignored_query_params_for_optimization;
		public $ignore_opt_for_all_query_params;
		public $allowed_cookies_for_optimization;
		public $ignored_cookies_for_optimization;
		public $ignored_query_params_regexes_for_optimization;
		public $allowed_query_params_regexes_for_optimization;
		public $skip_empty_user_agent_check;

		public function __construct($config) {
			$cache_config = isset($config['cache_params']) && is_array($config['cache_params']) ? $config['cache_params'] : null;
			if (!isset($cache_config)) {
				$this->do_not_optimize = true;
			} else {
				$this->do_not_optimize = false;
				$this->ignore_files = isset($cache_config['ignore_files']) ? $cache_config['ignore_files'] : array();
				$this->ignore_extensions = isset($cache_config['ignore_extensions']) ? $cache_config['ignore_extensions'] : array();
				$this->allowed_methods = isset($cache_config['allowed_methods']) ? $cache_config['allowed_methods'] : array();
				$this->cache_ssl = isset($cache_config['cache_ssl']) ? $cache_config['cache_ssl'] : array();
				$this->ignored_request_uri_regex = isset($cache_config['ignored_request_uri_regex']) ? $cache_config['ignored_request_uri_regex'] : array();
				$this->ignored_cookies_for_optimization = isset($cache_config['ignored_cookies_for_optimization'])
					? $cache_config['ignored_cookies_for_optimization']
					: (isset($cache_config['ignored_cookies']) ? $cache_config['ignored_cookies'] : array());
				$this->allowed_cookies_for_optimization = isset($cache_config['allowed_cookies_for_optimization'])
					? $cache_config['allowed_cookies_for_optimization']
					: (isset($cache_config['skip_cookies_to_ignore_optimization']) ? $cache_config['skip_cookies_to_ignore_optimization'] : array());
				$this->ignored_user_agents_for_optimization = isset($cache_config['ignored_user_agents_for_optimization'])
					? $cache_config['ignored_user_agents_for_optimization']
					: (isset($cache_config['ignored_user_agents']) ? $cache_config['ignored_user_agents'] : array());
				$this->ignored_query_params_for_optimization = isset($cache_config['ignored_query_params_for_optimization'])
					? $cache_config['ignored_query_params_for_optimization']
					: (isset($cache_config['ignored_query_params']) ? $cache_config['ignored_query_params'] : array());
				$this->ignore_opt_for_all_query_params = isset($cache_config['ignore_opt_for_all_query_params'])
					? $cache_config['ignore_opt_for_all_query_params']
					: (isset($cache_config['ignore_all_query_params']) ? $cache_config['ignore_all_query_params'] : false);
				$this->skip_empty_user_agent_check = isset($cache_config['skip_empty_user_agent_check']) ? $cache_config['skip_empty_user_agent_check'] : false;
				$this->allowed_user_agents_for_optimization = isset($cache_config['allowed_user_agents_for_optimization'])
					? $cache_config['allowed_user_agents_for_optimization']
					: array();
				$this->ignored_query_params_regexes_for_optimization = isset($cache_config['ignored_query_params_regexes_for_optimization'])
					? $cache_config['ignored_query_params_regexes_for_optimization']
					: false;
				$this->allowed_query_params_regexes_for_optimization = isset($cache_config['allowed_query_params_regexes_for_optimization'])
					? $cache_config['allowed_query_params_regexes_for_optimization']
					: false;
			}
		}

		public function isIgnoredFile() {
			$request_uri = ALCacheHelper::getRequestUriBase();
			foreach ($this->ignore_files as $file) {
				if (strpos($request_uri, '/' . $file)) {
					return true;
				}
			}
			return false;
		}

		public function isIgnoredExtension() {
			$request_uri = ALCacheHelper::getRequestUriBase();
			if (strtolower($request_uri) === '/index.php') {
				return false;
			}
			$extension = pathinfo($request_uri, PATHINFO_EXTENSION);
			return $extension && in_array($extension, $this->ignore_extensions);
		}

		public function isIgnoredRequestMethod() {
			$method = ALHelper::getRawParam('SERVER', 'REQUEST_METHOD');
			if (in_array($method, $this->allowed_methods)) {
				return false;
			}
			return true;
		}

		
		private function checkQueryParamsUsingRegex() {
			$params = ALCacheHelper::getQueryParams();
			if (!$params) {
				return false;
			}

			// Strict mode: allow only specific query params, and skip if ignored ones exist
			if (!empty($this->ignore_opt_for_all_query_params)) {
				// Check each query param against patterns
				foreach (array_keys($params) as $query_param) {
					// FIRST: Check if query param matches ignore patterns (highest priority)
					if (!empty($this->ignored_query_params_regexes_for_optimization) && is_array($this->ignored_query_params_regexes_for_optimization)) {
						foreach ($this->ignored_query_params_regexes_for_optimization as $ignored_pattern) {
							if (ALHelper::safePregMatch($ignored_pattern, $query_param)) {
								return true; // Skip optimization - ignored takes absolute priority
							}
						}
					}
				}

				foreach (array_keys($params) as $query_param) {
					// SECOND: Check if query param matches allowed patterns
					if (!empty($this->allowed_query_params_regexes_for_optimization) && is_array($this->allowed_query_params_regexes_for_optimization)) {
						foreach ($this->allowed_query_params_regexes_for_optimization as $allowed_pattern) {
							if (ALHelper::safePregMatch($allowed_pattern, $query_param)) {
								return false;
							}
						}
					}
				}

				// If we reach here, no ignored params found and no allowed params found
				// No ignored and no allowed params found → skip optimization (strict mode)
				return true;
			}

			// Default behavior when strict mode is off
			// Check each query param against ignore patterns (ignored takes priority)
			foreach (array_keys($params) as $query_param) {
				if (!empty($this->ignored_query_params_regexes_for_optimization) && is_array($this->ignored_query_params_regexes_for_optimization)) {
					foreach ($this->ignored_query_params_regexes_for_optimization as $ignored_pattern) {
						if (ALHelper::safePregMatch($ignored_pattern, $query_param)) {
							return true; // Skip optimization
						}
					}
				}
			}

			return false; // Default allow optimization
		}

		/**
		 * Check query params using exact match (deprecated - for older config versions)
		 * @deprecated This method is deprecated for newer config versions, use regex patterns instead
		 * @return bool true if optimization should be skipped
		 */
		private function checkQueryParamsUsingExactMatch() {
			$params = ALCacheHelper::getQueryParams();
			if (!$params) {
				return false;
			}

			if (!!$this->ignore_opt_for_all_query_params) {
				return true;
			}
			  
			if (array_intersect_key($params, array_flip($this->ignored_query_params_for_optimization))) {
				return true;
			}
		
			return false; // Default allow optimization
		}

		public function isIgnoredQueryString() {
			$al_debug_mode = ALHelper::getRawParam('GET', 'al_debug_mode');
			if (!empty($al_debug_mode)) {
				return false;
			}

			// Check if newer regex-based config is available
			if (!empty($this->ignored_query_params_regexes_for_optimization) || !empty($this->allowed_query_params_regexes_for_optimization)) {
				// Use newer regex-based method
				return $this->checkQueryParamsUsingRegex();
			} else {
				// Fall back to deprecated exact match method for backward compatibility
				return $this->checkQueryParamsUsingExactMatch();
			}
		}

		public function canCacheSSL() {
			if (function_exists('is_ssl')) {
				return !is_ssl() || $this->cache_ssl;
			}
			return true;
		}

		public function isIgnoredRequestURI() {
			$request_uri = ALCacheHelper::getRequestURIBase();
			foreach ($this->ignored_request_uri_regex as $regex) {
				if (ALHelper::safePregMatch($regex, $request_uri)) {
					return true;
				}
			}
			return false;
		}

		public function hasIgnoredCookies() {
			if (!is_array($_COOKIE) || empty( $_COOKIE )) {
				return false;
			}

			foreach (array_keys($_COOKIE) as $cookie_name) {
				$is_skipped = false;
				
				// First check if cookie should be skipped from ignore list
				foreach ($this->allowed_cookies_for_optimization as $skip_cookie) {
					if (ALHelper::safePregMatch($skip_cookie, $cookie_name)) {
						$is_skipped = true;
						break;
					}
				}

				// If cookie is not in skip list, check if it matches ignore patterns
				if (!$is_skipped) {
					foreach ($this->ignored_cookies_for_optimization as $ignored_cookie) {
						if (ALHelper::safePregMatch($ignored_cookie, $cookie_name)) {
							return true;
						}
					}
				}
			}
			return false;
		}

		public function hasIgnoredUserAgents() {
			$user_agent = ALHelper::getRawParam('SERVER', 'HTTP_USER_AGENT');
			if (!isset($user_agent)) {
				return $this->skip_empty_user_agent_check ? false : true;
			}

			// First check if user agent should be allowed (skip prevention)
			if (is_array($this->allowed_user_agents_for_optimization)) {
				foreach ($this->allowed_user_agents_for_optimization as $allowed_ua) {
					if (ALHelper::safePregMatch($allowed_ua, $user_agent)) {
						return false;
					}
				}
			}
			
			// If user agent is not in allow list, check if it matches ignore patterns
			if (is_array($this->ignored_user_agents_for_optimization)) {
				foreach ($this->ignored_user_agents_for_optimization as $ignored_ua) {
					if (ALHelper::safePregMatch($ignored_ua, $user_agent)) {
						return true;
					}
				}
			}
			
			return false;
		}

		public function hasDonotCachePage() {
			if (defined('AL_DONOTCACHEPAGE') && AL_DONOTCACHEPAGE) {
				return true;
			}
			return false;
		}

		public function shouldSkipOptimization() {
			if (defined('AL_DONOTOPTIMIZEPAGE') && AL_DONOTOPTIMIZEPAGE) {
				return true;
			}
			return false;
		}

		public function checkIfSearchQuery() {
			global $wp_query;
			if (!isset($wp_query)) {
				return false;
			}
			return $wp_query->is_search();
		}

		public function canOptimizeBuffer($buffer) {
			if (strlen($buffer) <= 255 || http_response_code() !== 200 || $this->shouldSkipOptimization() || $this->checkIfSearchQuery()) {
				return false;
			}
			return true;
		}

		public function canOptimizePage() {
			if ($this->do_not_optimize || $this->isIgnoredFile() || $this->isIgnoredExtension() || $this->isIgnoredRequestMethod() ||
					is_admin() || $this->isIgnoredQueryString() || !$this->canCacheSSL() ||
					$this->isIgnoredRequestURI() || $this->hasIgnoredCookies() || $this->hasIgnoredUserAgents()) {
				return false;
			}
			return true;
		}
	}
endif;