<?php

require_once dirname(__FILE__) . '/../image_optimization/tag_rewriter.php';
require_once dirname(__FILE__) . '/../image_optimization/variant_resolver.php';

if (!class_exists('ALInlineBackgroundImageRewriter')) :

	class ALInlineBackgroundImageRewriter {
		private $extract_regex;
		private $image_config;
		private $not_to_lazyload;
		private $not_to_resize;
		private $tag_rewriter;
		private $variant_resolver;

		public function __construct($config, $image_config, $tag_rewriter) {
			$config = is_array($config) ? $config : array();
			$this->image_config = is_array($image_config) ? $image_config : array();
			$this->extract_regex = !empty($config['optimize_embed_style_tag_images']) && isset($config['extract_css_background_declaration_regex']) ? $config['extract_css_background_declaration_regex'] : '';
			$this->not_to_lazyload = $this->urlLookup(isset($this->image_config['images_not_to_lazyload']) ? $this->image_config['images_not_to_lazyload'] : array());
			$this->not_to_resize = $this->urlLookup(isset($this->image_config['images_not_to_resize']) ? $this->image_config['images_not_to_resize'] : array());
			$this->tag_rewriter = $tag_rewriter;
			$this->variant_resolver = new ALImageVariantResolver($config, $this->image_config);
		}

		public function canRewrite() {
			return is_string($this->extract_regex) && $this->extract_regex !== '' && !empty($this->image_config);
		}

		public function rewrite($style) {
			if (!$this->canRewrite()) {
				return $style;
			}

			return $this->tag_rewriter->safePregReplaceCallback($this->extract_regex, function($match) {
				return $this->tag_rewriter->replaceStyleUrls($match[0], array($this, 'replacementUrl'));
			}, $style);
		}

		public function replacementUrl($url) {
			$large = $this->variant_resolver->resolveLarge($url, true);
			if (empty($large['url'])) {
				return $url;
			}

			$is_optimized = !empty($large['is_optimized']);
			if (empty($this->image_config['lazyload_inline_style_images']) || isset($this->not_to_lazyload[$url])) {
				return $is_optimized ? $large['url'] : $url;
			}

			$can_resize = $is_optimized && !empty($this->image_config['resize_inline_style_images']) && !isset($this->not_to_resize[$url]);
			return $this->tag_rewriter->svgPlaceholder(
				$is_optimized ? $this->variant_resolver->optimizedBaseUrl($large['url']) : $large['url'],
				$is_optimized ? $this->variant_resolver->resizedInfos($url, $can_resize, true) : '',
				isset($large['width']) ? $large['width'] : 0,
				isset($large['height']) ? $large['height'] : 0
			);
		}

		private function urlLookup($urls) {
			$lookup = array();
			foreach (is_array($urls) ? $urls : array() as $url) {
				if (is_scalar($url) && (string) $url !== '') {
					$lookup[(string) $url] = true;
				}
			}
			return $lookup;
		}
	}
endif;
