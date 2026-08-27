(function () {
	var config = window.ALPurgeCacheTools || {};
	var modal = null;
	var confirmModal = null;
	var messageModal = null;
	var textarea = null;
	var submitButton = null;
	var errorNode = null;
	var confirmTitleNode = null;
	var confirmMessageNode = null;
	var confirmButton = null;
	var messageTitleNode = null;
	var messageTextNode = null;
	var messageCloseButton = null;
	var pendingPurge = null;

	function showUrlError(message) {
		if (modal) {
			modal.hidden = false;
		}
		if (!errorNode) {
			showMessage(message, true);
			return;
		}
		errorNode.textContent = message;
		errorNode.hidden = false;
	}

	function clearError() {
		if (errorNode) {
			errorNode.textContent = '';
			errorNode.hidden = true;
		}
	}

	function openModal() {
		if (!modal) {
			return;
		}
		clearError();
		modal.hidden = false;
		if (textarea) {
			textarea.focus();
		}
	}

	function closeModal() {
		if (modal) {
			modal.hidden = true;
		}
	}

	function closeConfirmModal() {
		if (confirmModal) {
			confirmModal.hidden = true;
		}
		pendingPurge = null;
	}

	function closeMessageModal() {
		if (messageModal) {
			messageModal.hidden = true;
		}
	}

	function confirmationCopy(options) {
		if (options.scope === 'full') {
			return {
				title: 'Purge All Cache?',
				message: 'This will purge the full AirLift cache for this site.',
				button: 'Purge All Cache'
			};
		}
		if (options.source === 'current') {
			return {
				title: 'Purge This Page?',
				message: 'This will purge the cached version of the current page.',
				button: 'Purge This Page'
			};
		}
		var lines = String(options.urls || '').split(/\r\n|\r|\n/).filter(function (line) {
			return line.trim() !== '';
		});
		return {
			title: 'Purge URLs?',
			message: 'This will purge cache for ' + lines.length + ' URL' + (lines.length === 1 ? '' : 's') + ' or path' + (lines.length === 1 ? '' : 's') + '.',
			button: 'Purge URLs'
		};
	}

	function openConfirmModal(options) {
		pendingPurge = options;
		var copy = confirmationCopy(options);
		if (!confirmModal) {
			if (window.confirm(copy.message)) {
				performPurge(options);
			}
			return;
		}
		if (confirmTitleNode) {
			confirmTitleNode.textContent = copy.title;
		}
		if (confirmMessageNode) {
			confirmMessageNode.textContent = copy.message;
		}
		if (confirmButton) {
			confirmButton.disabled = false;
			confirmButton.textContent = copy.button;
		}
		confirmModal.hidden = false;
		if (confirmButton) {
			confirmButton.focus();
		}
	}

	function showMessage(message, isError) {
		if (!messageModal) {
			window.alert(message);
			return;
		}
		if (messageTitleNode) {
			messageTitleNode.textContent = isError ? 'Unable to Purge Cache' : 'Cache Purge Started';
		}
		if (messageTextNode) {
			messageTextNode.textContent = message;
		}
		messageModal.hidden = false;
		if (messageCloseButton) {
			messageCloseButton.focus();
		}
	}

	function setWorking(isWorking) {
		if (submitButton) {
			submitButton.disabled = isWorking;
			submitButton.textContent = isWorking ? (config.workingMessage || 'Purging cache...') : (config.submitLabel || 'Purge URLs');
		}
		if (confirmButton) {
			confirmButton.disabled = isWorking;
			if (isWorking) {
				confirmButton.textContent = config.workingMessage || 'Purging cache...';
			}
		}
	}

	function requestPurge(options) {
		options = options || {};
		var scope = options.scope || 'urls';
		var urls = String(options.urls !== undefined ? options.urls : (textarea ? textarea.value : '')).trim();
		if (scope === 'urls' && !urls) {
			showUrlError(config.emptyMessage || 'Enter at least one URL or path.');
			return;
		}
		if (scope === 'urls' && urls.length > Number(config.maxInputLength || 65535)) {
			showUrlError(config.maxInputLengthMessage || 'The URL list is too large. Please enter fewer URLs.');
			return;
		}
		clearError();
		openConfirmModal({ scope: scope, urls: urls, source: options.source || '' });
	}

	function performPurge(options) {
		options = options || {};
		var scope = options.scope || 'urls';
		var urls = String(options.urls || '').trim();
		setWorking(true);
		var formData = new window.FormData();
		formData.append('action', 'al_purge_urls');
		formData.append('nonce', config.nonce || '');
		formData.append('scope', scope);
		if (scope === 'urls') {
			formData.append('urls', urls);
		}

		var xhr = new window.XMLHttpRequest();
		xhr.open('POST', config.ajaxUrl, true);
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) {
				return;
			}
			setWorking(false);
			var response = {};
			try {
				response = JSON.parse(xhr.responseText || '{}');
			} catch (e) {
				response = {};
			}
			var message = response.data && response.data.message ? response.data.message : null;
			closeConfirmModal();
			if (xhr.status >= 200 && xhr.status < 300 && response.success) {
				closeModal();
				showMessage(message || config.successMessage || 'Cache purge has been initiated.', false);
			} else {
				showMessage(message || config.errorMessage || 'Unable to purge cache. Please try again.', true);
			}
		};
		xhr.send(formData);
	}

	document.addEventListener('DOMContentLoaded', function () {
		modal = document.getElementById('al-purge-cache-modal');
		confirmModal = document.getElementById('al-purge-confirm-modal');
		messageModal = document.getElementById('al-purge-message-modal');
		textarea = document.getElementById('al-purge-cache-urls');
		submitButton = document.getElementById('al-purge-cache-submit');
		errorNode = document.getElementById('al-purge-cache-error');
		confirmTitleNode = document.getElementById('al-purge-confirm-title');
		confirmMessageNode = document.getElementById('al-purge-confirm-message');
		confirmButton = document.getElementById('al-purge-confirm-submit');
		messageTitleNode = document.getElementById('al-purge-message-title');
		messageTextNode = document.getElementById('al-purge-message-text');
		messageCloseButton = document.getElementById('al-purge-message-close');

		var urlsLink = document.querySelector('#wp-admin-bar-al-purge-urls a');
		if (urlsLink) {
			urlsLink.addEventListener('click', function (event) {
				event.preventDefault();
				openModal();
			});
		}

		var currentUrlLink = document.querySelector('#wp-admin-bar-al-purge-current-url a');
		if (currentUrlLink) {
			currentUrlLink.addEventListener('click', function (event) {
				event.preventDefault();
				requestPurge({ scope: 'urls', urls: config.currentUrl || window.location.href, source: 'current' });
			});
		}

		var allCacheLink = document.querySelector('#wp-admin-bar-al-purge-all-cache a');
		if (allCacheLink) {
			allCacheLink.addEventListener('click', function (event) {
				event.preventDefault();
				requestPurge({ scope: 'full' });
			});
		}

		document.querySelectorAll('[data-al-purge-close]').forEach(function (node) {
			node.addEventListener('click', closeModal);
		});

		document.querySelectorAll('[data-al-purge-confirm-close]').forEach(function (node) {
			node.addEventListener('click', closeConfirmModal);
		});

		document.querySelectorAll('[data-al-purge-message-close]').forEach(function (node) {
			node.addEventListener('click', closeMessageModal);
		});

		if (confirmButton) {
			confirmButton.addEventListener('click', function () {
				if (pendingPurge) {
					performPurge(pendingPurge);
				}
			});
		}

		if (submitButton) {
			submitButton.addEventListener('click', function () {
				requestPurge();
			});
		}
	});
}());
