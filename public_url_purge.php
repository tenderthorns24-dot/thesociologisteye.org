<?php

if (!defined('ABSPATH')) exit;
if (!class_exists('ALPublicUrlPurge')) :

class ALPublicUrlPurge {
	const EVENT_TYPE = 'airlift';
	const EVENT_TAG = 'public_url_purge';

	private $settings;
	private $db;
	private $api;
	private $config;
	private $pending_urls = array();
	private $old_post_urls = array();
	private $old_term_urls = array();

	public function __construct($settings, $db, $api) {
		$this->settings = $settings;
		$this->db = $db;
		$this->api = $api;
		$cache_config = $settings->getOption('alcacheconfig');
		$this->config = is_array($cache_config) && isset($cache_config['public_urls']) && is_array($cache_config['public_urls']) ?
			$cache_config['public_urls'] : array();
	}

	public function registerHooks() {
		if (!$this->anyPurgeEnabled()) {
			return;
		}

		add_action('pre_post_update', array($this, 'captureOldPostUrls'), 10, 2);
		add_action('save_post', array($this, 'schedulePost'), 100, 3);
		add_action('transition_post_status', array($this, 'schedulePostTransition'), 100, 3);
		add_action('trashed_post', array($this, 'schedulePost'), 10, 1);
		add_action('before_delete_post', array($this, 'scheduleDeletedPost'), 10, 1);
		add_action('set_object_terms', array($this, 'schedulePostTerms'), 100, 6);

		add_action('edit_terms', array($this, 'captureOldTermUrl'), 10, 2);
		add_action('pre_delete_term', array($this, 'captureOldTermUrl'), 10, 2);
		add_action('created_term', array($this, 'scheduleTerm'), 100, 3);
		add_action('edited_term', array($this, 'scheduleTerm'), 100, 3);
		add_action('delete_term', array($this, 'scheduleDeletedTerm'), 100, 4);

		add_action('update_option_page_on_front', array($this, 'scheduleFrontPageChange'), 100, 3);
		add_action('update_option_page_for_posts', array($this, 'scheduleFrontPageChange'), 100, 3);
		add_action('update_option_show_on_front', array($this, 'scheduleFrontPageChange'), 100, 3);
		add_action('shutdown', array($this, 'flush'), 1);
	}

	public function captureOldPostUrls($post_id, $data = array()) {
		$this->old_post_urls[(int) $post_id] = $this->urlsForPost(get_post($post_id));
	}

	public function schedulePost($post_id, $post = null, $update = false) {
		$post_id = (int) $post_id;
		if ($post_id <= 0 || $this->isRevisionOrAutosave($post_id)) {
			return;
		}
		$this->queueUrls(isset($this->old_post_urls[$post_id]) ? $this->old_post_urls[$post_id] : array());
		$this->queueUrls($this->urlsForPost($post ? $post : get_post($post_id)));
	}

	public function schedulePostTransition($new_status, $old_status, $post) {
		if ($new_status !== $old_status && is_object($post) && isset($post->ID)) {
			$this->schedulePost($post->ID, $post, true);
		}
	}

	public function scheduleDeletedPost($post_id) {
		$this->captureOldPostUrls($post_id);
		$this->schedulePost($post_id);
	}

	public function schedulePostTerms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
		if (!$this->allowedTaxonomy($taxonomy)) {
			return;
		}
		foreach ((array) $old_tt_ids as $term_taxonomy_id) {
			$term = get_term_by('term_taxonomy_id', (int) $term_taxonomy_id, $taxonomy);
			if ($term) {
				$this->queueUrls($this->urlsForTerm($term, $taxonomy));
			}
		}
		$this->schedulePost($object_id);
	}

	public function captureOldTermUrl($term_id, $taxonomy) {
		$key = $taxonomy . ':' . (int) $term_id;
		$this->old_term_urls[$key] = $this->urlsForTerm($term_id, $taxonomy);
	}

	public function scheduleTerm($term_id, $tt_id, $taxonomy) {
		if (!$this->allowedTaxonomy($taxonomy)) {
			return;
		}
		$key = $taxonomy . ':' . (int) $term_id;
		$this->queueUrls(isset($this->old_term_urls[$key]) ? $this->old_term_urls[$key] : array());
		$this->queueUrls($this->urlsForTerm($term_id, $taxonomy));
		$this->queueSharedUrls();
	}

	public function scheduleDeletedTerm($term_id, $tt_id, $taxonomy, $deleted_term) {
		if (!$this->allowedTaxonomy($taxonomy)) {
			return;
		}
		$key = $taxonomy . ':' . (int) $term_id;
		$this->queueUrls(isset($this->old_term_urls[$key]) ? $this->old_term_urls[$key] : array());
		$this->queueSharedUrls();
	}

	public function scheduleFrontPageChange($old_value, $value, $option) {
		$this->queueUrl(home_url('/'));
		foreach (array($old_value, $value) as $post_id) {
			$post_id = (int) $post_id;
			$post = $post_id > 0 ? get_post($post_id) : null;
			if ($this->publicPost($post)) {
				$this->queueUrl(get_permalink($post));
			}
		}
	}

	public function flush() {
		$urls = array_values($this->pending_urls);
		$this->pending_urls = array();
		if (empty($urls)) {
			return;
		}

		$event_id = $this->eventId();
		if ($this->enabled('watch_purge')) {
			$this->storeWatchEvent($event_id, $urls);
		}

		$executor = null;
		if ($this->enabled('local_purge') || $this->enabled('varnish_purge')) {
			$executor = $this->speedExecutor();
		}
		if ($executor && $this->enabled('local_purge')) {
			foreach ($urls as $url) {
				$executor->clearCacheUrl($url);
			}
		}
		if ($executor && $this->enabled('varnish_purge')) {
			$host = isset($this->config['purge_host']) && is_string($this->config['purge_host']) ? $this->config['purge_host'] : '';
			$executor->clearPublicUrlsHostCache($host, $urls);
		}

		if ($this->enabled('direct_purge')) {
			$this->api->pingbv('/plugin/api/public_url_purges', array(
				'event_id' => $event_id,
				'urls' => $urls,
			));
		}
	}

	public static function currentPublicUrlIdentity() {
		if (function_exists('is_front_page') && is_front_page()) {
			return self::identity('front_page', get_queried_object_id());
		}
		if (function_exists('is_home') && is_home()) {
			return self::identity('posts_page', (int) get_option('page_for_posts'));
		}
		if (function_exists('is_singular') && is_singular()) {
			$post = get_queried_object();
			if (is_object($post) && isset($post->ID, $post->post_type)) {
				return self::identity('post', $post->ID, $post->post_type);
			}
		}
		if (function_exists('is_tax') && (is_tax() || is_category() || is_tag())) {
			$term = get_queried_object();
			if (is_object($term) && isset($term->term_id, $term->taxonomy)) {
				return self::identity('term', $term->term_id, $term->taxonomy);
			}
		}
		if (function_exists('is_post_type_archive') && is_post_type_archive()) {
			$post_type = get_query_var('post_type');
			$post_type = is_array($post_type) ? reset($post_type) : $post_type;
			return self::identity('post_type_archive', null, $post_type);
		}
		if (function_exists('is_author') && is_author()) {
			return self::identity('author', get_queried_object_id());
		}
		if (function_exists('is_search') && is_search()) {
			return self::identity('search');
		}
		if (function_exists('is_date') && is_date()) {
			return self::identity('date');
		}
		return self::identity('other');
	}

	private static function identity($kind, $id = null, $subtype = null) {
		$identity = array('kind' => $kind);
		if ((int) $id > 0) {
			$identity['id'] = (int) $id;
		}
		if (is_string($subtype) && $subtype !== '') {
			$identity['subtype'] = sanitize_key($subtype);
		}
		return $identity;
	}

	private function urlsForPost($post) {
		if (!$this->publicPost($post)) {
			return array();
		}

		$urls = array(get_permalink($post));
		$post_type = $post->post_type;
		$archive_url = get_post_type_archive_link($post_type);
		if ($archive_url) {
			$urls[] = $archive_url;
		}
		if ($post_type === 'product' && function_exists('wc_get_page_id')) {
			$shop_page_id = (int) wc_get_page_id('shop');
			$shop_page = $shop_page_id > 0 ? get_post($shop_page_id) : null;
			if ($this->publicPost($shop_page)) {
				$urls[] = get_permalink($shop_page);
			}
		}

		foreach ($this->allowedTaxonomies() as $taxonomy) {
			$terms = wp_get_post_terms($post->ID, $taxonomy);
			if (is_wp_error($terms)) {
				continue;
			}
			foreach ($terms as $term) {
				$urls = array_merge($urls, $this->urlsForTerm($term, $taxonomy));
			}
		}
		$this->queueSharedUrls($urls);
		return $urls;
	}

	private function urlsForTerm($term, $taxonomy) {
		if (!$this->allowedTaxonomy($taxonomy) || !$this->publicTaxonomy($taxonomy)) {
			return array();
		}
		$link = get_term_link($term, $taxonomy);
		return is_wp_error($link) ? array() : array($link);
	}

	private function queueSharedUrls(&$urls = null) {
		$shared = array(home_url('/'));
		$posts_page_id = (int) get_option('page_for_posts');
		$posts_page = $posts_page_id > 0 ? get_post($posts_page_id) : null;
		if ($this->publicPost($posts_page)) {
			$shared[] = get_permalink($posts_page);
		}
		if (is_array($urls)) {
			$urls = array_merge($urls, $shared);
			return;
		}
		$this->queueUrls($shared);
	}

	private function publicPost($post) {
		if (!is_object($post) || !isset($post->ID, $post->post_type) || !$this->allowedPostType($post->post_type)) {
			return false;
		}
		if ($this->isRevisionOrAutosave($post->ID)) {
			return false;
		}
		if (function_exists('is_post_publicly_viewable')) {
			return is_post_publicly_viewable($post);
		}
		$type = get_post_type_object($post->post_type);
		return $post->post_status === 'publish' && $type && !empty($type->publicly_queryable);
	}

	private function publicTaxonomy($taxonomy) {
		$object = get_taxonomy($taxonomy);
		if (!$object) {
			return false;
		}
		if (function_exists('is_taxonomy_viewable')) {
			return is_taxonomy_viewable($object);
		}
		return !empty($object->publicly_queryable) || !empty($object->public);
	}

	private function allowedPostType($post_type) {
		$types = isset($this->config['template_post_types']) && is_array($this->config['template_post_types']) ?
			$this->config['template_post_types'] : array();
		return in_array($post_type, $types, true);
	}

	private function allowedTaxonomy($taxonomy) {
		return in_array($taxonomy, $this->allowedTaxonomies(), true);
	}

	private function allowedTaxonomies() {
		return isset($this->config['template_taxonomies']) && is_array($this->config['template_taxonomies']) ?
			$this->config['template_taxonomies'] : array();
	}

	private function isRevisionOrAutosave($post_id) {
		return (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) ||
			(function_exists('wp_is_post_autosave') && wp_is_post_autosave($post_id));
	}

	private function queueUrls($urls) {
		foreach ((array) $urls as $url) {
			$this->queueUrl($url);
		}
	}

	private function queueUrl($url) {
		$url = $this->sameSiteUrl($url);
		if ($url !== '') {
			$this->pending_urls[$url] = $url;
		}
	}

	private function sameSiteUrl($url) {
		if (!is_string($url) || $url === '') {
			return '';
		}
		$parsed = wp_parse_url($url);
		$home = wp_parse_url(home_url('/'));
		if (!is_array($parsed) || !is_array($home) || empty($parsed['host']) || empty($home['host'])) {
			return '';
		}
		$host = preg_replace('/^www\./i', '', strtolower($parsed['host']));
		$home_host = preg_replace('/^www\./i', '', strtolower($home['host']));
		if ($host !== $home_host || !isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), array('http', 'https'), true)) {
			return '';
		}
		$path = isset($parsed['path']) ? $parsed['path'] : '';
		$query = isset($parsed['query']) ? $parsed['query'] : '';
		if (!$this->isSafeUrlPath($path) || !$this->isSafeUrlPath($query)) {
			return '';
		}
		return preg_replace('/#.*$/', '', $url);
	}

	private function isSafeUrlPath($value) {
		if (!is_string($value)) {
			return false;
		}
		$value = rawurldecode($value);
		return strpos($value, "\0") === false && strpos($value, '\\') === false &&
			preg_match('/[\x00-\x1F\x7F]/', $value) !== 1 && !in_array('..', explode('/', $value), true);
	}

	private function storeWatchEvent($event_id, $urls) {
		$table = $this->db->getBVTable('dynamic_sync');
		if (!$this->db->isTablePresent($table)) {
			return false;
		}
		return $this->db->replaceIntoBVTable('dynamic_sync', array(
			'site_id' => get_current_blog_id(),
			'event_type' => self::EVENT_TYPE,
			'event_tag' => self::EVENT_TAG,
			'event_data' => maybe_serialize(array('event_id' => $event_id, 'urls' => $urls)),
		));
	}

	private function speedExecutor() {
		try {
			require_once dirname(__FILE__) . '/callback/base.php';
			require_once dirname(__FILE__) . '/callback/wings/speed.php';
			$handler = new stdClass();
			$handler->settings = $this->settings;
			$handler->db = $this->db;
			return new ALSpeedCallback($handler);
		} catch (Throwable $e) {
			return false;
		}
	}

	private function eventId() {
		$uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : sha1(uniqid('', true));
		return 'airlift-' . $uuid;
	}

	private function enabled($flag) {
		return isset($this->config[$flag]) && $this->config[$flag] === true;
	}

	private function anyPurgeEnabled() {
		return $this->enabled('local_purge') || $this->enabled('varnish_purge') ||
			$this->enabled('direct_purge') || $this->enabled('watch_purge');
	}
}
endif;
