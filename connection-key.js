(function() {
	document.addEventListener('DOMContentLoaded', function() {
		var keyField = document.getElementById('al-connection-key');
		var viewButton = document.getElementById('al-view-connection-key');
		var copyButton = document.getElementById('al-copy-connection-key');
		if (!keyField || !viewButton || !copyButton) {
			return;
		}

		viewButton.addEventListener('click', function() {
			if (keyField.type === 'password') {
				keyField.type = 'text';
				viewButton.textContent = 'Hide Key';
			} else {
				keyField.type = 'password';
				viewButton.textContent = 'View Key';
			}
		});

		copyButton.addEventListener('click', function() {
			var previousType = keyField.type;
			keyField.type = 'text';

			var updateCopyState = function() {
				copyButton.textContent = 'Copied!';
				setTimeout(function() {
					copyButton.textContent = 'Copy Key';
				}, 2000);
			};

			var restoreField = function() {
				keyField.type = previousType;
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(keyField.value).then(function() {
					updateCopyState();
				}).finally(function() {
					restoreField();
				});
				return;
			}

			keyField.select();
			document.execCommand('copy');
			updateCopyState();
			restoreField();
		});
	});
})();
