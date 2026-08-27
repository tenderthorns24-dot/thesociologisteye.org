<section id="al-show-connection-key" style="padding: 30px 0;">
	<div style="max-width: 760px; margin: auto; padding: 20px; box-sizing: border-box; box-shadow: 2px 2px 9px rgb(212 212 212), 0 0 9px rgb(212 212 212); border-radius: 11px; background: #fff;">
		<h4 class="text-left" style="margin-top: 0;">
			<span>Connection Key</span>
		</h4>
		<div style="margin-bottom: 12px;">
			Use this key on your AirLift dashboard to connect this site.
		</div>
		<div style="display: flex; gap: 8px; align-items: center;">
			<input
				type="password"
				id="al-connection-key"
				name="connection_key"
				value="<?php echo esc_attr($this->bvinfo->getConnectionKey()); ?>"
				class="widefat"
				style="flex: 1;"
				readonly
			>
			<button type="button" id="al-view-connection-key" class="button">View Key</button>
			<button type="button" id="al-copy-connection-key" class="button">Copy Key</button>
		</div>
	</div>
</section>
