<?php

if (!class_exists('ALResponsiveImageSourceBuilder')) :

	class ALResponsiveImageSourceBuilder {
		private $variant_resolver;

		private $devices = array(
			'mobile' => 480,
			'ipad' => 820,
			'desktop' => 1536
		);

		public function __construct($variant_resolver) {
			$this->variant_resolver = $variant_resolver;
		}

		public function build($image_url, $rendering_infos, $can_resize = true, $can_use_optimized = true) {
			$srcset_entries = array();
			$sizes_entries = array();
			$large_variant = $this->variant_resolver->resolveLarge($image_url, $can_use_optimized);
			$default_url = $large_variant && !empty($large_variant['url']) ? $large_variant['url'] : '';
			$default_width = $large_variant && !empty($large_variant['width']) ? absint($large_variant['width']) : 0;
			$has_optimized_variant = $large_variant && !empty($large_variant['is_optimized']);

			foreach ($this->variant_resolver->availableVariants($image_url, $can_resize, $can_use_optimized) as $variant) {
				if (!empty($variant['is_optimized'])) {
					$has_optimized_variant = true;
				}
				if (empty($variant['url']) || empty($variant['width'])) {
					continue;
				}
				$srcset_entries[$variant['url']] = esc_url($variant['url']) . ' ' . absint($variant['width']) . 'w';
				$default_width = max($default_width, absint($variant['width']));
			}

			foreach ($this->devices as $device => $breakpoint) {
				$rendering_width = $this->renderingWidth($rendering_infos, $device);
				if ($rendering_width <= 0 || $rendering_width > $breakpoint) {
					continue;
				}
				$sizes_entries[] = '(max-width: ' . absint($breakpoint) . 'px) ' . absint($rendering_width) . 'px';
			}

			if ($default_width > 0) {
				$sizes_entries[] = absint($default_width) . 'px';
			}

			return array(
				'srcset' => implode(', ', array_values($srcset_entries)),
				'sizes' => implode(', ', $sizes_entries),
				'has_optimized_variant' => $has_optimized_variant,
				'default_url' => $default_url,
				'resized_infos' => $this->variant_resolver->resizedInfos($image_url, $can_resize, $can_use_optimized)
			);
		}

		public function placeholderDimensions($image_url, $rendering_infos = array()) {
			foreach (array_keys($this->devices) as $device) {
				$width = $this->renderingWidth($rendering_infos, $device);
				$height = $this->renderingHeight($rendering_infos, $device);
				if ($width > 0 || $height > 0) {
					return array($width, $height);
				}
			}

			$large_variant = $this->variant_resolver->resolveLarge($image_url, true);
			return array(
				!empty($large_variant['width']) ? absint($large_variant['width']) : 0,
				!empty($large_variant['height']) ? absint($large_variant['height']) : 0
			);
		}

		private function renderingWidth($rendering_infos, $device) {
			$info = $this->deviceInfo($rendering_infos, $device);
			if (!$info) {
				return 0;
			}

			if (isset($info['width'])) {
				return absint(ceil($info['width']));
			}
			if (isset($info['boundingClientRect']) && is_array($info['boundingClientRect']) && isset($info['boundingClientRect']['width'])) {
				return absint(ceil($info['boundingClientRect']['width']));
			}
			if (isset($info['rect']) && is_array($info['rect']) && isset($info['rect']['width'])) {
				return absint(ceil($info['rect']['width']));
			}

			return 0;
		}

		private function renderingHeight($rendering_infos, $device) {
			$info = $this->deviceInfo($rendering_infos, $device);
			if (!$info) {
				return 0;
			}

			if (isset($info['height'])) {
				return absint(ceil($info['height']));
			}
			if (isset($info['boundingClientRect']) && is_array($info['boundingClientRect']) && isset($info['boundingClientRect']['height'])) {
				return absint(ceil($info['boundingClientRect']['height']));
			}
			if (isset($info['rect']) && is_array($info['rect']) && isset($info['rect']['height'])) {
				return absint(ceil($info['rect']['height']));
			}

			return 0;
		}

		private function deviceInfo($rendering_infos, $device) {
			if (!is_array($rendering_infos) || empty($rendering_infos[$device]) || !is_array($rendering_infos[$device])) {
				return false;
			}
			return $rendering_infos[$device];
		}
	}
endif;
