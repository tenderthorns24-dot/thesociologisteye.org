<?php

if (!class_exists('ALImageTagRewriter')) :

	class ALImageTagRewriter {
		const IMAGE_URL_REGEX = '~(?:\A|[\s"\'<>,()\[\]{}=;]|&(?:quot|#0?39|#x27);)\K[^\s"\'<>,()\[\]{}&;=]+?\.(?:jpe?g|png|webp|avif|svg)(?:[?#][^\s"\'<>,()\[\]{}]*)?(?=\z|[\s"\'<>,()\[\]{};]|&(?:quot|#0?39|#x27);)~i';

		public function tagName($tag) {
			if (preg_match('~<\s*([a-zA-Z0-9:-]+)~', $tag, $matches)) {
				return strtolower($matches[1]);
			}
			return '';
		}

		public function addClass($tag, $class_name) {
			$classes = $this->attributeValue($tag, 'class');
			if ($classes === null || trim($classes) === '') {
				return $this->setAttribute($tag, 'class', $class_name);
			}

			$class_list = preg_split('/\s+/', trim($classes));
			if (!in_array($class_name, $class_list, true)) {
				$class_list[] = $class_name;
			}
			return $this->setAttribute($tag, 'class', implode(' ', $class_list));
		}

		public function attributeValue($tag, $attr_name) {
			$attrs = $this->attributes($tag);
			$key = strtolower($attr_name);
			return array_key_exists($key, $attrs) ? $attrs[$key]['value'] : null;
		}

		public function setAttribute($tag, $attr_name, $value) {
			$opening_tag = $this->openingTag($tag);
			if ($opening_tag === '') {
				return $tag;
			}

			$attrs = $this->attributes($opening_tag);
			$attrs[strtolower($attr_name)] = array('name' => $attr_name, 'value' => (string) $value);
			$new_opening_tag = $this->buildOpeningTag($opening_tag, $attrs);
			return ALHelper::safeStrReplace($opening_tag, $new_opening_tag, $tag);
		}

		public function removeAttribute($tag, $attr_name) {
			$opening_tag = $this->openingTag($tag);
			if ($opening_tag === '') {
				return $tag;
			}

			$attrs = $this->attributes($opening_tag);
			unset($attrs[strtolower($attr_name)]);
			$new_opening_tag = $this->buildOpeningTag($opening_tag, $attrs);
			return ALHelper::safeStrReplace($opening_tag, $new_opening_tag, $tag);
		}

		public function renameAttribute($tag, $from_attr, $to_attr) {
			$value = $this->attributeValue($tag, $from_attr);
			if ($value === null) {
				return $tag;
			}

			$tag = $this->removeAttribute($tag, $from_attr);
			return $this->setAttribute($tag, $to_attr, $value);
		}

		public function attributes($tag) {
			$opening_tag = $this->openingTag($tag);
			$tag_name = $this->tagName($opening_tag);
			$attr_text = preg_replace('~^<\s*' . preg_quote($tag_name, '~') . '\b~i', '', $opening_tag);
			$attr_text = preg_replace('~/?\s*>$~', '', $attr_text);
			$attrs = array();

			if (preg_match_all('~\s+([^\s=\/>]+)(?:\s*=\s*(["\'])(.*?)\2|\s*=\s*([^\s"\'>]+))?~s', $attr_text, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$name = $match[1];
					$value = isset($match[3]) && $match[3] !== '' ? $match[3] : (isset($match[4]) ? $match[4] : '');
					$attrs[strtolower($name)] = array('name' => $name, 'value' => html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
				}
			}

			return $attrs;
		}

		public function openingTag($tag) {
			$tag = (string) $tag;
			$length = strlen($tag);
			$quote = '';
			$start = strpos($tag, '<');
			if ($start === false) {
				return '';
			}

			for ($index = $start; $index < $length; $index++) {
				$char = $tag[$index];
				if (($char === '"' || $char === "'") && ($index === 0 || $tag[$index - 1] !== '\\')) {
					$quote = $quote === $char ? '' : ($quote === '' ? $char : $quote);
					continue;
				}
				if ($char === '>' && $quote === '') {
					return substr($tag, $start, $index - $start + 1);
				}
			}

			return '';
		}

		public function imageUrls($value) {
			if (!is_string($value) || trim($value) === '') {
				return array();
			}

			preg_match_all(self::IMAGE_URL_REGEX, $value, $matches);
			return array_values(array_unique(array_filter($matches[0])));
		}

		public function srcsetUrls($value) {
			$urls = array();
			foreach (explode(',', (string) $value) as $candidate) {
				$parts = preg_split('/\s+/', trim($candidate));
				if (!empty($parts[0]) && !$this->isDataImage($parts[0])) {
					$urls[] = $parts[0];
				}
			}
			return array_values(array_unique($urls));
		}

		public function srcsetWidthCandidates($value) {
			$candidates = array();
			foreach (explode(',', (string) $value) as $candidate) {
				$parts = preg_split('/\s+/', trim($candidate));
				if (empty($parts[0]) || $this->isDataImage($parts[0])) {
					continue;
				}

				$url = array_shift($parts);
				foreach ($parts as $descriptor) {
					if (preg_match('/^(\d+)w$/i', $descriptor, $matches)) {
						$candidates[] = array(
							'url' => $url,
							'width' => absint($matches[1])
						);
						break;
					}
				}
			}
			return $candidates;
		}

		public function replaceSrcsetUrls($value, $callback) {
			$entries = array();
			foreach (explode(',', (string) $value) as $candidate) {
				$candidate = trim($candidate);
				if ($candidate === '') {
					continue;
				}
				$parts = preg_split('/\s+/', $candidate);
				$url = array_shift($parts);
				$new_url = call_user_func($callback, $url);
				$entries[] = trim($new_url . (empty($parts) ? '' : ' ' . implode(' ', $parts)));
			}
			return implode(', ', $entries);
		}

		public function styleUrls($value) {
			$urls = array();
			if (preg_match_all('~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*?))\s*\)~i', (string) $value, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$url = isset($match[1]) && $match[1] !== '' ? $match[1] : (isset($match[2]) && $match[2] !== '' ? $match[2] : $match[3]);
					$url = trim($url);
					if ($url !== '' && !$this->isDataImage($url)) {
						$urls[] = $url;
					}
				}
			}
			return array_values(array_unique($urls));
		}

		public function replaceStyleUrls($value, $callback) {
			return $this->safePregReplaceCallback('~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*?))\s*\)~i', function($match) use ($callback) {
				$url = isset($match[1]) && $match[1] !== '' ? $match[1] : (isset($match[2]) && $match[2] !== '' ? $match[2] : $match[3]);
				if ($this->isDataImage($url)) {
					return $match[0];
				}
				return 'url("' . call_user_func($callback, trim($url)) . '")';
			}, (string) $value);
		}

		public function jsonImageUrls($value) {
			$decoded = json_decode(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5), true);
			if (!is_array($decoded)) {
				return $this->imageUrls($value);
			}

			$urls = array();
			$this->walkJsonStrings($decoded, function($string) use (&$urls) {
				$urls = array_merge($urls, $this->imageUrls($string));
			});
			return array_values(array_unique($urls));
		}

		public function replaceJsonUrls($value, $callback) {
			$decoded = json_decode(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5), true);
			if (!is_array($decoded)) {
				return $this->replaceImageUrls($value, $callback);
			}

			$this->walkJsonStrings($decoded, function(&$string) use ($callback) {
				$string = $this->replaceImageUrls($string, $callback);
			});
			return wp_json_encode($decoded);
		}

		public function replaceImageUrls($value, $callback) {
			return $this->safePregReplaceCallback(self::IMAGE_URL_REGEX, function($match) use ($callback) {
				if ($this->isDataImage($match[0])) {
					return $match[0];
				}
				return call_user_func($callback, $match[0]);
			}, (string) $value);
		}

		public function safePregReplaceCallback($pattern, $callback, $subject, $limit = -1) {
			if (!is_string($pattern) || !is_callable($callback) || !is_string($subject) || !is_int($limit)) {
				return $subject;
			}

			$updated_subject = @preg_replace_callback($pattern, $callback, $subject, $limit);
			if (!is_string($updated_subject)) {
				return $subject;
			}
			if (function_exists('preg_last_error') && preg_last_error() !== PREG_NO_ERROR) {
				return $subject;
			}

			return $updated_subject;
		}

		public function svgPlaceholder($image_url, $resized_infos = '', $width = 0, $height = 0) {
			$width = absint($width);
			$height = absint($height);
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . max(1, $width) . ' ' . max(1, $height) . '"';
			if ($width > 0 && $height > 0) {
				$svg .= ' width="' . $width . '" height="' . $height . '"';
			}
			$svg .= ' bv-img-url="' . esc_attr($image_url) . '"';
			if ($resized_infos !== '') {
				$svg .= ' bv-resized-infos="' . esc_attr(base64_encode($resized_infos)) . '"';
			}
			$svg .= '></svg>';
			return 'data:image/svg+xml;base64,' . base64_encode($svg);
		}

		private function buildOpeningTag($opening_tag, $attrs) {
			$tag_name = $this->tagName($opening_tag);
			if ($tag_name === '') {
				return $opening_tag;
			}

			$parts = array('<' . $tag_name);
			foreach ($attrs as $attr) {
				$name = $attr['name'];
				$value = $attr['value'];
				$parts[] = $name . '="' . esc_attr($value) . '"';
			}

			$self_closing = preg_match('~/\s*>$~', $opening_tag);
			return implode(' ', $parts) . ($self_closing ? ' />' : '>');
		}

		private function walkJsonStrings(&$value, $callback) {
			if (is_array($value)) {
				foreach ($value as &$child) {
					$this->walkJsonStrings($child, $callback);
				}
				return;
			}

			if (is_string($value)) {
				call_user_func_array($callback, array(&$value));
			}
		}

		private function isDataImage($url) {
			return stripos(trim((string) $url), 'data:image/') === 0;
		}
	}
endif;
