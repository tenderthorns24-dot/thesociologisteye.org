<?php
if (!defined('ABSPATH')) exit;
if (!class_exists('ALWPCache')) :
class ALWPCache {
	public static $so_cache_path =  WP_CONTENT_DIR . '/cache/airlift';
	public static $cache_path =  WP_CONTENT_DIR . '/cache';

	private static function getConfigFilePath() {
		if (ALWPFileSystem::getInstance()->isWritable(ABSPATH . 'wp-config.php') === true) {
			return ABSPATH . 'wp-config.php';
		}
		if ((ALWPFileSystem::getInstance()->exists(dirname(ABSPATH) . '/wp-config.php')) === true 
				&& (ALWPFileSystem::getInstance()->exists(dirname(ABSPATH) . '/wp-settings.php')) === false) {
			return dirname(ABSPATH) . '/wp-config.php';
		}
		return false;
	}

	public static function enableCache($info) {
		$resp = array();
		$resp['wp_config_updated'] = self::updateWpConfig('true', $info);
		$resp['advanced_cache_updated'] = self::copyAdvancedCacheFile();
		return $resp;
	}

	public static function disableCache($info) {
		$resp = array();
		ALWPFileSystem::getInstance()->rmdir(self::$so_cache_path, true);
		$resp['wp_config_updated'] = self::updateWpConfig('false', $info);
		$resp['advanced_cache_updated'] = self::removeAdvancedCacheFileContent();
		return $resp;
	}

	public static function updateWpConfig($value, $info) {
		$config_file_path = self::getConfigFilePath();

		if (!$config_file_path) {
			return false;
		}

		$content = ALWPFileSystem::getInstance()->getContents($config_file_path);

		$is_cache_present = ALHelper::safePregMatch('/^\s*define\(\s*\'WP_CACHE\'\s*,\s*(?<value>[^\s\)]*)\s*\)/m', $content, $current_value);
		if (isset($current_value['value']) && !empty($current_value['value']) && $value === $current_value['value']) {
			return true;
		}
		$constant = "define('WP_CACHE', {$value} ); // Added by {$info->brandname}";
		if (!$is_cache_present) {
			$config_content = preg_replace("/(<\?php)/i", "<?php\r\n{$constant}\r\n", $content);
		} elseif ( isset($current_value['value']) && !empty($current_value['value']) && $current_value['value'] !== $value) {
			$config_content = preg_replace("/^\s*define\(\s*\'WP_CACHE\'\s*,\s*([^\s\)]*)\s*\).+/m", $constant, $content);
		}
		$chmod = ALWPFileSystem::getInstance()->getchmodOctal($config_file_path);
		if (ALWPFileSystem::getInstance()->putContents($config_file_path, $config_content, $chmod) === true) {
			return true;
		}
		return false;
	}

	public static function copyAdvancedCacheFile() {
		$contents = ALWPFileSystem::getInstance()->getContents(WP_CONTENT_DIR . '/plugins/airlift/buffer/advanced-cache.php');
		if (ALWPFileSystem::getInstance()->putContents(WP_CONTENT_DIR . "/advanced-cache.php", $contents) === true) {
			return true;
		}
		return false;
	}

	public static function removeAdvancedCacheFileContent() {
		if (ALWPFileSystem::getInstance()->exists(WP_CONTENT_DIR . "/advanced-cache.php") === false) {
			return true;
		}
		if (ALWPFileSystem::getInstance()->putContents(WP_CONTENT_DIR . "/advanced-cache.php", "") === true) {
			return true;
		}
		return false;
	}
}

endif;