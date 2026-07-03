/**
 * SiteGuard Backup - Restore Progress Handler
 *
 * @deprecated UNUSED. window.SGBRestore is never invoked by the plugin and this
 * file has drifted from the authoritative implementation. The live restore flow
 * (streaming XHR, connection-loss handling, resume, progress rendering) lives in
 * js/admin.js -> SGB.doRestore(). Do NOT add features here. This file is kept
 * only so the existing wp_enqueue_script handle (owned by admin.php) does not
 * 404; remove the enqueue and this file together in a future cleanup.
 *
 * Handles streaming XHR for restore progress display.
 */
(function($) {
	'use strict';

	window.SGBRestore = {
		/**
		 * Start a streaming restore request.
		 *
		 * @param {number} timestamp - Backup timestamp.
		 * @param {object} options   - Restore options (which entities, encryption key).
		 * @param {string} logSelector - jQuery selector for the log container.
		 * @param {function} onComplete - Callback when restore finishes.
		 */
		start: function(timestamp, options, logSelector, onComplete) {
			var $log = $(logSelector);
			$log.empty();

			var formData = new FormData();
			formData.append('action', 'sgbackup_restore');
			formData.append('nonce', (sgbackup_settings.nonces && sgbackup_settings.nonces.restore) || sgbackup_settings.nonce);
			formData.append('timestamp', timestamp);

			if (options.db) formData.append('restore_db', '1');
			if (options.plugins) formData.append('restore_plugins', '1');
			if (options.themes) formData.append('restore_themes', '1');
			if (options.uploads) formData.append('restore_uploads', '1');
			if (options.others) formData.append('restore_others', '1');
			if (options.wpcore) formData.append('restore_wpcore', '1');
			if (options.encryption_key) formData.append('encryption_key', options.encryption_key);

			var xhr = new XMLHttpRequest();
			xhr.open('POST', sgbackup_settings.ajax_url, true);
			var lastIndex = 0;

			this.appendLog($log, 'Starting restore...', 'info');

			xhr.onprogress = function() {
				var newData = xhr.responseText.substring(lastIndex);
				lastIndex = xhr.responseText.length;

				var lines = newData.split('\n');
				for (var i = 0; i < lines.length; i++) {
					var line = lines[i].trim();
					if (!line) continue;

					if (line.indexOf('RINFO:') === 0) {
						try {
							var info = JSON.parse(line.substring(6));
							SGBRestore.handleMessage(info, $log, onComplete);
						} catch (e) {
							// Non-JSON line, show as-is
							SGBRestore.appendLog($log, line, 'info');
						}
					}
				}
			};

			xhr.onerror = function() {
				SGBRestore.appendLog($log, 'Connection error during restore.', 'error');
				if (onComplete) onComplete(false);
			};

			xhr.onload = function() {
				if (lastIndex < xhr.responseText.length) {
					// Process remaining data
					xhr.onprogress();
				}
			};

			xhr.send(formData);
		},

		/**
		 * Handle a parsed RINFO message.
		 */
		handleMessage: function(info, $log, onComplete) {
			switch (info.type) {
				case 'progress':
					this.appendLog($log, info.message, 'info');
					break;

				case 'warning':
					this.appendLog($log, 'Warning: ' + info.message, 'warning');
					break;

				case 'error':
					this.appendLog($log, 'Error: ' + info.message, 'error');
					break;

				case 'complete':
					if (info.success) {
						this.appendLog($log, 'Restore completed successfully!', 'success');
					} else {
						this.appendLog($log, 'Restore finished with errors.', 'error');
					}

					if (info.messages) {
						for (var i = 0; i < info.messages.length; i++) {
							this.appendLog($log, info.messages[i], 'info');
						}
					}

					if (info.errors) {
						for (var j = 0; j < info.errors.length; j++) {
							this.appendLog($log, info.errors[j], 'error');
						}
					}

					if (onComplete) onComplete(info.success);
					break;
			}
		},

		/**
		 * Append a log entry to the log container.
		 */
		appendLog: function($log, message, type) {
			var cls = '';
			switch (type) {
				case 'error': cls = 'restore-error'; break;
				case 'success': cls = 'restore-success'; break;
				case 'warning': cls = 'restore-warning'; break;
			}

			var time = new Date().toLocaleTimeString();
			$log.append('<div class="' + cls + '">[' + time + '] ' + this.escapeHtml(message) + '</div>');
			$log.scrollTop($log[0].scrollHeight);
		},

		/**
		 * Escape HTML entities.
		 */
		escapeHtml: function(str) {
			var div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		}
	};

})(jQuery);
