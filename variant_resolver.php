<?php

if (!class_exists('ALImageVariantResolver')) :

	class ALImageVariantResolver {
		const DEVICE_MOBILE = 'mobile';
		const DEVICE_IPAD = 'ipad';
		const DEVICE_DESKTOP = 'desktop';

		private $config;
		private $image_config;
		private $optimized_extension;

		public function __construct($config, $image_config) {
			$this->config = is_array($config) ? $config : array();
			$this->image_config = is_array($image_config) ? $image_config : array();
			$this->optimized_extension = '.bv.' . $this->optimizationFormat();
		}

		public function resolveLarge($image_url, $can_use_optimized = true) {
			$full_url = $this->absoluteUrl($image_url);
			if (!$can_use_optimized) {
				return $this->originalVariant($full_url);
			}

			$optimized_url = $this->withOptimizedExtension($full_url);
			$variant = $this->variantIfPresent($optimized_url);
			return $variant ? $variant : $this->originalVariant($full_url);
		}

		public function resolveForDevice($image_url, $device, $can_resize = true, $can_use_optimized = true) {
			$full_url = $this->absoluteUrl($image_url);
			if ($can_resize && $can_use_optimized && $device) {
				foreach ($this->resizedCandidates($full_url, $device) as $candidate) {
					$variant = $this->variantIfPresent($candidate['url'], $candidate['token']);
					if ($variant) {
						return $variant;
					}
				}
			}

			return $this->resolveLarge($full_url, $can_use_optimized);
		}

		public function availableVariants($image_url, $can_resize = true, $can_use_optimized = true) {
			$full_url = $this->absoluteUrl($image_url);
			$variants = array();

			if ($can_resize && $can_use_optimized) {
				foreach (array(self::DEVICE_MOBILE, self::DEVICE_IPAD, self::DEVICE_DESKTOP) as $device) {
					foreach ($this->resizedCandidates($full_url, $device) as $candidate) {
						$variant = $this->variantIfPresent($candidate['url'], $candidate['token']);
						if ($variant && !empty($variant['is_resized'])) {
							$this->addVariantByWidth($variants, $variant);
							break;
						}
					}
				}
			}

			$this->addVariantByWidth($variants, $this->resolveLarge($full_url, $can_use_optimized));
			usort($variants, function($left, $right) {
				return absint($left['width']) - absint($right['width']);
			});

			return array_values($variants);
		}

		public function resizedInfos($image_url, $can_resize = true, $can_use_optimized = true) {
			if (!$can_resize || !$can_use_optimized) {
				return '';
			}

			$parts = array();
			foreach (array(self::DEVICE_MOBILE, self::DEVICE_IPAD, self::DEVICE_DESKTOP) as $device) {
				$variant = $this->resolveForDevice($image_url, $device, true, true);
				if (!$variant || empty($variant['resized_token']) || empty($variant['width']) || empty($variant['height']) || empty($variant['is_resized'])) {
					continue;
				}
				$parts[] = $variant['resized_token'] . ':' . absint($variant['width']) . '*' . absint($variant['height']);
			}
			return implode(';', $parts);
		}

		public function originalVariant($image_url) {
			$full_url = $this->absoluteUrl($image_url);
			$size = $this->imageSizeForUrl($full_url, false);
			return array(
				'url' => $full_url,
				'width' => $size ? absint($size[0]) : 0,
				'height' => $size ? absint($size[1]) : 0,
				'is_resized' => false,
				'is_optimized' => false
			);
		}

		public function optimizedBaseUrl($optimized_url) {
			$parts = preg_split('/([?#])/', (string) $optimized_url, 2, PREG_SPLIT_DELIM_CAPTURE);
			$path = isset($parts[0]) ? $parts[0] : '';
			if (substr($path, -strlen($this->optimized_extension)) === $this->optimized_extension) {
				$path = substr($path, 0, -strlen($this->optimized_extension));
			}
			return $path . (count($parts) > 1 ? implode('', array_slice($parts, 1)) : '');
		}

		private function addVariantByWidth(&$variants, $variant) {
			if (!$variant || empty($variant['url']) || empty($variant['width'])) {
				return;
			}

			$width = absint($variant['width']);
			if (isset($variants[$width])) {
				if (!empty($variants[$width]['is_resized']) && empty($variant['is_resized'])) {
					return;
				}
			}

			$variants[$width] = $variant;
		}

		public function absoluteUrl($url) {
			$url = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5);
			$url = trim($url, "\"' ");
			if ($url === '' || stripos($url, 'data:') === 0) {
				return $url;
			}

			if (strpos($url, '//') === 0) {
				return $this->currentScheme() . ':' . $url;
			}

			if (preg_match('~^https?://~i', $url)) {
				return $url;
			}

			$host = ALHelper::getRawParam('SERVER', 'HTTP_HOST');
			$scheme = $this->currentScheme();
			if (strpos($url, '/') === 0) {
				return $scheme . '://' . $host . $url;
			}

			$path = ALHelper::getRawParam('SERVER', 'REQUEST_URI');
			$path = $path ? dirname(parse_url($path, PHP_URL_PATH)) : '';
			return $scheme . '://' . $host . rtrim($path, '/') . '/' . ltrim($url, './');
		}

		private function resizedCandidates($full_url, $device) {
			$token = $this->deviceToken($device);
			$extension = $this->extension($full_url);
			if ($token === '' || $extension === '') {
				return array();
			}

			$base_url = $this->urlWithoutQuery($full_url);
			$new_notation = preg_replace('~(\.[^\/.]+)$~', '-' . $token . '$1', $base_url) . $this->optimized_extension;
			$legacy_notation = $base_url . '.bv_resized_' . $device . '.' . $extension . $this->optimized_extension;
			$new_candidate = array('token' => $token . '.' . $extension, 'url' => $new_notation);
			$legacy_candidate = array('token' => 'bv_resized_' . $device . '.' . $extension, 'url' => $legacy_notation);

			if ($this->usesDimensionTokenResize()) {
				return array($new_candidate, $legacy_candidate);
			}

			return array($legacy_candidate, $new_candidate);
		}

		private function usesDimensionTokenResize() {
			return !empty($this->image_config['image_file_version']) && (string) $this->image_config['image_file_version'] === '1.2';
		}

		private function variantIfPresent($candidate_url, $resized_token = '') {
			$path = $this->optimizedStoragePath($candidate_url);
			if (!$path || !file_exists($path)) {
				return false;
			}

			$size = @getimagesize($path);
			$public_url = $this->deliveryUrl($candidate_url);
			return array(
				'url' => $public_url,
				'width' => is_array($size) ? absint($size[0]) : 0,
				'height' => is_array($size) ? absint($size[1]) : 0,
				'is_resized' => $this->isResizedCandidate($candidate_url),
				'resized_token' => $resized_token,
				'is_optimized' => true
			);
		}

		private function optimizedStoragePath($url) {
			$parsed = parse_url($url);
			if (!$parsed || empty($parsed['host']) || empty($parsed['path'])) {
				return false;
			}

			$upload_dir = wp_upload_dir();
			if (empty($upload_dir['basedir'])) {
				return false;
			}

			return rtrim($upload_dir['basedir'], '/') . '/al_opt_content/IMAGE/' . $parsed['host'] . '/' . ltrim(rawurldecode($parsed['path']), '/');
		}

		private function imageSizeForUrl($url, $optimized_path = true) {
			$path = $optimized_path ? $this->optimizedStoragePath($url) : $this->localSitePath($url);
			if (!$path || !is_readable($path)) {
				return false;
			}
			$size = @getimagesize($path);
			return is_array($size) ? $size : false;
		}

		private function localSitePath($url) {
			$parsed = parse_url($url);
			if (!$parsed || empty($parsed['path'])) {
				return false;
			}

			$path = rawurldecode($parsed['path']);
			$upload_dir = wp_upload_dir();
			if (!empty($upload_dir['baseurl']) && !empty($upload_dir['basedir'])) {
				$upload_path = parse_url($upload_dir['baseurl'], PHP_URL_PATH);
				$upload_path = rtrim($upload_path ? $upload_path : '', '/');
				if ($upload_path !== '' && strpos($path, $upload_path . '/') === 0) {
					return rtrim($upload_dir['basedir'], '/') . substr($path, strlen($upload_path));
				}
			}

			if (defined('ABSPATH')) {
				return rtrim(ABSPATH, '/') . '/' . ltrim($path, '/');
			}

			return false;
		}

		private function deliveryUrl($full_url) {
			$parsed = parse_url($full_url);
			if (!$parsed || empty($parsed['host']) || empty($parsed['path'])) {
				return $full_url;
			}

			$url = $this->replaceHostWithOptimizedBase($full_url, $parsed['host']);
			if ($url !== $full_url) {
				return $url;
			}

			if (!empty($this->image_config['copied_to_site']) && !empty($this->config['image_url_path'])) {
				return rtrim($this->config['image_url_path'], '/') . '/' . $parsed['host'] . '/' . ltrim($parsed['path'], '/');
			}

			return $this->replaceHostWithCdn($full_url, $parsed['host']);
		}

		private function replaceHostWithOptimizedBase($url, $host) {
			if (!empty($this->image_config['replace_urls']) && is_array($this->image_config['replace_urls'])) {
				foreach ($this->image_config['replace_urls'] as $replace_url) {
					if (!is_array($replace_url) || empty($replace_url['search_for']) || !isset($replace_url['replace_with'])) {
						continue;
					}
					if (strpos($url, $replace_url['search_for']) !== false) {
						return $this->optimizedUrlFromBase($replace_url['replace_with'], $host, $url);
					}
				}
			}

			return $url;
		}

		private function optimizedUrlFromBase($base_url, $host, $url) {
			$parsed = parse_url($url);
			if (!$parsed || empty($parsed['path'])) {
				return $url;
			}

			$optimized_url = rtrim($base_url, '/') . '/' . $host . '/' . ltrim($parsed['path'], '/');
			if (isset($parsed['query']) && $parsed['query'] !== '') {
				$optimized_url .= '?' . $parsed['query'];
			}
			if (isset($parsed['fragment']) && $parsed['fragment'] !== '') {
				$optimized_url .= '#' . $parsed['fragment'];
			}

			return $optimized_url;
		}

		private function replaceHostWithCdn($url, $host) {
			$optimized_url = $this->replaceHostWithOptimizedBase($url, $host);
			if ($optimized_url !== $url) {
				return $optimized_url;
			}

			if (!empty($this->config['cdn_url'])) {
				$cdn_host = parse_url($this->config['cdn_url'], PHP_URL_HOST);
				if ($cdn_host) {
					return ALHelper::safeStrReplace($host, $cdn_host, $url);
				}
			}

			return $url;
		}

		private function withOptimizedExtension($full_url) {
			return $this->urlWithoutQuery($full_url) . $this->optimized_extension;
		}

		private function urlWithoutQuery($url) {
			return explode('?', (string) $url, 2)[0];
		}

		private function extension($url) {
			$path = parse_url($url, PHP_URL_PATH);
			$extension = pathinfo($path, PATHINFO_EXTENSION);
			return strtolower((string) $extension);
		}

		private function optimizationFormat() {
			$format = isset($this->image_config['image_optimization_format']) ? strtolower((string) $this->image_config['image_optimization_format']) : 'webp';
			return $format === 'avif' ? 'avif' : 'webp';
		}

		private function deviceToken($device) {
			$tokens = array(
				self::DEVICE_MOBILE => '480',
				self::DEVICE_IPAD => '820',
				self::DEVICE_DESKTOP => '1536'
			);
			return isset($tokens[$device]) ? $tokens[$device] : '';
		}

		private function currentScheme() {
			if (function_exists('is_ssl') && is_ssl()) {
				return 'https';
			}
			$https = ALHelper::getRawParam('SERVER', 'HTTPS');
			return !empty($https) && strtolower($https) !== 'off' ? 'https' : 'http';
		}

		private function isResizedCandidate($url) {
			return (bool) preg_match('~(?:-(480|820|1536)\.[^\/.]+|\.bv_resized_(mobile|ipad|desktop)\.[^\/.]+)\.bv\.(?:webp|avif)$~i', $url);
		}
	}
endif;
