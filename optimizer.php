<?php
require_once dirname(__FILE__) . '/helper.php';
require_once dirname(__FILE__) . '/asset_content_normalizer.php';
require_once dirname(__FILE__) . '/image_optimization/handler.php';
require_once dirname(__FILE__) . '/inline_style/handler.php';
require_once dirname(__FILE__) . '/../wp_db.php';
require_once dirname(__FILE__) . '/../public_url_purge.php';

if (!class_exists('ALOptimizer')) :

	class ALOptimizer {
		# Protect script blocks and unterminated tails, then remove only complete outside HTML comments.
		# Possessive quantifiers prevent backtrack exhaustion on large response buffers.
		const COMMENT_REMOVAL_PRESERVE_SCRIPTS_REGEX = '~<script\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>(?:(?!</script\s*>)[\s\S])*+(?:</script\s*>|\z)(*SKIP)(*F)|<!--(?:(?!--!?>)[\s\S])*+\z(*SKIP)(*F)|<!--(?:(?!--!?>)[\s\S])*+--!?>~i';
		const TEMPLATE_CATEGORY_POST = 1;
		const TEMPLATE_CATEGORY_TAXONOMY = 2;

		public static $airlift_stats_table = 'airlift_stats';
		public static $airlift_config_table = 'airlift_config';
		public $config;
		public $db;
		public $image_config;
		public $alinfo;
		private $asset_content_digest_cache = array();
		private $asset_normalization_time_ms = 0.0;

		public function __construct($config, $alinfo) {
			$this->config = $config;
			$this->db = new ALWPDb();
			$this->alinfo = $alinfo;
			$this->asset_content_digest_cache = array();
			$this->asset_normalization_time_ms = 0.0;
		}

		public function addOptimizationInfo($template_config_id, $page_audits) {
			$public_url_config = isset($this->config['public_urls']) && is_array($this->config['public_urls']) ?
				$this->config['public_urls'] : array();
			if (!isset($public_url_config['collect']) || $public_url_config['collect'] !== true) {
				return;
			}
			$query_string = ALCacheHelper::getQueryString();
			if (is_string($query_string) && $query_string !== '') {
				return;
			}
			$max_rows = isset($public_url_config['max_stats_rows']) ?
				min((int) $public_url_config['max_stats_rows'], 200000) : 200000;
			$table = $this->db->getBVTable(self::$airlift_stats_table);
			if ($max_rows <= 0 || $this->db->rowsCount($table) >= $max_rows) {
				return;
			}
			$page_audits = is_array($page_audits) ? $page_audits : array();
			$page_audits['asset_normalization_time_ms'] = round($this->asset_normalization_time_ms, 2);
			$page_audits['public_url'] = ALPublicUrlPurge::currentPublicUrlIdentity();
			$values = array(
				"path" => ALCacheHelper::getRequestURIBase(),
				"query_string" => $query_string,
				"time" => time(),
				"page_audits" => serialize($page_audits),
				"template_config_id" => $template_config_id
			);
			$this->db->replaceIntoBVTable(self::$airlift_stats_table, $values);
		}

		public function getHtmlDataToReplace($template_config) {
			if (array_key_exists('html_contents_to_replace', $template_config) && !empty($template_config['html_contents_to_replace'])) {
				return $template_config['html_contents_to_replace'];
			}
			return array();
		}

		public function matchingUrlConfig($rows) {
			$current_urls = $this->currentRequestUrls();
			$reversedRows = array_reverse($rows);
			foreach($reversedRows as $row) {
				if (isset($row['url']) && in_array($this->normalizeTemplateUrl($row['url']), $current_urls, true)) {
					return ['id' => $row['id']];
				}
			}
			return false;
		}

		private function currentRequestUrls() {
			global $wp;
			$urls = array();
			$urls[] = home_url($wp->request);
			$urls[] = home_url(ALCacheHelper::getCleanRequestURI());
			$normalized_urls = array();
			foreach ($urls as $url) {
				$normalized_url = $this->normalizeTemplateUrl($url);
				if (!empty($normalized_url)) {
					$normalized_urls[] = $normalized_url;
				}
			}
			return array_values(array_unique($normalized_urls));
		}

		private function normalizeTemplateUrl($url) {
			if (!is_string($url)) {
				return '';
			}
			return rtrim(trim($url), '/');
		}

		private function mainRequestContext() {
			global $wp_the_query;
			if (!isset($wp_the_query) || !($wp_the_query instanceof WP_Query)) {
				return ['kind' => 'unknown'];
			}

			if (!$wp_the_query->is_singular()) {
				return ['kind' => 'non_singular'];
			}

			$queried_object = $wp_the_query->get_queried_object();
			if (!($queried_object instanceof WP_Post)) {
				return ['kind' => 'unknown'];
			}

			$post_id = (int) $queried_object->ID;
			if ($post_id <= 0) {
				return ['kind' => 'unknown'];
			}

			return ['kind' => 'singular', 'post_id' => $post_id];
		}

		public function getTemplateforSimilarPosts($configs) {
			$request_context = $this->mainRequestContext();
			if ($request_context['kind'] !== 'singular') {
				return false;
			}
			$post_id = $request_context['post_id'];
			$reversedRows = array_reverse($configs);
			foreach ($reversedRows as $row) {
				if (!isset($row['category']) || (int) $row['category'] !== self::TEMPLATE_CATEGORY_POST) continue;
				if (!isset($row['similar_post_ids'])) continue;
				$similar_post_ids = json_decode($row['similar_post_ids']);
				if (!is_array($similar_post_ids)) continue;
				foreach ($similar_post_ids as $similar_post_id) {
					$similar_post_id = (int) $similar_post_id;
					if ($similar_post_id > 0 && $post_id === $similar_post_id) {
						$output = ['id' => $row['id']];
						if (array_key_exists("enable_used_css_on_similar_posts", $this->config) && true == $this->config["enable_used_css_on_similar_posts"]) {
							$output["apply_used_css_on_similar_posts"] = true;
						}
						return $output;
					}
				}
			}
			return false;
		}

		public function getTemplateforSimilarUrls($configs) {
			$request_context = $this->mainRequestContext();
			if ($request_context['kind'] !== 'non_singular') {
				return false;
			}
			$current_urls = $this->currentRequestUrls();
			$reversedRows = array_reverse($configs);
			foreach ($reversedRows as $row) {
				if (!isset($row['category']) || (int) $row['category'] !== self::TEMPLATE_CATEGORY_TAXONOMY) continue;
				if (!isset($row['similar_urls'])) continue;
				$similar_urls = json_decode($row['similar_urls']);
				if (!is_array($similar_urls)) continue;
				foreach ($similar_urls as $similar_url) {
					if (in_array($this->normalizeTemplateUrl($similar_url), $current_urls, true)) {
						// Similar archive URLs are family matches, not exact URL matches.
						return ['id' => $row['id']];
					}
				}
			}
			return false;
		}

		public function isAggregatedStyleTagsMd5Matching($buffer, $config) {
			if (array_key_exists('agg_style_tags_md5', $config)) {
				$matched_style_tags = preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $buffer, $style_tags);
				$matched_link_tags = preg_match_all('/<link[^>]*rel\s*=\s*["\']\s*stylesheet\s*["\'][^>]*>/iU', $buffer, $link_tags);
				$agg_style_tags_md5 = $config['agg_style_tags_md5'];
				$agg_css_hash = [];
				if ($matched_style_tags) {
					foreach ($style_tags[0] as $style) {
						$agg_css_hash[] = array($style, strpos($buffer, $style));
					}
				}
				if ($matched_link_tags) {
					foreach ($link_tags[0] as $link) {
						$agg_css_hash[] = array($link, strpos($buffer, $link));
					}
				}
				if(empty($agg_css_hash)) {
					return true;
				}
				usort($agg_css_hash, function($a, $b) { return $a[1] <=> $b[1]; });
				$agg_css_array = [];
				foreach ($agg_css_hash as $css) {
					$agg_css_array[] = $css[0];
				}
				$agg_css_array = implode($agg_css_array);
				$aggStyleMd5Hash = md5($agg_css_array);
				return ($agg_style_tags_md5 == $aggStyleMd5Hash);
			}
			return false;
		}

		public function isAggregatedScriptTagsMd5Matching($buffer, $config) {
			if (array_key_exists('agg_script_tags_md5', $config)) {
				$matched_script_tags = preg_match_all('/<script[^>]*>[\s\S]*?<\/script>/is', $buffer, $scripts);
				if (!$matched_script_tags) {
					return true;
				}
				$agg_script_string = implode($scripts[0]);
				$aggScriptMd5Hash = md5($agg_script_string);
				$agg_script_tags_md5 = $config['agg_script_tags_md5'];
				return $aggScriptMd5Hash == $agg_script_tags_md5;
			}
			return false;
		}

		public function getDefaultConfig($configs) {
			if (isset($this->config['disable_default_template_optimization']) && !$this->config['disable_default_template_optimization']) {
				foreach ($configs as $row) {
					if (isset($row['category_value']) && $row['category_value'] === 'home' && isset($row['id'])) {
						return ['id' => $row['id'], 'is_default_config' => true];
					}
				}
			}
			return false;
		}

		public function findTemplateConfig() {
			$table = $this->db->getBVTable(self::$airlift_config_table);

			// Do not select config here. It is longtext and loading it for every row can exhaust PHP memory.
			$sql = "SELECT id, url, similar_post_ids, similar_urls, category, category_value FROM $table ORDER BY id ASC";
			$rows = $this->db->getResult($sql, ARRAY_A);
			if (!is_array($rows) || empty($rows)) {
				return false;
			}

			$match = $this->getMatchedConfigId($rows);
			if (!$match || !isset($match['id'])) {
				return null;
			}

			$id = intval($match['id']);
			$sql = "SELECT * FROM $table WHERE id = $id LIMIT 1";
			$result = $this->db->getResult($sql, ARRAY_A);
			if (!$result || !isset($result[0])) {
				return null;
			}

			$config = json_decode($result[0]['config'], true);
			if (!is_array($config)) {
				return null;
			}

			if (!empty($match['url_matched'])) {
				$config['url_matched'] = true;
			}
			if (!empty($match['is_default_config'])) {
				$config['is_default_config'] = true;
			}
			if (!empty($match['apply_used_css_on_similar_posts'])) {
				$config['apply_used_css_on_similar_posts'] = true;
			}

			return $config;
		}

		private function getMatchedConfigId($rows) {
			$_config = $this->matchingUrlConfig($rows);
			if (is_array($_config) && isset($_config['id'])) {
				return ['id' => $_config['id'], 'url_matched' => true];
			}

			if (array_key_exists("apply_non_template_optimization", $this->config) && false === $this->config["apply_non_template_optimization"]) {
				return null;
			}

			$_config = $this->getTemplateforSimilarPosts($rows);
			if (is_array($_config) && isset($_config['id'])) {
				$out = ['id' => $_config['id']];
				if (!empty($_config['apply_used_css_on_similar_posts'])) {
					$out['apply_used_css_on_similar_posts'] = true;
				}
				return $out;
			}

			$_config = $this->getTemplateforSimilarUrls($rows);
			if (is_array($_config) && isset($_config['id'])) {
				return ['id' => $_config['id']];
			}

			$_config = $this->getDefaultConfig($rows);
			if (is_array($_config) && isset($_config['id'])) {
				return ['id' => $_config['id'], 'is_default_config' => true];
			}

			return null;
		}

		public function moveMetaTagsToTop($buffer) {
			# Activate offset-based reordering only after this blog receives the new global config flag.
			if (isset($this->config['use_offset_based_meta_tag_reordering'])
					&& true === $this->config['use_offset_based_meta_tag_reordering']) {
				return $this->moveMetaTagsToTopUsingOffsets($buffer);
			}

			return $this->moveMetaTagsToTopLegacy($buffer);
		}

		private function moveMetaTagsToTopUsingOffsets($buffer) {
			$opening_head_tag_pattern = isset($this->config['extract_opening_head_tag_regex']) ? $this->config['extract_opening_head_tag_regex'] : '/<head\b[^>]*>/iU';
			$meta_pattern = isset($this->config['extract_meta_tag_regex']) ? $this->config['extract_meta_tag_regex'] : '/<meta\b[^>]*>/i';

			$opening_head_tag_match = preg_match($opening_head_tag_pattern, $buffer, $head_tag_matches, PREG_OFFSET_CAPTURE);
			if (1 !== $opening_head_tag_match || !isset($head_tag_matches[0][0], $head_tag_matches[0][1])) {
				return $buffer;
			}

			$head_opening_tag = $head_tag_matches[0][0];
			$head_content_start = $head_tag_matches[0][1] + strlen($head_opening_tag);
			$head_closing_tag_start = stripos($buffer, '</head>', $head_content_start);
			if (false === $head_closing_tag_start) {
				return $buffer;
			}

			$head_content_length = $head_closing_tag_start - $head_content_start;
			$head_content = substr($buffer, $head_content_start, $head_content_length);
			if (false === $head_content) {
				return $buffer;
			}

			$meta_match_count = preg_match_all($meta_pattern, $head_content, $meta_matches);
			if (false === $meta_match_count || 0 === $meta_match_count) {
				return $buffer;
			}

			$head_content_without_metas = ALHelper::safePregReplace($meta_pattern, '', $head_content);
			if ($head_content_without_metas === $head_content) {
				return $buffer;
			}

			$meta_tags = implode("\n", $meta_matches[0]);
			$new_head_content = "\n" . $meta_tags . "\n" . $head_content_without_metas;

			return substr_replace($buffer, $new_head_content, $head_content_start, $head_content_length);
		}

		private function moveMetaTagsToTopLegacy($buffer) {
			// Use config-driven regexes so behavior can be tuned from server
			$head_block_pattern = isset($this->config['extract_head_block_regex']) ? $this->config['extract_head_block_regex'] : '~<head\b[^>]*>(.*?)<\/head>~is';
			$opening_head_tag_pattern = isset($this->config['extract_opening_head_tag_regex']) ? $this->config['extract_opening_head_tag_regex'] : '/<head\b[^>]*>/iU';
			$meta_pattern = isset($this->config['extract_meta_tag_regex']) ? $this->config['extract_meta_tag_regex'] : '/<meta\b[^>]*>/i';

			if (!preg_match($head_block_pattern, $buffer, $head_matches)) {
				return $buffer; // No head block found
			}

			$full_head_block = $head_matches[0];  // Full <head>...</head>
			$head_opening_tag = '';
			$head_content = $head_matches[1];     // Content inside head tags

			// Extract the opening head tag
			if (preg_match($opening_head_tag_pattern, $full_head_block, $head_tag_matches)) {
				$head_opening_tag = $head_tag_matches[0];
			}

			// Find all meta tags within the head content only
			if (!preg_match_all($meta_pattern, $head_content, $meta_matches)) {
				return $buffer; // No meta tags in head
			}

			// Remove meta tags from head content
			$head_content_without_metas = ALHelper::safePregReplace($meta_pattern, '', $head_content);

			// Create new head content with meta tags at the top
			$meta_tags = implode("\n", $meta_matches[0]);
			$new_head_content = "\n" . $meta_tags . "\n" . $head_content_without_metas;

			// Reconstruct the head block
			$new_head_block = $head_opening_tag . $new_head_content . '</head>';

			// Replace the original head block with the new one using exact string replace
			$buffer = ALHelper::safeStrReplace($full_head_block, $new_head_block, $buffer);

			return $buffer;
		}

		private function isPartialJsDelayAllowed($config) {
			return isset($config['is_partial_js_delay_allowed'])
				&& $config['is_partial_js_delay_allowed'] === true
				&& (!isset($config['is_delayed']) || $config['is_delayed'] !== true);
		}

		public function extractAndMatchJavascripts($buffer, $js_config_hash) {
			if (!isset($this->config['extract_js_regex'])) {
				return false;
			}

			if (array_key_exists("js_config", $js_config_hash) && is_array($js_config_hash['js_config'])) {
				$js_config = $js_config_hash["js_config"];
			} else {
				return $buffer;
			}

			$extract_js_regex = $this->config['extract_js_regex'];

			$result = preg_match_all($extract_js_regex, $buffer, $script_matches);
			if ($result === false) {
				return false;
			}

			$extracted_scripts = $script_matches[0];
			$remaining_scripts = array();
			$all_matched_configs_are_delayed = true; // Start with true, set to false if any non-delayed config found

			foreach ($extracted_scripts as $script) {
				$stripped_script = preg_replace('/\s+/', '', $script);
				if (preg_match('~<script.*?type=[\'"](.*?)[\'"]~is', $script, $type_value)) {
					if (isset($js_config_hash['js_types_to_ignore']) && is_array($js_config_hash['js_types_to_ignore'])) {
						if (!empty($type_value[1]) && in_array($type_value[1], $js_config_hash['js_types_to_ignore'])) {
							continue;
						}
					}
				}

				if (isset($js_config_hash['js_attributes_to_ignore']) && is_array($js_config_hash['js_attributes_to_ignore'])) {
					foreach ($js_config_hash['js_attributes_to_ignore'] as $excluded_attribute) {
						if (strpos($script, $excluded_attribute) !== false) {
							continue 2;
						}
					}
				}

				$config = $this->findMatchingAssetConfig($script, $js_config, 'js');
				$match_found = is_array($config);
				if ($match_found && (!isset($config['is_delayed']) || $config['is_delayed'] !== true) && !$this->isPartialJsDelayAllowed($config)) {
					$all_matched_configs_are_delayed = false;
				}

				if (!$match_found) {
					$remaining_scripts[] = $script;
				}
			}

			return array(
				'remaining_scripts' => $remaining_scripts,
				'all_matched_configs_are_delayed' => $all_matched_configs_are_delayed
			);
		}

		public function extractHeadTag($buffer) {
			$match_present = ALHelper::safePregMatch('/<head\b[^>]*>/iU', $buffer, $head_tags);
			if ($match_present) {
				return $head_tags[0];
			}
			return "<head>";
		}

		public function removeCommentsPreserveScripts($buffer) {
			# Activate the new behavior only after this blog receives the new global config key.
			if (isset($this->config['comment_removal_preserve_scripts_regex'])) {
				return ALHelper::safePregReplace(
					$this->config['comment_removal_preserve_scripts_regex'],
					'',
					$buffer
				);
			}

			$script_pattern = '/<script[^>]*>([\s\S]*?)<\/script>/i';
			preg_match_all($script_pattern, $buffer, $script_matches);

			$temp_buffer = $buffer;
			$placeholder_map = [];

			foreach ($script_matches[0] as $script) {
				$placeholder = "SCRIPT_PLACEHOLDER_" . wp_rand(1000000, 9999999) . "_";
				$placeholder_map[$placeholder] = $script;
				$temp_buffer = ALHelper::safeStrReplace($script, $placeholder, $temp_buffer);
			}

			$comment_regex = isset($this->config['comment_removal_regex'])
				? $this->config['comment_removal_regex']
				: '/<!--(.*)-->/Uis';
			$temp_buffer = ALHelper::safePregReplace($comment_regex, '', $temp_buffer);

			foreach ($placeholder_map as $placeholder => $script) {
				$temp_buffer = ALHelper::safeStrReplace($placeholder, $script, $temp_buffer);
			}

			return $temp_buffer;
		}

		/**
		 * Preserve script tags by replacing them with placeholders
		 * Returns the modified buffer and a placeholder map for restoration
		 */
		public function preserveScriptTags($buffer) {
			$script_pattern = '/<script[^>]*>[\s\S]*?<\/script>/i';
			preg_match_all($script_pattern, $buffer, $script_matches);

			$placeholder_map = [];
			$temp_buffer = $buffer;

			foreach ($script_matches[0] as $index => $script) {
				$placeholder = "BV_SCRIPT_PRESERVE_" . wp_rand(1000000, 9999999) . "_" . $index . "_";
				$placeholder_map[$placeholder] = $script;
				$temp_buffer = ALHelper::safeStrReplace($script, $placeholder, $temp_buffer);
			}

			return array(
				'buffer' => $temp_buffer,
				'placeholder_map' => $placeholder_map
			);
		}

		/**
		 * Restore script tags from placeholders
		 */
		public function restoreScriptTags($buffer, $placeholder_map) {
			foreach ($placeholder_map as $placeholder => $script) {
				$buffer = ALHelper::safeStrReplace($placeholder, $script, $buffer);
			}
			return $buffer;
		}

		public function cleanBuffer($regexes, $buffer) {
			$cleaned_buffer = $buffer;
			if (isset($regexes) && is_array($regexes)) {
				foreach ($regexes as $regex) {
					$cleaned_buffer = ALHelper::safePregReplace($regex, '', $cleaned_buffer);
				}
			}
			return $cleaned_buffer;
		}

		public function extractAndReplaceStyles($buffer, $css_config, $modify_buffer = true) {
			if (!isset($this->config['extract_css_regex'])) {
				return array(
					'remaining_styles' => array(),
					'modified_buffer' => $buffer
				);
			}

			// Preserve script tags to avoid matching link/style tags inside JavaScript strings
			$preserved = $this->preserveScriptTags($buffer);
			$temp_buffer = $preserved['buffer'];
			$placeholder_map = $preserved['placeholder_map'];

			$extract_css_regex = $this->config['extract_css_regex'];
			preg_match_all($extract_css_regex, $temp_buffer, $style_matches);
			$extracted_styles = $style_matches[0];
			$remaining_styles = array();
			$modified_buffer = $temp_buffer;

			foreach ($extracted_styles as $style) {
				$stripped_style = preg_replace('/\s+/', '', $style);
				$match_found = false;
				$replacement = '';

				$config = $this->findMatchingAssetConfig($style, $css_config, 'css');
				if (is_array($config)) {
					$match_found = true;
					$replacement = isset($config['replace_with']) ? $config['replace_with'] : '';
				}

				if ($match_found) {
					if ($modify_buffer) {
						$modified_buffer = ALHelper::safeStrReplace($style, $replacement, $modified_buffer);
					}
				} else {
					$remaining_styles[] = $style;
				}
			}

			// Restore script tags before returning
			$modified_buffer = $this->restoreScriptTags($modified_buffer, $placeholder_map);

			return array(
				'remaining_styles' => $remaining_styles,
				'modified_buffer' => $modified_buffer
			);
		}

		public function optimizeBuffer($buffer) {
			$startTime = microtime(true);
			$this->asset_content_digest_cache = array();
			$this->asset_normalization_time_ms = 0.0;
			$template_config = $this->findTemplateConfig();
			if (is_array($template_config) && !empty($template_config)) {
				if (!isset($template_config["version"]) || $template_config["version"] !== ALInfo::AL_CONF_VERSION) {
					return $buffer;
				}

				$buffer = $this->removeCommentsPreserveScripts($buffer);
				$buffer = ALHelper::safePregReplace( '#<noscript>.*?</noscript>#ism', '', $buffer );
				$html_data_to_replace = $this->getHtmlDataToReplace($template_config);
				$url_matched = null;
				$page_audits = array();
				$is_default_config = false;

				if (array_key_exists('is_default_config', $template_config) && true == $template_config['is_default_config']) {
					$is_default_config = true;
				}
				$canonical_font_config = isset($template_config['canonical_font_optimization'])
					&& is_array($template_config['canonical_font_optimization']) ?
					$template_config['canonical_font_optimization'] : array();
				$apply_used_css_on_similar_posts = null;
				if (array_key_exists('url_matched', $template_config) && $template_config['url_matched']) {
					$url_matched = $template_config['url_matched'];
				}
				if (array_key_exists('apply_used_css_on_similar_posts', $template_config) && $template_config['apply_used_css_on_similar_posts']) {
					$apply_used_css_on_similar_posts = $template_config['apply_used_css_on_similar_posts'];
				}
				if (array_key_exists("web_worker_scripts", $template_config)) {
					$buffer = $this->addJavascripts($buffer, $template_config["web_worker_scripts"]);
				}
				if (array_key_exists("javascript", $template_config) && is_array($template_config['javascript'])) {
					$this->processJavascriptOptimization($buffer, $template_config, $is_default_config, $page_audits);
				}
				if (!$is_default_config && isset($canonical_font_config['injection_rules'])
					&& is_array($canonical_font_config['injection_rules'])) {
					$buffer = $this->addJavascripts($buffer, $canonical_font_config['injection_rules']);
				}
				if (array_key_exists("javascripts_to_add_before_delay", $template_config)) {
					$injection_rules = $template_config["javascripts_to_add_before_delay"];
					if ($is_default_config) {
						$injection_rules = $this->withoutCanonicalFontInjectionRules($injection_rules);
					}
					$buffer = $this->addJavascripts($buffer, $injection_rules);
				}
				$apply_used_css = $this->shouldApplyUsedCss($template_config, $url_matched, $apply_used_css_on_similar_posts);
				$apply_critical_css = !$apply_used_css && $this->shouldApplyCriticalCss($template_config);
				$inline_style_config = $this->getInlineStyleTemplateConfig($template_config, $is_default_config);
				$inline_style_handler = new ALInlineStyleOptimizationHandler($this->config, $inline_style_config);
				if ($apply_used_css) {
					$this->processUsedCssOptimization($buffer, $template_config, $page_audits, $inline_style_handler);
				} elseif ($apply_critical_css) {
					$this->processCriticalCssOptimization($buffer, $template_config, $page_audits, $inline_style_handler);
					if (array_key_exists("critical_css_replace_rules", $template_config) && is_array($template_config['critical_css_replace_rules'])) {
						$critical_css_replace_rules = $template_config['critical_css_replace_rules'];
						if ($is_default_config) {
							$critical_css_replace_rules = $this->withoutCanonicalFontInjectionRules($critical_css_replace_rules);
						}
						$buffer = $this->doSearchAndReplace($buffer, $critical_css_replace_rules);
					}
				} elseif ($this->isDefaultConfig($template_config)) {
					$this->processDefaultCssOptimization($buffer, $template_config, $inline_style_handler);
				}
				if (array_key_exists("dyn_images", $html_data_to_replace) && is_array($html_data_to_replace["dyn_images"])) {
					$buffer = $this->doSearchAndReplace($buffer, $html_data_to_replace['dyn_images']);
				}
				if (array_key_exists("preloaded_assets", $html_data_to_replace) && is_array($html_data_to_replace["preloaded_assets"])) {
					$buffer = $this->doSearchAndReplace($buffer, $html_data_to_replace["preloaded_assets"]);
				}
				if (array_key_exists("font", $template_config)) {
					$buffer = $this->handleFonts($buffer, $template_config["font"]);
				}
				if (array_key_exists("image", $template_config)) {
					$this->image_config = $template_config["image"];
					$buffer = $this->handleImages($buffer, $template_config["image"], $url_matched, !$is_default_config);
				}
				if (array_key_exists("iframe", $template_config)) {
					$buffer = $this->handleIframes($buffer, $template_config["iframe"]);
				}
				if (array_key_exists("video", $template_config) && is_array($template_config["video"])) {
					$buffer = $this->doSearchAndReplace($buffer, $template_config["video"]);
				}
				if (array_key_exists("preconnect", $html_data_to_replace) && is_array($html_data_to_replace["preconnect"])) {
					$buffer = $this->doSearchAndReplace($buffer, $html_data_to_replace["preconnect"]);
				}
				if (array_key_exists("meta_tags", $html_data_to_replace) && is_array($html_data_to_replace["meta_tags"])) {
					$buffer = $this->doSearchAndReplace($buffer, $html_data_to_replace["meta_tags"]);
				} else if (array_key_exists("meta", $html_data_to_replace) && is_array($html_data_to_replace["meta"])) {
					$buffer = $this->doSearchAndReplace($buffer, $html_data_to_replace['meta']);
				}
				if (array_key_exists("search_and_replace_in_html", $this->config) && is_array($this->config['search_and_replace_in_html'])) {
					$buffer = $this->doSearchAndReplace($buffer, $this->config['search_and_replace_in_html']);
				}
				if (array_key_exists("id", $template_config)) {
					if ($this->alinfo->hasValidDBVersion()) {
						$this->addOptimizationInfo($template_config["id"], $page_audits);
					}
				}
				$buffer = $this->moveMetaTagsToTop($buffer);
				if (array_key_exists("footer_message", $template_config)) {
					$buffer .= $this->optimizationFooterComment($template_config["footer_message"], $startTime);
				}
			} else {
				if (array_key_exists("footer_message", $this->config)) {
					$buffer .= $this->optimizationFooterComment($this->config["footer_message"], $startTime);
				}
			}
			return $buffer;
		}

		private function optimizationFooterComment($footer_message, $start_time) {
			$current_time = gmdate("Y-m-d H:i:s");
			$optimization_time_ms = round((microtime(true) - $start_time) * 1000, 2);
			$asset_normalization_time_ms = round($this->asset_normalization_time_ms, 2);

			return '<!-- ' . $footer_message
				. ', Cached Timestamp: ' . $current_time . ' UTC'
				. ', Optimization Time: ' . $optimization_time_ms . 'ms'
				. ', Asset Normalization Time: ' . $asset_normalization_time_ms . 'ms -->';
		}

		private function getLinkAttributes($linkTag) {
			if (!is_string($linkTag) || trim($linkTag) === '') {
				return [];
			}

			if (!isset($this->config['extract_css_attr_regex'])) {
				return [];
			}
			$attr_regex = $this->config['extract_css_attr_regex']; // Reuse existing regex
			$attributes = [];
			$ignoredAttributes = isset($this->config['strict_link_attrs']) ? $this->config['strict_link_attrs'] : array();

			if (preg_match('/<link\b(?<attrs>[^>]*?)(?:\s*\/)?>/i', $linkTag, $matches)) {
				$attrString = $matches['attrs'];
				if (preg_match_all($attr_regex, $attrString, $matches, PREG_SET_ORDER) && is_array($matches) && !empty($matches)) {
					foreach ($matches as $match) {
						$name = $match['name'];
						if (in_array($name, $ignoredAttributes)) {
							continue;
						}
						$value = !empty($match['value1']) ? $match['value1'] :
							(!empty($match['value2']) ? $match['value2'] :
							(!empty($match['value3']) ? $match['value3'] :
							(!empty($match['value4']) ? $match['value4'] : '')));
						$attributes[$name] = $value;
					}
				}
			}
			return $attributes;
		}

		public function modifyExternalStylesheets($buffer, $css_config) {
			if (!isset($this->config['extract_css_regex'])) {
				return $buffer;
			}

			// Preserve script tags to avoid matching link tags inside JavaScript strings
			$preserved = $this->preserveScriptTags($buffer);
			$temp_buffer = $preserved['buffer'];
			$placeholder_map = $preserved['placeholder_map'];

			$styleAttrs = array();
			$matched = preg_match_all($this->config['extract_css_regex'], $temp_buffer, $matches);

			if ($matched) {
				foreach ($matches[0] as $link_tag) {
					$attributes = $this->getLinkAttributes($link_tag);
					if (isset($attributes['id']) && strpos($attributes['id'], 'bv-') === 0) {
						continue;
					}
					$original_href = isset($attributes['href']) ? $attributes['href'] : '';
					$new_href = $original_href;
					$should_remove = false;

					$stripped_link_tag = preg_replace('/\s+/', '', $link_tag);

					$config = $this->findMatchingAssetConfig($link_tag, $css_config, 'css');
					if (is_array($config)) {
						$replace_with = isset($config['replace_with']) ? $config['replace_with'] : '';

						// Check if this link should be removed (replace_with is empty string)
						if ($replace_with === '') {
							$should_remove = true;
						} else {
							$replace_attributes = $this->getLinkAttributes($replace_with);
							$new_href = isset($replace_attributes['href']) ? $replace_attributes['href'] : $original_href;
						}
					}

					// If should_remove is true, remove the link tag entirely
					if ($should_remove) {
						$temp_buffer = str_replace($link_tag, '', $temp_buffer);
						continue;
					}

					$template_id = ALCacheHelper::randString(20);
					$template_tag = '<template id="' . $template_id . '"></template>';

					$attributes['href'] = $new_href;
					$styleAttrs[] = array(
						"attrs" => $attributes,
						"bv_unique_id" => $template_id
					);

					$temp_buffer = str_replace($link_tag, $template_tag, $temp_buffer);
				}
			}

			// Restore script tags before further processing
			$buffer = $this->restoreScriptTags($temp_buffer, $placeholder_map);

			if (!empty($styleAttrs)) {
				$head_tag = $this->extractHeadTag($buffer);
				$styleAttrsJson = json_encode($styleAttrs);
				$styleAttrsScript = "<script id=\"bv-dl-styles-list\" data-cfasync=\"false\" bv-exclude=\"true\">\nvar linkStyleAttrs = $styleAttrsJson;\n</script>";
				$buffer = ALHelper::safeStrReplaceFirst($head_tag, $head_tag . "\n" . $styleAttrsScript, $buffer);
			}
			return $buffer;
		}

		public function modifyInlineStyles($buffer, $inline_style_handler = null) {
			if (!isset($this->config['extract_style_regex'])) {
				return $buffer;
			}

			// Preserve script tags to avoid matching style tags inside JavaScript strings
			$preserved = $this->preserveScriptTags($buffer);
			$temp_buffer = $preserved['buffer'];
			$placeholder_map = $preserved['placeholder_map'];

			$res = preg_match_all($this->config['extract_style_regex'], $temp_buffer, $style_matches);
			if (!$res) {
				return $buffer;
			}

			foreach ($style_matches[0] as $original_style) {
				$style = $original_style;
				if ($inline_style_handler instanceof ALInlineStyleOptimizationHandler) {
					$style = $inline_style_handler->optimizeStyleTag($style);
				}
				preg_match('~(<style\b[^>]*>)(.*?)<\/style>~is', $style, $style_attrs);
				$opening_tag = $style_attrs[1];
				$content = $style_attrs[2];
				$opening_tag = preg_replace('/\stype=(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $opening_tag); // we should send type capturing regex from rails side
				$opening_tag = str_replace('>', ' type="bv_inline_delayed_css">', $opening_tag);
				$new_style = $opening_tag . $content . '</style>';
				$temp_buffer = str_replace($original_style, $new_style, $temp_buffer);
			}

			// Restore script tags before returning
			return $this->restoreScriptTags($temp_buffer, $placeholder_map);
		}

		public function handleIframes($buffer, $iframe_config) {
			if (!array_key_exists("has_iframe", $iframe_config) || $iframe_config["has_iframe"] != TRUE) {
				return $buffer;
			}

			$iframes_matched = preg_match_all('#<iframe(?<attrs>\s.+)>.*</iframe>#iUs', $buffer, $iframes, PREG_SET_ORDER);
			if (!$iframes_matched) {
				return $buffer;
			}

			foreach($iframes as $iframe) {
				$src_matched = ALHelper::safePregMatch('#\ssrc\s*=\s*(\'|")(?<src>.*)\1#iUs', $iframe['attrs'], $attrs);
				if (!$src_matched) {
					continue;
				}
				
				if ($this->hasExcludedIframeAttrs($iframe)){
					continue;
				}
				$iframe['src'] = trim($attrs['src']);
				if ($iframe['src'] === '') {
					continue;
				}

				if (esc_url($iframe['src']) === '') {
					continue;
				}
				$lazyloaded_iframe = $iframe[0];
				$lazyloaded_iframe = $this->lazyloadIframe($iframe, $lazyloaded_iframe);
				if (preg_match('#<iframe[^>]*>#is', $lazyloaded_iframe, $opening_tag_match)) {
					$original_opening_tag = $opening_tag_match[0];
					$modified_opening_tag = $this->addBvClass($original_opening_tag, 'bv-lazyload-iframe');
					$lazyloaded_iframe = ALHelper::safeStrReplace($original_opening_tag, $modified_opening_tag, $lazyloaded_iframe);
				} else {
					// Fallback to old method if regex fails (should not happen)
					$lazyloaded_iframe = $this->addBvClass($lazyloaded_iframe, 'bv-lazyload-iframe');
				}
				$lazyloaded_iframe .= '<noscript>' . $iframe[0] . '</noscript>';

				$buffer = ALHelper::safeStrReplace($iframe[0], $lazyloaded_iframe, $buffer);
			}

			return $buffer;
		}

		public function lazyloadIframe($iframe, $lazyloaded_iframe) {
			$parsed_url = parse_url($iframe['src'], PHP_URL_HOST);
			if ($parsed_url !== false && isset($this->config['add_iframe_host']) && $this->config['add_iframe_host'] == TRUE) {
				$hostname = '';
				if (!empty($parsed_url)) {
					$hostname = $parsed_url;
				}
				$final_attrs = ALHelper::safeStrReplace($iframe['src'], 'about:blank;' . $hostname, $iframe['attrs']);
			} else {
				$final_attrs = ALHelper::safeStrReplace($iframe['src'], 'about:blank', $iframe['attrs']);
			}
			$lazyloaded_iframe = ALHelper::safeStrReplace($iframe['attrs'], $final_attrs . ' bv-data-src="' . esc_url($iframe['src'])  . '" ', $lazyloaded_iframe);
			return $lazyloaded_iframe;
		}

		public function doSearchAndReplace($buffer, $search_and_replace_info) {
			foreach ($search_and_replace_info as $config) {
				if (array_key_exists("search_for", $config) && array_key_exists("replace_with", $config)) {
					if (array_key_exists("replace_head_tag", $config) && $config["replace_head_tag"] === true) {
						$head_tag = $this->extractHeadTag($buffer);
						$replace_with = $head_tag . "\n" . $config["replace_with"];
						$buffer = ALHelper::safeStrReplaceFirst($head_tag, $replace_with, $buffer);
					} else if ($config["search_for"] === '</body>') {
						$buffer = $this->replaceLastBodyTag($buffer, $config['replace_with']);
					} else {
						$buffer = ALHelper::safeStrReplace($config["search_for"], $config["replace_with"], $buffer);
					}
				}
			}
			return $buffer;
		}

		public function addCriticalCssV2($buffer, $critical_css_v2) {
			if (ALHelper::safePregMatch('/<head\b[^>]*>/iU', $buffer, $matches)) {
				$head_tag = $matches[0];
				$replacement = $head_tag . "\n" . $critical_css_v2 . "\n";

				$buffer = ALHelper::safeStrReplaceFirst($head_tag, $replacement, $buffer);
			}
			return $buffer;
		}

		public function addCriticalCss($buffer, $critical_css) {
			if (ALHelper::safePregMatch('/<head\b[^>]*>/iU', $buffer, $matches)) {
				$head_tag = $matches[0];
				$replacement = $head_tag . "\n<style id='bv-critical-css'>" . $critical_css . "</style>\n";

				$buffer = ALHelper::safeStrReplaceFirst($head_tag, $replacement, $buffer);
			}
			return $buffer;
		}

		public function addUsedCss($buffer, $used_css) {
			if (ALHelper::safePregMatch('/<head\b[^>]*>/iU', $buffer, $matches)) {
				$head_tag = $matches[0];
				$replacement = $head_tag . "\n" . $used_css . "\n";

				$buffer = ALHelper::safeStrReplaceFirst($head_tag, $replacement, $buffer);
			}
			return $buffer;
		}

		public function preloadImages($buffer, $preload_images) {
			$head_matched = ALHelper::safePregMatch('/<head\b[^>]*>/iU', $buffer, $matches);
			if ($head_matched) {
				$head_tag = $matches[0];
				foreach ($preload_images as $image_to_preload) {
					$replacement = $head_tag . "\n" . $image_to_preload;
					$buffer = ALHelper::safeStrReplaceFirst($head_tag, $replacement, $buffer);
				}
			}
			return $buffer;
		}

		private function hasEmbeddedImage($image) {
			return strpos($image, 'data:image') !== false;
		}

		public function hasNoActionAttribute($element) {
			if (!array_key_exists('no_action_attributes', $this->config) && !is_array($this->config['no_action_attributes'])) {
				return false;
			}

			foreach ($this->config['no_action_attributes'] as $excluded_attr) {
				if (strpos($element, $excluded_attr) !== false) {
					return true;
				}
			}

			return false;
		}

		private function hasIgnoredAttribute($image) {
			return strpos($image, 'data:image') !== false;
		}

		public function handleUsedCss($buffer, $used_css, $used_css_replace_rules) {
			if (isset($used_css) && !empty($used_css)) {
				$buffer = $this->addUsedCss($buffer, $used_css);
				$buffer = $this->doSearchAndReplace($buffer, $used_css_replace_rules);
			}
			return $buffer;
		}

		public function handleImagesInInlineStyle($buffer, $image_replace_rules) {
			if (!isset($image_replace_rules) || empty($image_replace_rules) || !isset($this->config['extract_style_regex'])) {
				return $buffer;
			}

			$res = preg_match_all($this->config['extract_style_regex'], $buffer, $style_matches);
			if (!$res) {
				return $buffer;
			}

			foreach ($style_matches[0] as $original_style) {
				$modified_style = $original_style;

				foreach ($image_replace_rules as $config) {
					if (array_key_exists("search_for", $config) && array_key_exists("replace_with", $config)) {
						$modified_style = ALHelper::safeStrReplace($config["search_for"], $config["replace_with"], $modified_style);
					}
				}

				if ($modified_style !== $original_style) {
					$buffer = ALHelper::safeStrReplace($original_style, $modified_style, $buffer);
				}
			}

			return $buffer;
		}

		/**
		 * Determines if Used CSS should be applied based on configuration and matching conditions
		 */
		private function shouldApplyUsedCss($template_config, $url_matched, $apply_used_css_on_similar_posts) {
			return array_key_exists('used_css', $template_config) && ($url_matched || $apply_used_css_on_similar_posts);
		}

		private function isDefaultConfig($template_config) {
			return array_key_exists('is_default_config', $template_config) && true === $template_config['is_default_config'];
		}

		private function getInlineStyleTemplateConfig($template_config, $is_default_config) {
			$has_canonical_font_config = array_key_exists('canonical_font_optimization', $template_config)
				&& is_array($template_config['canonical_font_optimization']);
			if ($is_default_config || $has_canonical_font_config) {
				unset($template_config['font_replace_rules']);
			}
			if (!$is_default_config && $has_canonical_font_config) {
				$font_config = $template_config['canonical_font_optimization'];
				if (isset($font_config['font_replace_rules']) && is_array($font_config['font_replace_rules'])) {
					$template_config['font_replace_rules'] = $font_config['font_replace_rules'];
				}
			}
			return $template_config;
		}

		private function withoutCanonicalFontInjectionRules($rules) {
			$filtered_rules = array();
			foreach (is_array($rules) ? $rules : array() as $rule) {
				$replacement = is_array($rule) && isset($rule['replace_with']) ? $rule['replace_with'] : '';
				if (is_string($replacement) && (strpos($replacement, 'bv-font-style-lazyload') !== false
					|| strpos($replacement, 'bv-balanced-font-css') !== false)) {
					continue;
				}
				$filtered_rules[] = $rule;
			}
			return $filtered_rules;
		}

		/**
		 * Determines if Critical CSS optimization should be applied
		 */
		private function shouldApplyCriticalCss($template_config) {
			if ($this->isDefaultConfig($template_config)) {
				if (empty($template_config['can_apply_critical_css_on_other_pages']) || false === $template_config['can_apply_critical_css_on_other_pages']) {
					return false;
				}
			} elseif (!array_key_exists('url_matched', $template_config) || false === $template_config['url_matched']) {
				if (empty($template_config['apply_critical_css_on_similar_posts']) || false === $template_config['apply_critical_css_on_similar_posts']) {
					return false;
				}
			}
			return (array_key_exists('critical_css', $template_config) || array_key_exists('critical_css_v2', $template_config))
				&& array_key_exists("stylesheet", $template_config);
		}

		/**
		 * Determines if stylesheets should be delayed based on configuration
		 */
		private function shouldDelayStylesheets($template_config) {
			return array_key_exists('delay_stylesheets', $template_config) && $template_config['delay_stylesheets'];
		}

		/**
		 * Determines if delay should only occur on exact match
		 */
		private function shouldDelayOnlyOnExactMatch($template_config, $remaining_styles) {
			return array_key_exists('delay_only_on_exact_match', $template_config)
				&& $template_config['delay_only_on_exact_match']
				&& !empty($remaining_styles);
		}

		/**
		 * Determines which Critical CSS version to use (v2 takes precedence)
		 */
		private function getCriticalCssContent($template_config) {
			if (!empty($template_config["critical_css_v2"])) {
				return ['version' => 'v2', 'content' => $template_config["critical_css_v2"]];
			}
			return ['version' => 'v1', 'content' => $template_config["critical_css"]];
		}

		/**
		 * Determines if image replacement rules should be applied
		 */
		private function shouldApplyImageReplacementRules($template_config) {
			return array_key_exists('image_replace_rules', $template_config);
		}

		/**
		 * Process Used CSS optimization path
		 */
		private function processUsedCssOptimization(&$buffer, $template_config, &$page_audits, $inline_style_handler) {
			$result = $this->extractAndReplaceStyles($buffer, $template_config['used_css_replace_rules']);
			$buffer = $result['modified_buffer'];

			$unmatched_external_styles = $this->externalAssetTags($result['remaining_styles'], 'css');
			if (!empty($unmatched_external_styles)) {
				$page_audits['non_matching_stylesheets'] = $unmatched_external_styles;
			}

			$buffer = $inline_style_handler->optimizeUnmatchedStyleTags($buffer, $template_config['used_css_replace_rules']);
			$buffer = $this->handleUsedCss($buffer, $template_config['used_css'], $template_config['used_css_replace_rules']);

			if ($this->shouldApplyImageReplacementRules($template_config)) {
				$buffer = $this->handleImagesInInlineStyle($buffer, $template_config['image_replace_rules']);
			}
		}

		/**
		 * Process Critical CSS optimization path
		 */
		private function processCriticalCssOptimization(&$buffer, $template_config, &$page_audits, $inline_style_handler) {
			$result = $this->extractAndReplaceStyles($buffer, $template_config['stylesheet'], false);

			$unmatched_external_styles = $this->externalAssetTags($result['remaining_styles'], 'css');
			if (!empty($unmatched_external_styles)) {
				$page_audits['non_matching_stylesheets'] = $unmatched_external_styles;
			}

			if ($this->shouldDelayStylesheets($template_config)) {
				$this->processDelayedStylesheetsPath($buffer, $template_config, $result['remaining_styles'], $inline_style_handler);
			} else {
				$this->processNonDelayedStylesheetsPath($buffer, $template_config, $inline_style_handler);
			}
		}

		/**
		 * Process the delayed stylesheets optimization path
		 */
		private function processDelayedStylesheetsPath(&$buffer, $template_config, $remaining_styles, $inline_style_handler) {
			$should_modify_styles = true;

			if ($this->shouldDelayOnlyOnExactMatch($template_config, $remaining_styles)) {
				$should_modify_styles = false;
			}

			if ($should_modify_styles) {
				$buffer = $this->modifyInlineStyles($buffer, $inline_style_handler);
				$buffer = $this->modifyExternalStylesheets($buffer, $template_config['stylesheet']);
			} else {
				$buffer = $inline_style_handler->optimizeUnmatchedStyleTags($buffer, $template_config['stylesheet']);
			}

			if ($this->shouldApplyImageReplacementRules($template_config)) {
				$buffer = $this->handleImagesInInlineStyle($buffer, $template_config['image_replace_rules']);
			}

			$this->applyCriticalCss($buffer, $template_config);
		}

		/**
		 * Process the non-delayed stylesheets optimization path
		 */
		private function processNonDelayedStylesheetsPath(&$buffer, $template_config, $inline_style_handler) {
			$buffer = $inline_style_handler->optimizeUnmatchedStyleTags($buffer, $template_config['stylesheet']);
			$this->applyCriticalCss($buffer, $template_config);
			$buffer = $this->handleStylesheet($buffer, $template_config["stylesheet"]);

			if ($this->shouldApplyImageReplacementRules($template_config)) {
				$buffer = $this->handleImagesInInlineStyle($buffer, $template_config['image_replace_rules']);
			}
		}

		private function processDefaultCssOptimization(&$buffer, $template_config, $inline_style_handler) {
			$should_replace_stylesheets = array_key_exists("stylesheet", $template_config)
				&& array_key_exists("can_defer_other_pages_stylesheets", $template_config)
				&& true === $template_config['can_defer_other_pages_stylesheets'];
			$stylesheet_rules = $should_replace_stylesheets ? $template_config['stylesheet'] : array();
			$buffer = $inline_style_handler->optimizeUnmatchedStyleTags($buffer, $stylesheet_rules);

			if (!$should_replace_stylesheets) {
				return;
			}

			$buffer = $this->handleStylesheet($buffer, $template_config["stylesheet"]);
			if ($this->shouldApplyImageReplacementRules($template_config)) {
				$buffer = $this->handleImagesInInlineStyle($buffer, $template_config['image_replace_rules']);
			}
		}

		/**
		 * Apply the appropriate Critical CSS version
		 */
		private function applyCriticalCss(&$buffer, $template_config) {
			$critical_css = $this->getCriticalCssContent($template_config);

			if ($critical_css['version'] === 'v2') {
				$buffer = $this->addCriticalCssV2($buffer, $critical_css['content']);
			} else {
				$buffer = $this->addCriticalCss($buffer, $critical_css['content']);
			}
		}

		private function processJavascriptOptimization(&$buffer, $template_config, $is_default_config, &$page_audits) {
			$result = $this->extractAndMatchJavascripts($buffer, $template_config['javascript']);
			if ($result === false) {
				return;
			}

			$remaining_scripts = $result['remaining_scripts'];

			if (is_array($remaining_scripts)) {
				$should_process_with_remaining = $this->shouldProcessWithRemainingScripts($is_default_config, $remaining_scripts, $result['all_matched_configs_are_delayed']);
				
				if (empty($remaining_scripts) || $should_process_with_remaining) {
					if (array_key_exists("javascripts_to_add_after_delay", $template_config)) {
						$buffer = $this->addJavascripts($buffer, $template_config["javascripts_to_add_after_delay"]);
					}
					
					// Handle matched scripts normally and delay remaining scripts if needed
					$buffer = $this->handleJavascript($buffer, $template_config["javascript"], $remaining_scripts, $should_process_with_remaining);
				}
				
				$unmatched_external_scripts = $this->externalAssetTags($remaining_scripts, 'js');
				if (!empty($unmatched_external_scripts)) {
					$page_audits['non_matching_scripts'] = $unmatched_external_scripts;
				}
			}
		}

		private function externalAssetTags($tags, $type) {
			$external_tags = array();
			foreach (is_array($tags) ? $tags : array() as $tag) {
				if ($this->extractExternalAssetUrl($tag, $type) !== '') {
					$external_tags[] = $tag;
				}
			}
			return array_values(array_unique($external_tags));
		}

		private function shouldProcessWithRemainingScripts($is_default_config, $remaining_scripts, $all_matched_configs_are_delayed) {
			$should_process_with_remaining = false;
			
			// Check if we should process even with remaining scripts
			if ($is_default_config) {
				// For default configs, only check the default config setting
				if (array_key_exists("delay_all_js_on_non_exact_match_for_default_config", $this->config) && $this->config["delay_all_js_on_non_exact_match_for_default_config"] === true) {
					$should_process_with_remaining = true;
				}
			} else {
				// For template configs, check the template config setting
				if (array_key_exists("delay_all_js_on_non_exact_match_for_template_config", $this->config) && $this->config["delay_all_js_on_non_exact_match_for_template_config"] === true) {
					$should_process_with_remaining = true;
				}
			}
			
			// Additional safety check: only process if ALL matching configs have is_delayed = true
			if ($should_process_with_remaining && !empty($remaining_scripts)) {
				if (!$all_matched_configs_are_delayed) {
					$should_process_with_remaining = false;
				}
			}
			
			// Check for patterns that should skip optimization entirely in non-exact match scenarios
			if ($should_process_with_remaining && !empty($remaining_scripts)) {
				if ($this->hasSkipOptimizationPatterns($remaining_scripts)) {
					$should_process_with_remaining = false;
				}
			}
			
			return $should_process_with_remaining;
		}

		private function hasSkipOptimizationPatterns($remaining_scripts) {
			if (!isset($this->config['skip_optimization_patterns_on_non_exact_match']) || !is_array($this->config['skip_optimization_patterns_on_non_exact_match'])) {
				return false;
			}
			
			foreach ($remaining_scripts as $remaining_script) {
				foreach ($this->config['skip_optimization_patterns_on_non_exact_match'] as $pattern) {
					if (strpos($remaining_script, $pattern) !== false) {
						return true;
					}
				}
			}
			
			return false;
		}

		public function handleJavascript($buffer, $js_config_hash, $remaining_scripts = [], $should_process_with_remaining = false) {
			if (!is_array($js_config_hash) || empty($js_config_hash)) {
				return $buffer;
			}

			if (array_key_exists("js_config", $js_config_hash) && is_array($js_config_hash['js_config'])) {
				$js_config = $js_config_hash["js_config"];
			} else {
				return $buffer;
			}

			$scriptAttrs = array();
			$deferredScriptAttrs = array(); // NEW: Separate array for scripts with existing defer
			$script_index = 0;

			usort($js_config, function($a, $b) {
				return ($a['aggregation_priority'] ?? PHP_INT_MAX) - ($b['aggregation_priority'] ?? PHP_INT_MAX);
			});

			$extract_js_regex = $this->config['extract_js_regex'];
			preg_match_all($extract_js_regex, $buffer, $script_matches);
			$extracted_scripts = $script_matches[0];

			if ($should_process_with_remaining && !empty($remaining_scripts)) {
				// Non-exact match processing - delay ALL scripts with smart config matching
				$result = $this->processNonExactMatchScripts($extracted_scripts, $js_config, $js_config_hash, $buffer, $script_index);
				$scriptAttrs = $result[0];
				$deferredScriptAttrs = $result[1];
			} else {
				// Exact match processing - original logic
				$result = $this->processExactMatchScripts($extracted_scripts, $js_config, $buffer, $script_index);
				$scriptAttrs = $result[0];
				$deferredScriptAttrs = $result[1];
			}

			if (!empty($deferredScriptAttrs) && is_array($deferredScriptAttrs) &&
					isset($deferredScriptAttrs[0]) && is_array($deferredScriptAttrs[0]) &&
					isset($deferredScriptAttrs[0]['attrs']) && is_array($deferredScriptAttrs[0]['attrs'])) {
				$deferredScriptAttrs[0]['attrs']['is_first_defer_element'] = true;
			}
			// Combine normal scripts first, then deferred scripts to maintain execution order
			$scriptAttrs = array_merge($scriptAttrs, $deferredScriptAttrs);

			// Add any additional script infos to delay
			if (isset($js_config_hash['js_infos_to_delay']) && is_array($js_config_hash['js_infos_to_delay'])) {
				$scriptAttrs = array_merge($scriptAttrs, $js_config_hash['js_infos_to_delay']);
			}

			if (!empty($scriptAttrs)) {
				$buffer = $this->addScriptAttrsToBuffer($buffer, $scriptAttrs);
			}

			return $buffer;
		}

		private function hasExistingDeferAttribute($script) {
			$attributes = $this->getScriptAttributes($script);
			return array_key_exists('defer', $attributes);
		}

		private function processNonExactMatchScripts($extracted_scripts, $js_config, $js_config_hash, &$buffer, &$script_index) {
			$nonAggScriptAttrs = array();
			$deferredScriptAttrs = array(); // NEW: Separate array for deferred scripts

			foreach ($extracted_scripts as $script) {
				// Skip scripts that should be ignored based on type
				if ($this->shouldSkipScriptByType($script, $js_config_hash)) {
					continue;
				}

				// Skip scripts that have ignored attributes
				if ($this->shouldSkipScriptByAttributes($script, $js_config_hash)) {
					continue;
				}

				$matching_config = $this->findMatchingConfig($script, $js_config);
				if ($matching_config && $this->isPartialJsDelayAllowed($matching_config)) {
					$script_index++;
					continue;
				}

				$template_id = ALCacheHelper::randString(20);

				// Try to find matching config, otherwise use default delay config
				$delay_config = $this->createDelayConfigForScript($script, $js_config);

				// Apply forced attributes for delayed scripts
				$delay_config = $this->applyForcedDelayAttributes($delay_config);

				$new_script = $this->processDelayedScript($script, $delay_config, $script_index, $template_id);
				$scriptAttrEntry = $this->createScriptAttrsEntry($delay_config, $script, $script_index, $template_id);

				// NEW: Check if script has existing defer attribute
				if ($this->hasExistingDeferAttribute($script)) {
					$deferredScriptAttrs[] = $scriptAttrEntry;
				} else {
					$nonAggScriptAttrs[] = $scriptAttrEntry;
				}

				$buffer = str_replace($script, $new_script, $buffer);
				$script_index++;
			}

			return array($nonAggScriptAttrs, $deferredScriptAttrs);
		}

		private function processExactMatchScripts($extracted_scripts, $js_config, &$buffer, &$script_index) {
			$nonAggScriptAttrs = array();
			$deferredScriptAttrs = array(); // NEW: Separate array for deferred scripts
			$aggScriptAttrs = array();
			$defer_scripts = array();
			$delay_scripts = array();

			foreach ($extracted_scripts as $script) {
				$matching_config = $this->findMatchingConfig($script, $js_config);

				if ($matching_config) {
					$this->processMatchedScript($script, $matching_config, $buffer, $defer_scripts, $delay_scripts, $nonAggScriptAttrs, $deferredScriptAttrs, $script_index);
				}
			}

			// Process aggregated scripts
			$this->processAggregatedScripts($buffer, $defer_scripts, $delay_scripts, $aggScriptAttrs, $script_index);

			// Merge: aggregated scripts, normal scripts, then deferred scripts
			return array(array_merge($aggScriptAttrs, $nonAggScriptAttrs), $deferredScriptAttrs);
		}

		private function shouldSkipScriptByType($script, $js_config_hash) {
			if (preg_match('~<script.*?type=[\'"](.*?)[\'"]~is', $script, $type_value)) {
				if (isset($js_config_hash['js_types_to_ignore']) && is_array($js_config_hash['js_types_to_ignore'])) {
					if (!empty($type_value[1]) && in_array($type_value[1], $js_config_hash['js_types_to_ignore'])) {
						return true;
					}
				}
			}
			return false;
		}

		private function shouldSkipScriptByAttributes($script, $js_config_hash) {
			if (isset($js_config_hash['js_attributes_to_ignore']) && is_array($js_config_hash['js_attributes_to_ignore'])) {
				foreach ($js_config_hash['js_attributes_to_ignore'] as $excluded_attribute) {
					if (strpos($script, $excluded_attribute) !== false) {
						return true;
					}
				}
			}
			return false;
		}

		private function findMatchingAssetConfig($tag, $configs, $type) {
			foreach ($configs as $config) {
				$search_for = isset($config['search_for']) ? $config['search_for'] : '';
				if ($this->assetTagsMatch($tag, $search_for)) {
					return $config;
				}
			}

			$digest_configs = array();
			foreach ($configs as $config) {
				if ($this->assetConfigHasDigest($config)) {
					$digest_configs[] = $config;
				}
			}
			if (!empty($digest_configs)) {
				$url = $this->extractExternalAssetUrl($tag, $type);
				$local_path = $url !== '' ? $this->getLocalAssetPath($url) : false;
				if ($local_path) {
					$matching_config = $this->assetContentMatchingConfig();
					$size = false;
					if (function_exists('filesize') && is_readable($local_path)) {
						try {
							$size = filesize($local_path);
						} catch (Throwable $error) {
							$size = false;
						}
					}
					if ($size !== false && $size >= 0 && $size <= $matching_config['max_asset_bytes']) {
						// Raw hashing is bounded by the file-size limit and must not consume the
						// budget reserved for the more expensive normalization fallback.
						$raw_md5 = $this->assetFileDigest($local_path, 'raw', $type, null);
						if ($raw_md5 !== false) {
							$candidates = $this->assetDigestCandidates($digest_configs, 'orig_md5', $raw_md5);
							$resolved = $this->resolveAssetDigestCandidates($candidates, $url, $type);
							if ($resolved !== null) {
								return $resolved;
							}
							if (empty($candidates)) {
								$deadline = $this->assetContentMatchingDeadline($matching_config);
								$normalized_md5 = $deadline !== false
									? $this->assetFileDigest($local_path, 'normalized', $type, $deadline) : false;
								if ($normalized_md5 !== false) {
									$candidates = $this->assetDigestCandidates($digest_configs, 'normalized_md5', $normalized_md5);
									$resolved = $this->resolveAssetDigestCandidates($candidates, $url, $type);
									if ($resolved !== null) {
										return $resolved;
									}
								}
							}
						}
					}
				}
			}

			// Old and new asset rules may coexist while templates are gradually regenerated.
			foreach ($configs as $config) {
				if ($this->assetConfigHasDigest($config)) {
					continue;
				}
				$search_for_regex = isset($config['search_for_regex']) ? $config['search_for_regex'] : '';
				if ($search_for_regex !== '' && preg_match($search_for_regex, $tag)) {
					return $config;
				}
			}
			return null;
		}

		private function assetConfigHasDigest($config) {
			return (isset($config['orig_md5']) && is_string($config['orig_md5']) && $config['orig_md5'] !== '')
				|| (isset($config['normalized_md5']) && is_string($config['normalized_md5']) && $config['normalized_md5'] !== '');
		}

		private function assetTagsMatch($tag, $search_for) {
			if (!is_string($tag) || !is_string($search_for) || $search_for === '') {
				return false;
			}
			$stripped_tag = preg_replace('/\s+/', '', $tag);
			$stripped_search_for = preg_replace('/\s+/', '', $search_for);
			if ($stripped_tag !== '' && $stripped_search_for !== '') {
				return strpos($stripped_tag, $stripped_search_for) !== false;
			}

			return strpos($tag, $search_for) !== false;
		}

		private function assetContentMatchingConfig() {
			$config = isset($this->config['asset_content_matching']) && is_array($this->config['asset_content_matching'])
				? $this->config['asset_content_matching'] : array();

			$total_budget_ms = isset($config['total_budget_ms']) ? (int) $config['total_budget_ms'] : 100;
			$per_asset_budget_ms = isset($config['per_asset_budget_ms']) ? (int) $config['per_asset_budget_ms'] : 10;
			$max_asset_bytes = isset($config['max_asset_bytes']) ? (int) $config['max_asset_bytes'] : 2097152;
			$total_budget_ms = ($total_budget_ms >= 1 && $total_budget_ms <= 500) ? $total_budget_ms : 100;
			$per_asset_budget_ms = ($per_asset_budget_ms >= 1 && $per_asset_budget_ms <= 100) ? $per_asset_budget_ms : 10;
			$max_asset_bytes = ($max_asset_bytes >= 65536 && $max_asset_bytes <= 10485760) ? $max_asset_bytes : 2097152;

			return array(
				'total_budget_ms' => $total_budget_ms,
				'per_asset_budget_ms' => $per_asset_budget_ms,
				'max_asset_bytes' => $max_asset_bytes
			);
		}

		private function assetContentMatchingDeadline($config) {
			$remaining_total_budget_ms = $config['total_budget_ms'] - $this->asset_normalization_time_ms;
			if ($remaining_total_budget_ms <= 0) {
				return false;
			}

			$normalization_budget_ms = min($remaining_total_budget_ms, $config['per_asset_budget_ms']);
			return microtime(true) + ($normalization_budget_ms / 1000);
		}

		private function assetFileDigest($local_path, $kind, $type, $deadline) {
			if (!isset($this->asset_content_digest_cache) || !is_array($this->asset_content_digest_cache)) {
				$this->asset_content_digest_cache = array();
			}
			$cache_key = $type . '|' . $local_path;
			if (isset($this->asset_content_digest_cache[$cache_key]) && array_key_exists($kind, $this->asset_content_digest_cache[$cache_key])) {
				return $this->asset_content_digest_cache[$cache_key][$kind];
			}
			if ($deadline !== null && microtime(true) >= $deadline) {
				return false;
			}

			$digest = false;
			if ($kind === 'raw') {
				if (function_exists('md5_file')) {
					try {
						$digest = md5_file($local_path);
					} catch (Throwable $error) {
						$digest = false;
					}
				}
			} else {
				$normalization_started_at = microtime(true);
				try {
					$content = function_exists('file_get_contents') ? file_get_contents($local_path) : false;
					$digest = is_string($content) ? ALAsset_Content_Normalizer::normalized_md5($content, $type, $deadline) : false;
				} catch (Throwable $error) {
					$digest = false;
				}
				$this->asset_normalization_time_ms += max(0, (microtime(true) - $normalization_started_at) * 1000);
			}
			if (!isset($this->asset_content_digest_cache[$cache_key])) {
				$this->asset_content_digest_cache[$cache_key] = array();
			}
			$this->asset_content_digest_cache[$cache_key][$kind] = $digest;
			return $digest;
		}

		private function assetDigestCandidates($configs, $digest_key, $digest) {
			$candidates = array();
			foreach ($configs as $config) {
				if (isset($config[$digest_key]) && is_string($config[$digest_key]) && $config[$digest_key] === $digest) {
					$candidates[] = $config;
				}
			}
			return $candidates;
		}

		private function resolveAssetDigestCandidates($candidates, $current_url, $type) {
			if (count($candidates) === 1) {
				return $candidates[0];
			}
			if (empty($candidates)) {
				return null;
			}

			$current_path = $this->assetUrlPath($current_url);
			$path_matches = array();
			foreach ($candidates as $candidate) {
				$search_for = isset($candidate['search_for']) ? $candidate['search_for'] : '';
				$candidate_url = $this->extractExternalAssetUrl($search_for, $type);
				if ($current_path !== '' && $this->assetUrlPath($candidate_url) === $current_path) {
					$path_matches[] = $candidate;
				}
			}

			return count($path_matches) === 1 ? $path_matches[0] : null;
		}

		private function extractExternalAssetUrl($tag, $type) {
			if (!is_string($tag)) {
				return '';
			}
			$attributes = $type === 'js' ? $this->getScriptAttributes($tag) : $this->getLinkAttributes($tag);
			$key = $type === 'js' ? 'src' : 'href';
			if (!isset($attributes[$key]) || !is_string($attributes[$key])) {
				return '';
			}

			$url = html_entity_decode(trim($attributes[$key]), ENT_QUOTES | ENT_HTML5);
			return $url !== '' && strpos($url, 'data:') !== 0 ? $url : '';
		}

		private function assetUrlPath($url) {
			$path = parse_url($url, PHP_URL_PATH);
			return is_string($path) ? rawurldecode($path) : '';
		}

		private function findMatchingConfig($script, $js_config) {
			return $this->findMatchingAssetConfig($script, $js_config, 'js');
		}

		private function createDelayConfigForScript($script, $js_config) {
			// First try to find a matching config for this script
			$matching_config = $this->findMatchingConfig($script, $js_config);
			
			if ($matching_config && !(isset($matching_config['is_aggregated']) && $matching_config['is_aggregated'])) {
				// Use the matching config but force delayed mode
				$delay_config = $matching_config;
				$delay_config['is_delayed'] = true;
			} else {
				$attributes = array();
				$scriptAttributes = $this->getScriptAttributes($script);
				$ignoredAttributes = isset($this->config['strict_js_attrs']) ? $this->config['strict_js_attrs'] : array();

				foreach ($scriptAttributes as $attrName => $attrValue) {
					if (in_array($attrName, $ignoredAttributes)) {
						continue;
					}

					$attributes[$attrName] = $attrValue;
				}

				// Create default delay config
				$delay_config = array(
					'is_delayed' => true,
					'is_inline' => $this->isInlineScript($script),
					'attributes' => $attributes
				);
			}
			
			return $delay_config;
		}

		private function applyForcedDelayAttributes($delay_config) {
			if (isset($this->config['forced_delay_js_attrs']) && is_array($this->config['forced_delay_js_attrs'])) {
				if (!isset($delay_config['attributes'])) {
					$delay_config['attributes'] = array();
				}
				$delay_config['attributes'] = array_merge($delay_config['attributes'], $this->config['forced_delay_js_attrs']);
			}
			return $delay_config;
		}

		private function processMatchedScript($script, $matching_config, &$buffer, &$defer_scripts, &$delay_scripts, &$nonAggScriptAttrs, &$deferredScriptAttrs, &$script_index) {
			$is_delay = isset($matching_config['is_delayed']) ? $matching_config['is_delayed'] : false;
			$is_defer = isset($matching_config['is_defer']) ? $matching_config['is_defer'] : false;
			$is_aggregated = isset($matching_config['is_aggregated']) ? $matching_config['is_aggregated'] : false;
			$final_source_url = isset($matching_config['final_source_url']) ? $matching_config['final_source_url'] : '';
			$has_existing_defer = $this->hasExistingDeferAttribute($script);

			if ($this->isPartialJsDelayAllowed($matching_config)) {
				$script_index++;
				return;
			}

			if ($is_delay && $is_aggregated) {
				if (!isset($delay_scripts[$final_source_url])) {
					$delay_scripts[$final_source_url] = array();
				}
				$delay_scripts[$final_source_url][] = $matching_config;
				$buffer = str_replace($script, '', $buffer);
			} elseif ($is_delay) {
				$template_id = ALCacheHelper::randString(20);
				$new_script = $this->processDelayedScript($script, $matching_config, $script_index, $template_id);
				$scriptAttrEntry = $this->createScriptAttrsEntry($matching_config, $script, $script_index, $template_id);

				// NEW: Separate based on existing defer attribute
				if ($has_existing_defer) {
					$deferredScriptAttrs[] = $scriptAttrEntry;
				} else {
					$nonAggScriptAttrs[] = $scriptAttrEntry;
				}

				$buffer = str_replace($script, $new_script, $buffer);
			} elseif ($is_defer && $is_aggregated) {
				if (!isset($defer_scripts[$final_source_url])) {
					$defer_scripts[$final_source_url] = array();
				}
				$defer_scripts[$final_source_url][] = $matching_config;
				$buffer = str_replace($script, '', $buffer);
			} else {
				$new_script = $this->processNonDelayedScript($script, $matching_config);
				$buffer = str_replace($script, $new_script, $buffer);
			}
			$script_index++;
		}

		private function processAggregatedScripts(&$buffer, $defer_scripts, $delay_scripts, &$aggScriptAttrs, &$script_index) {
			// Sort and process defer_scripts in descending order (higher priority first)
			uasort($defer_scripts, function($a, $b) {
				$priorityA = isset($a[0]['aggregation_priority']) ? $a[0]['aggregation_priority'] : PHP_INT_MAX;
				$priorityB = isset($b[0]['aggregation_priority']) ? $b[0]['aggregation_priority'] : PHP_INT_MAX;

				if ($priorityA === $priorityB) {
					return 0;
				}
				return ($priorityB < $priorityA) ? -1 : 1; // Descending order (higher priority first)
			});

			$head_tag = $this->extractHeadTag($buffer);

			foreach ($defer_scripts as $final_source_url => $configs) {
				$aggregated_script = $this->createAggregatedScript($final_source_url, $configs);
				$buffer = ALHelper::safeStrReplaceFirst($head_tag, $head_tag . "\n" . $aggregated_script, $buffer);
			}

			// Sort and process delay_scripts in descending order (higher priority first)
			uasort($delay_scripts, function($a, $b) {
				$priorityA = isset($a[0]['aggregation_priority']) ? $a[0]['aggregation_priority'] : PHP_INT_MAX;
				$priorityB = isset($b[0]['aggregation_priority']) ? $b[0]['aggregation_priority'] : PHP_INT_MAX;

				if ($priorityA === $priorityB) {
					return 0;
				}
				return ($priorityB < $priorityA) ? -1 : 1; // Descending order (higher priority first)
			});

			foreach ($delay_scripts as $final_source_url => $configs) {
				$template_id = ALCacheHelper::randString(20);
				$template = "<template id=\"$template_id\"></template>";
				$buffer = ALHelper::safeStrReplaceFirst($head_tag, $head_tag . "\n" . $template, $buffer);
				$aggScriptAttrs[] = $this->createScriptAttrsEntry($configs[0], $template, $script_index, $template_id);
				$script_index++;
			}
			
			// Reverse aggScriptAttrs to match DOM order (ascending priority)
			$aggScriptAttrs = array_reverse($aggScriptAttrs);
		}

		private function addScriptAttrsToBuffer($buffer, $scriptAttrs) {
			$head_tag = $this->extractHeadTag($buffer);
			$scriptAttrsJson = json_encode($scriptAttrs);
			$scriptAttrsScript = "<script id=\"bv-dl-scripts-list\" data-cfasync=\"false\" bv-exclude=\"true\">\nvar scriptAttrs = $scriptAttrsJson;\n</script>";
			return ALHelper::safeStrReplaceFirst($head_tag, $head_tag . "\n" . $scriptAttrsScript, $buffer);
		}

		private function createAggregatedScript($final_source_url, $configs) {
			$attributes = array();
			foreach ($configs as $config) {
				if (isset($config['attributes'])) {
					$attributes = array_merge($attributes, $config['attributes']);
				}
			}

			$new_script = '<script';

			foreach ($attributes as $attr => $value) {
				if ($attr === 'src') {
					continue;
				}
				
				// If value is boolean false, skip the attribute entirely
				if ($value === false) {
					continue;
				}
				
				// For all other values (including boolean true), use normal behavior with quotes
				$new_script .= ' ' . $attr . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
			}

			// Only add defer if it's not already in attributes
			if (!isset($attributes['defer'])) {
				$new_script .= ' defer';
			}

			$new_script .= ' src="' . htmlspecialchars($final_source_url, ENT_QUOTES, 'UTF-8') . '"></script>';

			return $new_script;
		}

		private function processDelayedScript($script, $config, $script_index, $template_id) {
			$is_inline = isset($config['is_inline']) ? $config['is_inline'] : false;

			if ($is_inline) {
				return $this->processDelayedInlineScript($script, $config, $template_id);
			} else {
				return $this->processDelayedExternalScript($script, $template_id);
			}
		}

		private function processDelayedInlineScript($script, $config, $template_id) {
			$inline_js_content_regex = '/<script[^>]*>([\s\S]*?)<\/script>/i';
			if (isset($this->config['inline_js_content_regex'])) {
				$inline_js_content_regex = $this->config['inline_js_content_regex'];
			}
			if (preg_match($inline_js_content_regex, $script, $matches)) {
				$content = trim($matches[1]);
			} else {
				return $script;
			}

			$new_script = '<script type="bv_inline_delayed_js" bv_unique_id="' . $template_id . '"';

			$attributes = isset($config['attributes']) ? $config['attributes'] : array();
			foreach ($attributes as $attr => $value) {
				if ($attr === 'type') {
					continue;
				}
				$new_script .= ' ' . $attr . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
			}
			$new_script .= '>' . $content . '</script>';
			return $new_script;
		}

		private function processDelayedExternalScript($script, $template_id) {
			return '<template id="' . $template_id . '"></template>';
		}

		private function processNonDelayedScript($script, $config) {
			$is_inline = isset($config['is_inline']) ? $config['is_inline'] : false;
			$is_async = isset($config['is_async']) ? $config['is_async'] : false;
			$is_defer = isset($config['is_defer']) ? $config['is_defer'] : false;
			$final_source_url = isset($config['final_source_url']) ? $config['final_source_url'] : '';
			$attributes = isset($config['attributes']) ? $config['attributes'] : array();
			$scriptAttributes = $this->getScriptAttributes($script);
			$ignoredAttributes = isset($this->config['strict_js_attrs']) ? $this->config['strict_js_attrs'] : array();

			foreach ($scriptAttributes as $attrName => $attrValue) {
				if (in_array($attrName, $ignoredAttributes)) {
					continue;
				}

				$attributes[$attrName] = $attrValue;
			}

			if ($is_inline) {
				return $this->processNonDelayedInlineScript($script, $is_async, $is_defer, $attributes);
			} else {
				return $this->processNonDelayedExternalScript($script, $is_async, $is_defer, $final_source_url, $attributes);
			}
		}

		private function processNonDelayedInlineScript($script, $is_async, $is_defer, $attributes) {
			$inline_js_content_regex = '/<script[^>]*>([\s\S]*?)<\/script>/i';
			if (isset($this->config['inline_js_content_regex'])) {
				$inline_js_content_regex = $this->config['inline_js_content_regex'];
			}
			if (preg_match($inline_js_content_regex, $script, $matches)) {
				$content = trim($matches[1]);
			} else {
				return $script;
			}

			if ($is_async || $is_defer) {
				$encoded_content = base64_encode($content);
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
				$new_script = '<script src="data:text/javascript;base64,' . $encoded_content . '"';
			} else {
				$new_script = '<script';
			}

			foreach ($attributes as $attr => $value) {
				if ($attr === 'src') {
					continue;
				}
				$new_script .= ' ' . $attr . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
			}

			if ($is_async) {
				$new_script .= ' async';
			}
			if ($is_defer) {
				$new_script .= ' defer';
			}

			if ($is_async || $is_defer) {
				$new_script .= '></script>';
			} else {
				$new_script .= '>' . $content . '</script>';
			}

			return $new_script;
		}

		private function processNonDelayedExternalScript($script, $is_async, $is_defer, $final_source_url, $attributes) {
			if (empty($final_source_url) && preg_match('/src=["\'](.*?)["\']/i', $script, $matches)) {
				$final_source_url = $matches[1];
			}

			$new_script = '<script';

			foreach ($attributes as $attr => $value) {
				if ($attr === 'src') {
					continue;
				}
				$new_script .= ' ' . $attr . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
			}

			if ($is_async) {
				$new_script .= ' async';
			}
			if ($is_defer) {
				$new_script .= ' defer';
			}

			$new_script .= ' src="' . htmlspecialchars($final_source_url, ENT_QUOTES, 'UTF-8') . '"></script>';

			return $new_script;
		}

		private function getScriptAttributes($scriptTag) {
			if (!is_string($scriptTag) || trim($scriptTag) === '') {
				return [];
			}

			if (!isset($this->config['extract_js_attr_regex'])) {
				return [];
			}

			$attr_regex = $this->config['extract_js_attr_regex'];
			$attributes = [];

			if (preg_match('/<script\b(?<attrs>[^>]*)>/i', $scriptTag, $matches)) {
				$attrString = $matches['attrs'];

				if (preg_match_all($attr_regex, $attrString, $matches, PREG_SET_ORDER) && is_array($matches) && !empty($matches)) {
					foreach ($matches as $match) {
						$name = $match['name'];
						$value = !empty($match['value1']) ? $match['value1'] :
							(!empty($match['value2']) ? $match['value2'] :
							(!empty($match['value3']) ? $match['value3'] :
							(!empty($match['value4']) ? $match['value4'] : '')));

						$attributes[$name] = $value;
					}
				}
			}
			return $attributes;
		}

		private function createScriptAttrsEntry($config, $script, $script_index, $bv_unique_id) {
			$is_inline = isset($config['is_inline']) ? $config['is_inline'] : false;
			$attributes = isset($config['attributes']) ? $config['attributes'] : array();
			$scriptAttributes = $this->getScriptAttributes($script);
			$ignoredAttributes = isset($this->config['strict_js_attrs']) ? $this->config['strict_js_attrs'] : array();

			foreach ($scriptAttributes as $attrName => $attrValue) {
				if (in_array($attrName, $ignoredAttributes)) {
					continue;
				}

				$attributes[$attrName] = $attrValue;
			}

			$entry = array(
				"attrs" => $attributes,
				"bv_unique_id" => $bv_unique_id,
				"reference" => $script_index
			);

			$entry['attrs']['bv_inline_delayed'] = $is_inline;

			if (!$is_inline) {
				$final_source_url = isset($config['final_source_url']) ? $config['final_source_url'] : '';
				
				// If no final_source_url from config, try to get src from already extracted attributes
				if (empty($final_source_url) && isset($attributes['src']) && !empty($attributes['src'])) {
					$final_source_url = $attributes['src'];
				}
				
				$decoded_url = html_entity_decode($final_source_url);
				$entry['attrs']['src'] = $decoded_url;
			}
			return $entry;
		}

		private function replaceLastBodyTag($buffer, $replaceWith) {
			$parts = explode('</body>', $buffer);
			if (count($parts) > 1) {
				$last_part = array_pop($parts);
				return implode('</body>', $parts) . $replaceWith . $last_part;
			}

			if (!is_string($replaceWith) || '' === $replaceWith) {
				return $buffer;
			}

			$body_tag_position = strripos($replaceWith, '</body>');
			if (false !== $body_tag_position) {
				$replaceWith = substr($replaceWith, 0, $body_tag_position)
					. substr($replaceWith, $body_tag_position + strlen('</body>'));
			}

			if ('' === $replaceWith) {
				return $buffer;
			}

			$script_end_tag_count = preg_match_all('~</\s*script\s*>~i', $buffer, $script_end_tags, PREG_OFFSET_CAPTURE);
			if (!$script_end_tag_count || empty($script_end_tags[0])) {
				return $buffer . $replaceWith;
			}

			$last_script_end_tag = end($script_end_tags[0]);
			$last_script_end = $last_script_end_tag[1] + strlen($last_script_end_tag[0]);
			return substr($buffer, 0, $last_script_end)
				. $replaceWith
				. substr($buffer, $last_script_end);
		}

		public function addJavascripts($buffer, $js_to_add_config) {
			if (!is_array($js_to_add_config) || empty($js_to_add_config)) {
				return $buffer;
			}
			foreach ($js_to_add_config as $config) {
				if (is_array($config) && !empty($config)) {
					if (array_key_exists("search_for", $config) && array_key_exists("replace_with", $config)) {
						if ($config['search_for'] === '</body>') {
							$buffer = $this->replaceLastBodyTag($buffer, $config['replace_with']);
						} else if(strpos($buffer, $config['search_for']) !== false) {
							$buffer = ALHelper::safePregReplace(
								'/' . preg_quote($config["search_for"], '/') . '/',
								$config["replace_with"],
								$buffer,
								1
							);
						} else if(array_key_exists("tag_name", $config)) {
							if (ALHelper::safePregMatch('/<'.$config["tag_name"].'\b[^>]*>/iU', $buffer, $matches)) {
								$buffer = ALHelper::safePregReplace(
									'/' . preg_quote($matches[0], '/') . '/',
									$config['replace_with'],
									$buffer,
									1
								);
							}
						}
					}
				}
			}

			return $buffer;
		}

		public function handleStylesheet($buffer, $style_config) {
			if (!is_array($style_config) || empty($style_config)) {
				return $buffer;
			}
			$result = $this->extractAndReplaceStyles($buffer, $style_config);
			// Preserve exact replacement for inline <style> rules; content matching is external-only.
			return $this->doSearchAndReplace($result['modified_buffer'], $style_config);
		}

		public function handleFonts($buffer, $font_config) {
			if (!is_array($font_config) || empty($font_config)) {
				return $buffer;
			}

			foreach ($font_config as $config) {
				if (is_array($config) && !empty($config)) {
					if (array_key_exists("search_for", $config) && array_key_exists("replace_with", $config)) {
						$buffer = ALHelper::safeStrReplace($config["search_for"], $config["replace_with"], $buffer);
					}
				}
			}

			return $buffer;
		}

		public function sanitize_cdn_url($url) {
			$url = sanitize_text_field($url);

			if (!$url || !ALHelper::safePregMatch('@^https?://.+\.[^.]+@i', $url)) {
				// Not an URL.
				return '';
			}

			return trailingslashit($url);
		}

		public function getParsedUrlInfo() {
			$info = array();
			$info['scheme'] = (ALHelper::getRawParam('SERVER', 'HTTPS')) ? "https://" : "http://";
			$info['path'] = dirname(ALHelper::getRawParam('SERVER', 'REQUEST_URI'));
			$info['host'] = ALHelper::getRawParam('SERVER', 'HTTP_HOST');
			return $info;
		}

		public function hasExcludedSource($url) {
			if (!array_key_exists('image_excluded_src_attributes', $this->config) && !is_array($this->config['image_excluded_src_attributes'])) {
				return false;
			}

			foreach ($this->config['image_excluded_src_attributes'] as $attr) {
				if (strpos($url, $attr) !== false) {
					return true;
				}
			}

			return false;
		}

		public function hasExcludedIframeAttrs($iframe) {
			if (!array_key_exists('iframe_excluded_attrs', $this->config) && !is_array($this->config['iframe_excluded_attrs'])) {
				return false;
			}

			foreach ($this->config['iframe_excluded_attrs'] as $excluded_attr) {
				if (strpos($iframe[0], $excluded_attr) !== false) {
					return true;
				}
			}
			return false;
		}

		public function hasExcludedAttributes($element) {
			if (!array_key_exists('image_excluded_attributes', $this->config) && !is_array($this->config['image_excluded_attributes'])) {
				return false;
			}

			foreach ($this->config['image_excluded_attributes'] as $excluded_attr) {
				if (strpos($element, $excluded_attr) !== false) {
					return true;
				}
			}

			return false;
		}

		public function canLazyload($image, $image_config) {
			if ($this->hasExcludedAttributes($image)) {
				return false;
			}
			if (!ALHelper::safePregMatch('#\ssrc\s*=\s*(\'|")(?<src>.*)\1#Uis', $image, $attrs)) {
				return false;
			}

			$img_src = trim($attrs['src']);

			if ( '' === $img_src || strpos($img_src, 'data:image/svg+xml') === 0 ) {
				return false;
			}

			if (isset($image_config['images_not_to_lazyload']) && is_array($image_config['images_not_to_lazyload'])) {
				foreach ($image_config['images_not_to_lazyload'] as $image_not_to_lazyload) {
					$image_not_to_lazyload = $this->getImageCdnUrl($image_not_to_lazyload, $image_config['replace_urls']);
					if (strpos($img_src, $image_not_to_lazyload) !== false) {
						return false;
					}
				}
			}

			return $image;
		}



		public function url_relative_to_full($url, $scheme, $host, $path) {
			$final_url = "";
			if (strpos($url, './') === 0 && !empty($path)) {
				$final_url = $scheme . $host . rtrim($path, '/') . '/' . ltrim($url, './');
			} elseif (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
				$final_url = $scheme . $host . $url;
			} else {
				$final_url = $url;
			}
			return $final_url;
		}

		public function replace_host_with_cdn($replace_urls, $url){
			foreach($replace_urls as $replace_url) {
				if (array_key_exists("search_for", $replace_url) && array_key_exists("replace_with", $replace_url)) {
					if (strpos($url, $replace_url["search_for"]) !== false) {
						return ALHelper::safeStrReplace($replace_url["search_for"], $replace_url["replace_with"], $url);
					}
				}
			}
			$new_url = $this->convertThirdPartyUrlToCdnUrl($url);
			return $new_url;
		}


		public function replace_host_with_site_urls($replace_urls, $url, $host){
			foreach($replace_urls as $replace_url) {
				if (array_key_exists("search_for", $replace_url) && array_key_exists("replace_with", $replace_url)) {
					if (strpos($url, $replace_url["search_for"]) === 0) {
						return ALHelper::safeStrReplace($replace_url["search_for"], $replace_url["replace_with"] . $host, $url);
					}
				}
			}
			$new_url = $this->convertThirdPartyUrlToSiteUrl($url);
			return $new_url;
		}

		public function isThirdPartyUrl($url) {
			$current_domain = ALHelper::getRawParam('SERVER', 'HTTP_HOST');
			$image_domain = parse_url($url, PHP_URL_HOST);
			if (empty($image_domain)) {
				return false;
			}
			return ($image_domain !== $current_domain);
		}

		public function convertThirdPartyUrlToCdnUrl($image_url) {
			if ($this->isThirdPartyUrl($image_url)) {
				$image_domain = parse_url($image_url, PHP_URL_HOST);
				$cdn_host = parse_url($this->config['cdn_url'], PHP_URL_HOST);
				return ALHelper::safeStrReplace($image_domain, $cdn_host, $image_url);
			}
			return $image_url;
		}

		public function convertThirdPartyUrlToSiteUrl($image_url) {
			if ($this->isThirdPartyUrl($image_url)) {
				$img_url_info = parse_url($image_url);
				if (!is_array($img_url_info) || empty($img_url_info['host']) || empty($img_url_info['path'])) {
					return $image_url;
				}
				$new_img_url = $this->config['image_url_path'] . $img_url_info['host'] . '/' . $img_url_info['path'];
				return $new_img_url;
			}
			return $image_url;
		}

		private function getResizedImagePaths($image_url) {
			if (!isset($this->config['resized_image_postfix_names']) || !is_array($this->config['resized_image_postfix_names'])) {
				return [];
			}

			$img_path_info = pathinfo($image_url);
			$img_url_info = parse_url($image_url);

			if (!is_array($img_path_info) || !is_array($img_url_info) ||
					empty($img_url_info['host']) || empty($img_url_info['path'])) {
				return [];
			}

			$upload_dir = wp_upload_dir();
			$image_basepath = $upload_dir['basedir'] . '/al_opt_content/IMAGE/' . $img_url_info['host'] . '/' . $img_url_info['path'];

			if (empty($img_path_info['extension'])) {
				return [];
			}

			$extension = $img_path_info['extension'];

			$resized_images = [];
			$optimized_extension = $this->getOptimizedImageExtension();

			foreach ($this->config['resized_image_postfix_names'] as $postfix) {
				$original = $image_basepath . '.' . $postfix . '.' . $extension;
				$optimized = $original . $optimized_extension;
				$resized_images[$postfix] = [
					'original' => $original,
					'optimized' => $optimized
				];
			}

			return ['basepath' => $image_basepath, 'variants' => $resized_images];
		}

		private function getOptimizedImageFormat() {
			$format = '';
			if (isset($this->image_config['image_optimization_format'])) {
				$format = strtolower((string) $this->image_config['image_optimization_format']);
			}

			if ($format === 'avif') {
				return 'avif';
			}

			return 'webp';
		}

		private function getOptimizedImageExtension() {
			return '.bv.' . $this->getOptimizedImageFormat();
		}

		public function isOptimizedImagePresent($image_url) {
			$resized = $this->getResizedImagePaths($image_url);
			if (empty($resized)) {
				return false;
			}

			if (!file_exists($resized['basepath']) || !file_exists($resized['basepath'] . $this->getOptimizedImageExtension())) {
				return false;
			}

			foreach ($resized['variants'] as $paths) {
				if (!file_exists($paths['optimized'])) {
					return false;
				}
			}

			return true;
		}

		private function appendResizedImageInfos($image_url, $new_image_url) {
			$resized = $this->getResizedImagePaths($image_url);
			if (empty($resized)) {
				return $new_image_url;
			}

			$image_info_parts = [];

			foreach ($resized['variants'] as $postfix => $paths) {
				if (file_exists($paths['optimized'])) {
					$size = getimagesize($paths['optimized']);
					if ($size) {
						$image_info_parts[] = $postfix . ':' . $size[0] . '*' . $size[1];
					}
				}
			}

			if (!empty($image_info_parts)) {
				$resized_info_str = implode(';', $image_info_parts);
				$resized_info_param = 'bv-resized-infos=' . urlencode($resized_info_str);
				$separator = (parse_url($new_image_url, PHP_URL_QUERY)) ? '&' : '?';
				$new_image_url .= $separator . $resized_info_param;
			}

			return $new_image_url;
		}

		public function getImageCdnUrl($image_url, $replace_urls) {
			if (isset($this->config['image_excluded_src_attributes']) && is_array($this->config['image_excluded_src_attributes'])) {
				foreach ($this->config['image_excluded_src_attributes'] as $attrs) {
					if (strpos($image_url, $attrs) !== false) {
						return $image_url;
					}
				}
			}
			$parsed_url_info = $this->getParsedUrlInfo();
			$image_url = $this->url_relative_to_full($image_url, $parsed_url_info['scheme'], $parsed_url_info['host'], $parsed_url_info['path']);

			# In the current CloudFront flow, optimized images are still stored on the site.
			# Only rewrite to the optimized path when the optimized asset is present there.
			if (!$this->isOptimizedImagePresent($image_url)) {
				return $image_url;
			}

			if ($this->image_config['copied_to_site']) {
				$parsed_url = parse_url($image_url);
				$new_image_url = $this->replace_host_with_site_urls($replace_urls, $image_url, $parsed_url['host']);
				$new_image_url = $this->appendResizedImageInfos($image_url, $new_image_url);
				return $new_image_url;
			} else {
				$new_image_url = $this->replace_host_with_cdn($replace_urls, $image_url);
				$new_image_url = $this->appendResizedImageInfos($image_url, $new_image_url);
				return $new_image_url;
			}

			return $image_url;
		}

		public function getUrlsFromAttributes($image_tag) {
			$image_urls = array();
			if (array_key_exists('image_src_attributes', $this->config) && is_array($this->config['image_src_attributes'])) {
				$attrs = implode('|', $this->config['image_src_attributes']);
				if (preg_match_all('#\s(' . $attrs . ')\s*=\s*(\'|")(?<values>.*?)\2#is', $image_tag, $matches)) {
					foreach ($matches['values'] as $values) {
						$values = trim($values);
						if ($this->hasEmbeddedImage($values)) continue;
						$values = explode(',', $values);
						foreach ($values as $src) {
							$src = preg_split('/\s+/', trim($src));
							array_push($image_urls, $src[0]);
						}
					}
				}
				return $image_urls;
			}
			return array();
		}


		public function replaceSrcAndSrcsetImages($element, $replace_urls, $class_name = "") {
			$urls_to_handle = array();
			$image_urls = $this->getUrlsFromAttributes($element);
			$image_urls = array_unique($image_urls, SORT_REGULAR);
			if (empty($image_urls)) {
				return $element;
			}
			if (!empty($class_name)) $element = $this->addBvClass($element, $class_name);
			foreach ($image_urls as $url) {
				array_push($urls_to_handle, $url);
			}

			foreach ($urls_to_handle as $url) {
				$final_url = $this->getImageCdnUrl($url, $replace_urls);
				if ($final_url) $element = ALHelper::safeStrReplace($url, $final_url, $element);
			}
			return $element;
		}

		public function handlePictureTags($buffer, $image_config, $url_matched) {
			if (!preg_match_all('#<picture(?:.*)?>(?<sources>.*)</picture>#iUs', $buffer, $pictures, PREG_SET_ORDER)) {
				return $buffer;
			}
			$index = 0;
			foreach ($pictures as $picture) {
				$index += 1;
				$updated_picture = NULL;
				if (isset($image_config['picture_tags_not_to_lazyload']) && is_array($image_config['picture_tags_not_to_lazyload']) && $url_matched) {
					if (in_array($index, $image_config['picture_tags_not_to_lazyload'])) {
						$updated_picture = ALHelper::safeStrReplace('<picture', '<picture data-bvlazyload="false"', $picture[0]);
						$buffer = ALHelper::safeStrReplace($picture[0], $updated_picture, $buffer);
					}
				}
				if (is_null($updated_picture) && $this->hasExcludedAttributes($picture[0])){
					$updated_picture = ALHelper::safeStrReplace('<picture', '<picture data-bvlazyload="false"', $picture[0]);
					$buffer = ALHelper::safeStrReplace($picture[0], $updated_picture, $buffer);
				}

			}
			if (!preg_match_all('#<picture(?:.*)?>(?<sources>.*)</picture>#iUs', $buffer, $pictures, PREG_SET_ORDER)) {
				return $buffer;
			}
			$pictures = array_unique($pictures, SORT_REGULAR);
			foreach ($pictures as $picture) {
				$lazyload_picture = isset($this->config["lazyload_existing_picture_tags"]) ? $this->config["lazyload_existing_picture_tags"] : false;
				$updated_picture = $picture[0];
				if (strpos($picture[0], 'data-bvlazyload') !== false) {
					$lazyload_picture = false;
				}
				if ($lazyload_picture) {
					$updated_picture = $this->addLazyloadClassToPicture($picture[0]);
				}
				if (preg_match_all('#<source(?<atts>\s.+)>#iUs', $picture['sources'], $sources, PREG_SET_ORDER)) {
					$sources = array_unique($sources, SORT_REGULAR);

					foreach ($sources as $source) {
						$final_source = $source;
						$final_source[0] = $this->replaceSrcAndSrcsetImages($final_source[0], $image_config['replace_urls']);
						if ($lazyload_picture) {
							$final_source[0] = ALHelper::safePregReplace('/([\s"\'])srcset/i', '\1bv-data-srcset', $final_source[0]);
						}
						$updated_picture = ALHelper::safeStrReplace($source[0], $final_source[0], $updated_picture);
					}
				}
				if (preg_match_all('#<img\s.+\s?/?>#Uis', $picture[0], $image, PREG_SET_ORDER)) {
					$final_image = $image[0];
					$final_image[0] = $this->replaceSrcAndSrcsetImages($final_image[0], $image_config['replace_urls']);
					if ($lazyload_picture) {
						$final_image[0] = $this->lazyloadImage($final_image[0], $image_config, false);
					}
					$updated_picture = ALHelper::safeStrReplace($image[0][0], $final_image[0], $updated_picture);
				}
				$buffer = ALHelper::safeStrReplace($picture[0], $updated_picture, $buffer);
			}
			return $buffer;
		}

		public function imageHeight($image_tag) {
			if (ALHelper::safePregMatch('#\sheight\s*=\s*(\'|")(?<height>.*?)(\'|")#iUs', $image_tag, $matches)) {
				return absint($matches['height']);
			}
			return 0;
		}

		public function imageWidth($image_tag) {
			if (ALHelper::safePregMatch('#\swidth\s*=\s*(\'|")(?<width>.*?)(\'|")#iUs', $image_tag, $matches)) {
				return absint($matches['width']);
			}
			return 0;
		}

		private function getImageDimensionsFromResizedInfos($image_url) {
			$image_url = html_entity_decode($image_url, ENT_QUOTES|ENT_HTML5);
			$query_string = parse_url($image_url, PHP_URL_QUERY);
			if (empty($query_string)) {
				return false;
			}

			parse_str($query_string, $query_params);
			if (empty($query_params['bv-resized-infos'])) {
				return false;
			}

			$resized_infos = array();
			foreach (explode(';', $query_params['bv-resized-infos']) as $resized_info) {
				$parts = explode(':', $resized_info, 2);
				if (count($parts) !== 2) {
					continue;
				}

				$dimensions = explode('*', $parts[1], 2);
				if (count($dimensions) !== 2) {
					continue;
				}

				$width = absint($dimensions[0]);
				$height = absint($dimensions[1]);
				if ($width > 0 && $height > 0) {
					$resized_infos[$parts[0]] = array($width, $height);
				}
			}

			foreach (array('bv_resized_mobile', 'bv_resized_ipad', 'bv_resized_desktop') as $device) {
				if (isset($resized_infos[$device])) {
					return $resized_infos[$device];
				}
			}

			return empty($resized_infos) ? false : reset($resized_infos);
		}

		private function currentSiteHosts() {
			$hosts = array(
				parse_url(home_url(), PHP_URL_HOST),
				parse_url(site_url(), PHP_URL_HOST),
				ALHelper::getRawParam('SERVER', 'HTTP_HOST')
			);

			$hosts = array_filter(array_map('strtolower', $hosts));
			return array_values(array_unique($hosts));
		}

		private function getLocalAssetPath($image_url) {
			$image_url = html_entity_decode($image_url, ENT_QUOTES|ENT_HTML5);
			$image_url = trim($image_url, "\"' ");
			if ($image_url === '' || strpos($image_url, 'data:') === 0) {
				return false;
			}

			$parsed_url = parse_url($image_url);
			if ($parsed_url === false) {
				return false;
			}

			$host = isset($parsed_url['host']) ? strtolower($parsed_url['host']) : '';
			if ($host !== '' && !in_array($host, $this->currentSiteHosts(), true)) {
				return false;
			}

			$path = isset($parsed_url['path']) ? rawurldecode($parsed_url['path']) : '';
			if ($path === '' || strpos($path, '..') !== false) {
				return false;
			}

			$local_path = false;
			$upload_dir = wp_upload_dir();
			if (!empty($upload_dir['baseurl']) && !empty($upload_dir['basedir'])) {
				$upload_url_path = parse_url($upload_dir['baseurl'], PHP_URL_PATH);
				$upload_url_path = rtrim($upload_url_path ? $upload_url_path : '', '/');
				if ($upload_url_path !== '' && strpos($path, $upload_url_path . '/') === 0) {
					$local_path = rtrim($upload_dir['basedir'], '/') . substr($path, strlen($upload_url_path));
				}
			}

			if (!$local_path && function_exists('content_url') && defined('WP_CONTENT_DIR')) {
				$content_url_path = parse_url(content_url(), PHP_URL_PATH);
				$content_url_path = rtrim($content_url_path ? $content_url_path : '', '/');
				if ($content_url_path !== '' && strpos($path, $content_url_path . '/') === 0) {
					$local_path = rtrim(WP_CONTENT_DIR, '/') . substr($path, strlen($content_url_path));
				}
			}

			if (!$local_path && defined('ABSPATH')) {
				$site_url_path = parse_url(site_url(), PHP_URL_PATH);
				$site_url_path = rtrim($site_url_path ? $site_url_path : '', '/');
				$relative_path = $path;
				if ($site_url_path !== '' && strpos($path, $site_url_path . '/') === 0) {
					$relative_path = substr($path, strlen($site_url_path));
				}
				$local_path = rtrim(ABSPATH, '/') . '/' . ltrim($relative_path, '/');
			}

			return ($local_path && is_readable($local_path)) ? $local_path : false;
		}

		private function getLocalImagePath($image_url) {
			return $this->getLocalAssetPath($image_url);
		}

		private function getLocalImageSize($image_url) {
			$local_path = $this->getLocalImagePath($image_url);
			if (!$local_path) {
				return false;
			}

			$size = @getimagesize($local_path);
			return is_array($size) ? $size : false;
		}

		private function getImageDimensionsForPlaceholder($image_url) {
			$dimensions = $this->getImageDimensionsFromResizedInfos($image_url);
			if ($dimensions) {
				return $dimensions;
			}

			$size = $this->getLocalImageSize($image_url);
			if ($size) {
				return array($size[0], $size[1]);
			}

			return false;
		}

		private function shouldUseImageDimensionsForLazyloadPlaceholder($image_config) {
			return isset($image_config['use_image_dimensions_for_lazyload_placeholder']) && $image_config['use_image_dimensions_for_lazyload_placeholder'];
		}

		private function replaceOrAddImageDimensionAttr($image, $attr, $value) {
			$value = absint($value);
			if ($value <= 0) {
				return $image;
			}

			if (ALHelper::safePregMatch('#\s' . preg_quote($attr, '#') . '\s*=\s*"[^"]*"#i', $image, $matches) ||
				ALHelper::safePregMatch("#\s" . preg_quote($attr, '#') . "\s*=\s*'[^']*'#i", $image, $matches)) {
				return ALHelper::safeStrReplace($matches[0], ' ' . $attr . '="' . $value . '"', $image);
			}

			return ALHelper::safeStrReplace('<img', '<img ' . $attr . '="' . $value . '"', $image);
		}

		private function applyImageDimensions($image, $width, $height) {
			$image = $this->replaceOrAddImageDimensionAttr($image, 'width', $width);
			return $this->replaceOrAddImageDimensionAttr($image, 'height', $height);
		}

		private function getImageDimensionsForLazyloadSvg($image, $image_url) {
			$width = $this->imageWidth($image);
			$height = $this->imageHeight($image);
			if ($width > 0 || $height > 0) {
				return array($width, $height, false);
			}

			$dimensions = $this->getImageDimensionsForPlaceholder($image_url);
			if ($dimensions) {
				return array($dimensions[0], $dimensions[1], true);
			}

			return array(0, 0, false);
		}

		public function getSvgSource($width = 0, $height = 0) {
			$width = absint($width);
			$height = absint($height);
			$intrinsic_dimensions = ($width > 0 && $height > 0) ? " width='$width' height='$height'" : '';
			$new_src = ALHelper::safeStrReplace( ' ', '%20', "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 $width $height'$intrinsic_dimensions%3E%3C/svg%3E" );
			return $new_src;
		}

		public function getCleanUrl($url) {
			$clean_url = html_entity_decode($url, ENT_QUOTES|ENT_HTML5);
			$clean_url = strip_tags($clean_url, '\'" ');
			$clean_url = trim($clean_url, "\"' ");
			$clean_url = esc_url($clean_url);
			return $clean_url;
		}

		public function lazyloadTagImages($buffer, $image_config) {
			if (isset($image_config['html_tag_bg_style_lazyload'])) {
				$html_tags_with_styles = preg_match_all('#<(?<tag>div|figure|section|span|li|a|article|label)\s+(?<before>[^>]+[\'"\s])?style\s*=\s*([\'"])(?<styles>.*?)\3(?<after>[^>]*)>#is', $buffer, $html_elements, PREG_SET_ORDER);
				if($html_tags_with_styles) {
					foreach($html_elements as $html_element) {
						$tag_with_bg_img = ALHelper::safePregMatch('#background-image\s*:.*(?<attr>\s*url\s*\((?<url>[^)]+)\))\s*;?#is', $html_element['styles'], $url);
						if ($tag_with_bg_img) {
							if ($this->hasExcludedAttributes($html_element[0])) {
								continue;
							}

							if (isset($image_config['images_not_to_lazyload']) && is_array($image_config['images_not_to_lazyload'])) {
								foreach ( $image_config['images_not_to_lazyload'] as $image_not_to_lazyload) {
									$image_not_to_lazyload = $this->getImageCdnUrl($image_not_to_lazyload, $image_config['replace_urls']);
									if (strpos($url['url'], $image_not_to_lazyload) !== false) {
										continue 2;
									}
								}
							}

							$url['orig_url'] = $url['url'];
							$url['url'] = $this->getCleanUrl($url['url']);
							$lazy_tag = $this->addBvClass($html_element[0], 'bv-lazyload-bg-style');
							$img_size = false;

							if (!empty($url['url'])) {
								if ($this->shouldUseImageDimensionsForLazyloadPlaceholder($image_config)) {
									$img_size =  $this->getImageDimensionsForPlaceholder($url['url']);
								} else {
									$img_size = $this->getLocalImageSize($url['url']);
								}
							}

							if (isset($img_size) && is_array($img_size)) {
								$svg_url = $this->getSvgSource($img_size[0], $img_size[1]);
							} else {
								$svg_url = $this->getSvgSource(0, 0);
							}
							$new_styles = ALHelper::safeStrReplace($url['orig_url'], esc_attr($svg_url), $html_element['styles']);
							$lazy_tag = ALHelper::safeStrReplace($html_element['styles'], $new_styles, $lazy_tag);
							$lazy_tag = ALHelper::safeStrReplace('<' . $html_element['tag'], '<' . $html_element['tag'] . ' bv-data-style="' . esc_attr($html_element['styles']) . '" ', $lazy_tag);
							$buffer = ALHelper::safeStrReplace($html_element[0], $lazy_tag, $buffer);
						}
					}
				}
			}
			return $buffer;
		}

		public function addLazyloadClassToPicture($element) {
			ALHelper::safePregMatch('#<picture.*>#Uis', $element, $open_tag);
			$new_open_tag = $open_tag[0];
			$lazyload_class = "bv-lazyload-picture";
			$element_with_class = ALHelper::safePregMatch('#\sclass\s*=\s*(?<classes>["\'].*?["\']|[^\s]+)#is', $open_tag[0], $class_attr);
			if (!$element_with_class || empty($class_attr)) {
				$new_open_tag = ALHelper::safeStrReplace('<picture', '<picture class="' . $lazyload_class . '"', $new_open_tag);
			} else {
				$classes = trim($class_attr['classes'], '\'" ');
				if (empty($classes)) {
					$new_open_tag =  ALHelper::safeStrReplace($class_attr[0], ' class="' . $lazyload_class . '" ', $new_open_tag);
				} else {
					$classes .= ' ' . $lazyload_class;
					$new_open_tag = ALHelper::safeStrReplace($class_attr[0], ' class="' . $classes . '" ', $new_open_tag);
				}
			}
			return ALHelper::safeStrReplace($open_tag[0], $new_open_tag, $element);
		}

		public function addBvClass($element, $lazyload_class) {
			$element_with_class = ALHelper::safePregMatch('#\sclass\s*=\s*(?<classes>["\'].*?["\']|[^\s]+)#is', $element, $class_attr);
			if (!$element_with_class || empty($class_attr)) {
				return ALHelper::safePregReplace('#<(img|div|figure|section|li|span|a|iframe)([^>]*)>#is', '<\1 class="' . $lazyload_class . '"\2>', $element);
			}

			$classes = trim($class_attr['classes'], '\'" ');
			if (empty($classes)) {
				return ALHelper::safeStrReplace($class_attr[0], ' class="' . $lazyload_class . '" ', $element);
			}

			$classes .= ' ' . $lazyload_class;
			return ALHelper::safeStrReplace($class_attr[0], ' class="' . $classes . '" ', $element);
		}

		public function lazyloadImage($image, $image_config, $add_bv_class = true) {
			$final_image = $image;
			if ($this->canLazyload($image, $image_config)) {
				if (ALHelper::safePregMatch('#\ssrc\s*=\s*(\'|")(?<src>.*)\1#Uis', $image, $matches)) {
					if (!empty($matches['src'])) {
						$dimensions = $this->getImageDimensionsForLazyloadSvg($image, $matches['src']);
						$width = $dimensions[0];
						$height = $dimensions[1];
						if ($dimensions[2] && $this->shouldUseImageDimensionsForLazyloadPlaceholder($image_config)) {
							$final_image = $this->applyImageDimensions($final_image, $width, $height);
						}

						if ($add_bv_class) {
							$final_image = $this->addBvClass($final_image, 'bv-lazyload-tag-img');
						}
						$final_image = ALHelper::safeStrReplace('<img', '<img bv-data-src="' . esc_attr($matches['src']) . '" ', $final_image);
						$final_image = ALHelper::safeStrReplace($matches[0], ' src="' . $this->getSvgSource($width, $height) . '"', $final_image);
					}
				}
				if (ALHelper::safePregMatch('#\ssrcset\s*=\s*(\'|")(?<srcset>.*)\1#Uis', $image, $srcset)) {
					if (!empty($srcset['srcset'])) {
						$final_image = ALHelper::safeStrReplace($srcset[0], ' bv-data-srcset="' . $srcset['srcset'] . '" ', $final_image);
					}
				}
			}
			return $final_image;
		}

		public function get_src_image_url($image_tag) {
			if (ALHelper::safePregMatch('#\ssrc\s*=\s*(\'|")(?<src>.*)\1#Uis', $image_tag, $attrs)) {
				return trim($attrs['src']);
			}
			return "";
		}

		public function normalizeImageUrlForViewportMatch($url) {
			$clean_url = $this->getCleanUrl($url);

			if ($clean_url === '') {
				return '';
			}

			$url_parts = explode('?', $clean_url, 2);
			if (!empty($url_parts[0])) {
				return $url_parts[0];
			}

			return $clean_url;
		}

		public function getMatchedViewportImageUrl($image, $image_config) {
			if (!isset($image_config['viewport_images_info']) || !is_array($image_config['viewport_images_info'])) {
				return false;
			}

			$img_src = $this->get_src_image_url($image);
			if (empty($img_src)) {
				return false;
			}

			$normalized_img_src = $this->normalizeImageUrlForViewportMatch($img_src);
			if ($normalized_img_src === '' || strpos($normalized_img_src, 'data:image') === 0) {
				return false;
			}

			foreach ($image_config['viewport_images_info'] as $device => $viewport_images) {
				if (!is_array($viewport_images)) {
					continue;
				}

				foreach ($viewport_images as $viewport_image_url => $info) {
					$normalized_viewport_image_url = $this->normalizeImageUrlForViewportMatch($viewport_image_url);
					if ($normalized_viewport_image_url === '') {
						continue;
					}

					if ($normalized_img_src === $normalized_viewport_image_url) {
						return $viewport_image_url;
					}
				}
			}

			return false;
		}

		public function isViewportImage($image, $image_config) {
			return (bool) $this->getMatchedViewportImageUrl($image, $image_config);
		}

		private function getViewportImageHydrationAttrs($image_url, $image_config) {
			foreach ($image_config['viewport_images_info'] as $device => $viewport_images) {
				if (!is_array($viewport_images) || !isset($viewport_images[$image_url]) || !is_array($viewport_images[$image_url])) {
					continue;
				}

				$attrs_to_add = isset($viewport_images[$image_url]['attrs_to_add']) ? $viewport_images[$image_url]['attrs_to_add'] : false;
				if (!$attrs_to_add || !is_array($attrs_to_add)) {
					continue;
				}

				return $attrs_to_add;
			}

			return array();
		}

		public function hydrateViewportImage($image, $image_config) {
			if (empty($image)) {
				return $image;
			}

			if (strpos($image, 'bv-data-mobile-src=') !== false || strpos($image, 'bv-data-large-src=') !== false) {
				return $this->addBvClass($image, 'bv-viewport-img');
			}

			ALHelper::safePregMatch('#\ssrc\s*=\s*(\'|")(?<src>.*)\1#Uis', $image, $matches);

			$src = trim($matches['src']);
			if ($src === '' || strpos($src, 'data:image') === 0) {
				return $image;
			}

			$matched_viewport_image_url = $this->getMatchedViewportImageUrl($image, $image_config);
			if (!$matched_viewport_image_url) {
				return $image;
			}

			$viewport_hydration_attrs = $this->getViewportImageHydrationAttrs($matched_viewport_image_url, $image_config);
			if (empty($viewport_hydration_attrs)) {
				return $image;
			}

			$final_image = $this->addBvClass($image, 'bv-viewport-img');

			$dimensions = $this->getImageDimensionsForLazyloadSvg($final_image, $matched_viewport_image_url);
			$width = $dimensions[0];
			$height = $dimensions[1];
			$attr_string = '';
			foreach ($viewport_hydration_attrs as $attr_name => $attr_value) {
				if (empty($attr_value)) {
					continue;
				}
				$attr_string .= ' ' . $attr_name . '="' . esc_attr($attr_value) . '"';
			}

			if ($attr_string === '') {
				return $image;
			}

			$final_image = ALHelper::safeStrReplace('<img', '<img' . $attr_string . ' ', $final_image);
			$final_image = ALHelper::safeStrReplace($matches[0], ' src="' . $this->getSvgSource($width, $height) . '"', $final_image);

			if (ALHelper::safePregMatch('#\ssrcset\s*=\s*(\'|")(?<srcset>.*)\1#Uis', $final_image, $srcset)) {
				$final_image = ALHelper::safeStrReplace($srcset[0], '', $final_image);
			}

			return $final_image;
		}

		private function addImageAttr($image, $image_config) {
			$img_src = $this->get_src_image_url($image);
			if (!empty($img_src)) {
				if (isset($image_config['viewport_images_info']['mobile'][$img_src])) {
					$height = $image_config['viewport_images_info']['mobile'][$img_src]['height'];
					$width = $image_config['viewport_images_info']['mobile'][$img_src]['width'];
					if (isset($height) && isset($width)) {
						if (ALHelper::safePregMatch('#\sstyle\s*=\s*(?<styles>("|\').*\2)#is', $image, $style)) {
							$new_style = trim($style['styles'], '\'" ');
							if (strpos($new_style, "min-width") === false && strpos($new_style, "min-height") === false) {
								$new_style = "min-width:$width" . "px;" . " min-height:$height" . "px; " . $new_style;
								$image = ALHelper::safeStrReplace($style[0], 'style="' . $new_style . '"', $image);
							}
						}
						else {
							$image = ALHelper::safeStrReplace('<img', '<img style="min-height:' . $height . 'px; min-width:' . $width . 'px;"', $image);
						}
					}
				}
			}
			return $image;

		}
		public function handleImgTags($buffer, $image_config) {
			if (isset($image_config['image_dimension_replacements']) && is_array($image_config['image_dimension_replacements'])) {
				$buffer = $this->doSearchAndReplace($buffer, $image_config['image_dimension_replacements']);
			}
			if (isset($image_config['picture_tags']) && is_array($image_config['picture_tags'])) {
				$buffer = $this->doSearchAndReplace($buffer, $image_config['picture_tags']);
			}
			$img_buffer_sanitizer_regexes = $this->config['img_buffer_sanitizer_regexes'];
			if (isset($img_buffer_sanitizer_regexes) && is_array($img_buffer_sanitizer_regexes)) {
				$cleaned_buffer = $this->cleanBuffer($this->config['img_buffer_sanitizer_regexes'], $buffer);
			} else {
				$cleaned_buffer = $buffer;
			}
			$html_without_picture_tag = preg_replace('/<picture[^>]*>.*?<\/picture\s*>/mis', '', $cleaned_buffer);
			if (preg_match_all('#<img\s.+\s?/?>#Uis', $html_without_picture_tag, $images, PREG_SET_ORDER)) {
				foreach ($images as $image) {
					if ($this->hasNoActionAttribute($image[0])) continue;
					$final_image = $image;
					$lazyload_image = true;
					$is_viewport_hydration_image = false;
					if (array_key_exists('replace_images', $image_config) && !empty($image_config['replace_images'])) {
						$final_image[0] = $this->doSearchAndReplace($final_image[0], $image_config['replace_images']);
						if ($final_image[0] !== $image[0]) $lazyload_image = false;
					}
					if (array_key_exists('viewport_images_info', $image_config) && array_key_exists('add_image_attributes', $image_config) && $image_config['add_image_attributes']) {
						$final_image[0] = $this->addImageAttr($final_image[0], $image_config);
					}
					if (isset($image_config['use_viewport_image_hydration']) && $image_config['use_viewport_image_hydration'] && $this->isViewportImage($final_image[0], $image_config)) {
						$final_image[0] = $this->hydrateViewportImage($final_image[0], $image_config);
						$lazyload_image = false;
						$is_viewport_hydration_image = true;
					}
					if (!$is_viewport_hydration_image) {
						$final_image[0] = $this->replaceSrcAndSrcsetImages($final_image[0], $image_config['replace_urls'], 'bv-tag-attr-replace');
						if ($lazyload_image && $this->config["lazyload_non_viewport_img_tags"]) {
							$final_image[0] = $this->lazyloadImage($final_image[0], $image_config);
						}
					}
					$buffer = ALHelper::safeStrReplace($image[0], $final_image[0], $buffer);
				}
			}
			return $buffer;
		}

		public function replaceStyleAttributeImages($element, $replace_urls, $tag) {
			if (ALHelper::safePregMatch('#\sstyle\s*=\s*(\'|").*?\1#is', $element, $styles)) {
				if (ALHelper::safePregMatch('#background-image\s*:.*(?<attr>\s*url\s*\((?<url>[^)]+)\))\s*;?#is', $styles[0], $url)) {
					$element = $this->addBvClass($element, 'bv-style-attr-replace');
					$url['url'] = $this->getCleanUrl($url['url']);
					$cdn_image_url = $this->getImageCdnUrl($url['url'], $replace_urls);
					$element = ALHelper::safeStrReplace('<' . $tag, '<' . $tag . ' bv-style-url=' . '"' . $cdn_image_url . '" ', $element);
					$element = ALHelper::safeStrReplace($url['url'], $cdn_image_url, $element);
				}
			}
			return $element;
		}

		public function handleOtherTagImages($buffer, $image_config) {
			if (preg_match_all('/(<(?<tag_name>div|figure|section|span|li|a|article|label)\s+([^>]+[\'"\s])?(style\s*=\s*([\'\"])(.*?)\4)?([^>]*)>)/',$buffer, $elements, PREG_SET_ORDER)) {
				foreach ($elements as $element) {
					$final_element = $element;
					if ($this->hasEmbeddedImage($element[0])) continue;
					$final_element[0] = $this->replaceStyleAttributeImages($final_element[0], $image_config['replace_urls'], $element["tag_name"]);
					$final_element[0] = $this->replaceSrcAndSrcsetImages($final_element[0], $image_config['replace_urls'], 'bv-tag-attr-replace');
					$buffer = ALHelper::safeStrReplace($element[0], $final_element[0], $buffer);
				}
			}
			$buffer = $this->lazyloadTagImages($buffer, $image_config);
			return $buffer;
		}

		public function handleImages($buffer, $image_config, $url_matched, $can_preload_images = null) {
			$can_preload_images = $can_preload_images === null ? $url_matched : $can_preload_images;
			$default_image_handler_configured = ALImageOptimizationHandler::canHandle($this->config, $image_config);
			$handler_image_config = $image_config;
			if (!$can_preload_images && is_array($handler_image_config) && isset($handler_image_config['default_image_preload_rules'])) {
				unset($handler_image_config['default_image_preload_rules']);
			}

			if ($default_image_handler_configured) {
				if (!ALImageOptimizationHandler::canHandle($this->config, $handler_image_config)) {
					return $buffer;
				}
				if (isset($handler_image_config['css_preload_images']) && is_array($handler_image_config['css_preload_images'])) {
					$buffer = $this->preloadImages($buffer, $handler_image_config['css_preload_images']);
				}
				$image_optimization_handler = new ALImageOptimizationHandler($this->config, $handler_image_config, $can_preload_images);
				$optimized_buffer = $image_optimization_handler->optimize($buffer);
				if (!is_string($optimized_buffer) || ($buffer !== '' && $optimized_buffer === '')) {
					return $buffer;
				}
				return $optimized_buffer;
			}
			if (!is_array($image_config) || empty($image_config)) {
				return $buffer;
			}
			$buffer = $this->handlePictureTags($buffer, $image_config, $url_matched);
			$buffer = $this->handleImgTags($buffer, $image_config);
			if (isset($this->config["optimize_embed_style_tag_images"]) && $this->config["optimize_embed_style_tag_images"]) {
				$buffer = $this->handleOtherTagImages($buffer, $image_config);
			}

			if ($url_matched && isset($image_config['preload_images']) && is_array($image_config['preload_images'])) {
				$buffer = $this->preloadImages($buffer, $image_config['preload_images']);
			}
			return $buffer;
		}

		private function isInlineScript($script) {
			if (preg_match('/<script\b([^>]*)>/i', $script, $matches)) {
				return !preg_match('/\bsrc\s*=/i', $matches[1]);
			}
			return false;
		}
	}
endif;
