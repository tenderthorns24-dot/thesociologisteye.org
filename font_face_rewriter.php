<?php

if (!class_exists('ALInlineFontFaceRewriter')) :

	class ALInlineFontFaceRewriter {
		private $extract_regex;
		private $replacements = array();
		private $tag_rewriter;

		public function __construct($extract_regex, $replace_rules, $tag_rewriter) {
			$this->extract_regex = is_string($extract_regex) ? $extract_regex : '';
			$this->tag_rewriter = $tag_rewriter;

			foreach (is_array($replace_rules) ? $replace_rules : array() as $rule) {
				if (!is_array($rule) || empty($rule['search_for']) || !array_key_exists('replace_with', $rule)) {
					continue;
				}
				$this->replacements[$this->normalize($rule['search_for'])] = (string) $rule['replace_with'];
			}
		}

		public function canRewrite() {
			return $this->extract_regex !== '' && !empty($this->replacements);
		}

		public function rewrite($style) {
			if (!$this->canRewrite()) {
				return $style;
			}

			return $this->tag_rewriter->safePregReplaceCallback($this->extract_regex, function($match) {
				$key = $this->normalize($match[0]);
				return array_key_exists($key, $this->replacements) ? $this->replacements[$key] : $match[0];
			}, $style);
		}

		private function normalize($font_face) {
			$normalized = preg_replace('/\s+/', '', (string) $font_face);
			return is_string($normalized) ? $normalized : '';
		}
	}
endif;
