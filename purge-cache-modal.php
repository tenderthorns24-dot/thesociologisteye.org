<?php if (!defined('ABSPATH')) exit; ?>
<div id="al-purge-cache-modal" class="al-purge-cache-modal" hidden>
	<div class="al-purge-cache-backdrop" data-al-purge-close="1"></div>
	<div class="al-purge-cache-dialog" role="dialog" aria-modal="true" aria-labelledby="al-purge-cache-title">
		<h2 id="al-purge-cache-title"><?php echo esc_html__('Purge URLs', 'airlift'); ?></h2>
		<label for="al-purge-cache-urls"><?php echo esc_html__('Enter one URL or path per line', 'airlift'); ?></label>
		<textarea id="al-purge-cache-urls" rows="7" maxlength="<?php echo esc_attr($max_purge_urls_input_length); ?>" placeholder="/pricing/&#10;/product/shoes/&#10;/product/*"></textarea>
		<p class="description"><?php echo esc_html__('Supported: same-site full URLs, paths, and trailing prefix wildcards like /product/*.', 'airlift'); ?></p>
		<p id="al-purge-cache-error" class="al-purge-cache-error" hidden></p>
		<div class="al-purge-cache-actions">
			<button type="button" class="al-purge-cache-button al-purge-cache-button-secondary" data-al-purge-close="1"><?php echo esc_html__('Cancel', 'airlift'); ?></button>
			<button type="button" class="al-purge-cache-button al-purge-cache-button-primary" id="al-purge-cache-submit"><?php echo esc_html__('Purge URLs', 'airlift'); ?></button>
		</div>
	</div>
</div>
<div id="al-purge-confirm-modal" class="al-purge-cache-modal al-purge-confirm-modal" hidden>
	<div class="al-purge-cache-backdrop" data-al-purge-confirm-close="1"></div>
	<div class="al-purge-cache-dialog" role="dialog" aria-modal="true" aria-labelledby="al-purge-confirm-title">
		<h2 id="al-purge-confirm-title"><?php echo esc_html__('Confirm Cache Purge', 'airlift'); ?></h2>
		<p id="al-purge-confirm-message" class="al-purge-cache-message"></p>
		<div class="al-purge-cache-actions">
			<button type="button" class="al-purge-cache-button al-purge-cache-button-secondary" data-al-purge-confirm-close="1"><?php echo esc_html__('Cancel', 'airlift'); ?></button>
			<button type="button" class="al-purge-cache-button al-purge-cache-button-primary" id="al-purge-confirm-submit"><?php echo esc_html__('Purge', 'airlift'); ?></button>
		</div>
	</div>
</div>
<div id="al-purge-message-modal" class="al-purge-cache-modal al-purge-message-modal" hidden>
	<div class="al-purge-cache-backdrop" data-al-purge-message-close="1"></div>
	<div class="al-purge-cache-dialog" role="dialog" aria-modal="true" aria-labelledby="al-purge-message-title">
		<h2 id="al-purge-message-title"><?php echo esc_html__('Cache Purge', 'airlift'); ?></h2>
		<p id="al-purge-message-text" class="al-purge-cache-message"></p>
		<div class="al-purge-cache-actions">
			<button type="button" class="al-purge-cache-button al-purge-cache-button-primary" id="al-purge-message-close" data-al-purge-message-close="1"><?php echo esc_html__('OK', 'airlift'); ?></button>
		</div>
	</div>
</div>
