/**
 * SiteGuard Backup - Admin JavaScript
 */
(function($) {
	'use strict';

	var SGB = {
		pollTimer: null,

		// HTML escape utility to prevent XSS
		esc: function(str) {
			var div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		},

		init: function() {
			this.bindTabs();
			this.bindBackupNow();
			this.bindRestore();
			this.bindDelete();
			this.bindLog();
			this.bindKeep();
			this.bindVerify();
			this.bindSelectiveRestore();
			this.bindSettings();
			this.bindUpload();
			this.bindMigration();
			this.bindStorageConfig();
			this.bindExternalDbs();
			this.bindAdvanced();
			this.bindMassActions();
			this.bindPagination();
			this.bindUploadToRemote();
			this.bindRescan();
			this.bindSiteSize();
			this.bindDbSize();
			this.bindCronEvents();
			this.bindExportImportSettings();
			this.pollActiveJobs();
		},

		// ===== AJAX Helper =====
		ajax: function(subaction, data, callback, errorCallback) {
			$.ajax({
				url: sgbackup_settings.ajax_url,
				type: 'POST',
				data: {
					action: 'sgbackup_ajax',
					subaction: subaction,
					nonce: sgbackup_settings.nonce,
					data: JSON.stringify(data || {})
				},
				success: function(response) {
					if (response.success) {
						if (callback) callback(response.data);
					} else {
						var msg = response.data && response.data.message ? response.data.message : sgbackup_settings.strings.error;
						if (errorCallback) {
							errorCallback(msg);
						} else {
							alert(msg);
						}
					}
				},
				error: function(xhr) {
					var msg = sgbackup_settings.strings.error;
					if (errorCallback) errorCallback(msg);
					else alert(msg);
				}
			});
		},

		// ===== Tabs =====
		bindTabs: function() {
			$('.sgbackup-tabs .nav-tab').on('click', function(e) {
				e.preventDefault();
				var tab = $(this).data('tab');
				$('.sgbackup-tabs .nav-tab').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');
				$('.sgbackup-tab-content').hide();
				$('#tab-' + tab).show();
			});
		},

		// ===== Backup Now =====
		bindBackupNow: function() {
			var self = this;

			$('#sgbackup-backupnow-btn').on('click', function() {
				$('#sgbackup-backupnow-modal').dialog({
					modal: true,
					width: 480,
					buttons: [
						{
							text: sgbackup_settings.strings.backup_started.replace('...', ''),
							class: 'button button-primary',
							click: function() {
								self.doBackupNow();
								$(this).dialog('close');
							}
						},
						{
							text: 'Cancel',
							class: 'button',
							click: function() { $(this).dialog('close'); }
						}
					]
				});
			});

			// Toggle entity checkboxes
			$('input[name="sgbackup_include_files"]').on('change', function() {
				$('#sgbackup-entity-checkboxes').toggle(this.checked);
			});
		},

		doBackupNow: function() {
			var modal = $('#sgbackup-backupnow-modal');
			var params = {};
			var includeFiles = $('input[name="sgbackup_include_files"]').is(':checked');
			var includeDb = $('input[name="sgbackup_include_db"]').is(':checked');

			if (!includeFiles) params.nofiles = true;
			if (!includeDb) params.nodb = true;

			if (includeFiles) {
				params.entities = [];
				$('input[name="sgbackup_entity[]"]:checked').each(function() {
					params.entities.push($(this).val());
				});
			}

			if ($('input[name="sgbackup_incremental"]').is(':checked')) {
				params.incremental = true;
			}

			if ($('input[name="sgbackup_always_keep"]').is(':checked')) {
				params.always_keep = true;
			}

			// H4: Backup label
			params.label = modal.find('[name="sgbackup_label"]').val();

			// H6: Per-table selection
			if (includeDb) {
				var selectedTables = [];
				$('#sgbackup-table-list input[name="sgbackup_include_tables[]"]:checked').each(function() {
					selectedTables.push($(this).val());
				});
				// Only pass include_tables if the user explicitly selected tables (table list was shown)
				if ($('#sgbackup-table-list').is(':visible') && selectedTables.length > 0) {
					params.include_tables = selectedTables;
				}
				if ($('input[name="sgbackup_include_nonwp_tables"]').is(':checked')) {
					params.include_nonwp_tables = true;
				}

				// M5: Database anonymisation
				if ($('input[name="sgbackup_anonymise"]').is(':checked')) {
					params.anonymise = true;
					params.anonymise_mode = $('input[name="sgbackup_anonymise_mode"]:checked').val() || 'all';
				}
			}

			var sendTo = $('#sgbackup-send-to-select').val();
			if (sendTo) {
				params.service = [sendTo];
			}

			$('#sgbackup-backup-status').text(sgbackup_settings.strings.backup_started).removeClass('success error');

			this.ajax('backupnow', params, function(data) {
				$('#sgbackup-backup-status').html(
					sgbackup_settings.strings.backup_started +
					' <span class="sgbackup-spinner"></span> Job: ' + data.nonce
				);
			});

			this.pollActiveJobs();
		},

		// ===== Active Jobs Polling =====
		pollActiveJobs: function() {
			var self = this;
			if (this.pollTimer) clearInterval(this.pollTimer);

			this.pollTimer = setInterval(function() {
				self.ajax('activejobs_list', {}, function(data) {
					if ($.isEmptyObject(data)) {
						$('#sgbackup-active-jobs').hide();
						$('#sgbackup-active-jobs-list').empty();
						$('#sgbackup-backup-status').text('').removeClass('success error');
						clearInterval(self.pollTimer);
						self.pollTimer = null;
						self.refreshBackupsList();
						return;
					}

					$('#sgbackup-active-jobs').show();
					var html = '';
					$.each(data, function(nonce, job) {
						var phases = [];
						if (job.backup_files === 'yes') {
							phases.push('Files: ' + (job.files_phase_complete ? 'Done' : 'In progress'));
						}
						if (job.backup_database === 'yes') {
							phases.push('DB: ' + (job.db_phase_complete ? 'Done' : (job.files_phase_complete ? 'In progress' : 'Pending')));
						}
						if (job.upload_phase_complete) phases.push('Upload: Done');

						html += '<div class="sgbackup-job">';
						html += '<button type="button" class="sgbackup-abort-btn" data-nonce="' + SGB.esc(nonce) + '">Abort</button>';
						html += '<div class="sgbackup-job-title">' + SGB.esc(job.job_type) + ' backup [' + SGB.esc(nonce) + ']</div>';
						html += '<div class="sgbackup-job-progress">Status: ' + SGB.esc(job.jobstatus) + ' | Resumption: ' + SGB.esc(String(job.resumption)) + '</div>';
						html += '<div class="sgbackup-job-progress">' + SGB.esc(phases.join(' | ')) + '</div>';
						html += '</div>';
					});
					$('#sgbackup-active-jobs-list').html(html);

					// Bind abort buttons
					$('.sgbackup-abort-btn').off('click').on('click', function() {
						var nonce = $(this).data('nonce');
						self.ajax('activejobs_delete', { job_id: nonce });
					});
				});
			}, 5000);
		},

		refreshBackupsList: function() {
			// Reload the page section
			this.ajax('get_existing_backups', {}, function(data) {
				// Rebuild table via page reload for simplicity
				location.reload();
			});
		},

		// ===== Restore =====
		bindRestore: function() {
			var self = this;
			$(document).on('click', '.sgbackup-restore-btn', function() {
				var timestamp = $(this).data('timestamp');
				if (!confirm(sgbackup_settings.strings.confirm_restore)) return;

				$('#sgbackup-restore-modal').data('timestamp', timestamp);
				$('#sgbackup-restore-options').show();
				$('#sgbackup-restore-verify').hide();
				$('#sgbackup-restore-progress').hide();
				$('#sgbackup-restore-log').empty();

				$('#sgbackup-restore-modal').dialog({
					modal: true,
					width: 600,
					buttons: [
						{
							text: 'Next',
							class: 'button button-primary sgbackup-restore-next-btn',
							click: function() {
								self.showRestoreVerification(timestamp);
							}
						},
						{
							text: 'Cancel',
							class: 'button',
							click: function() { $(this).dialog('close'); }
						}
					]
				});
			});

			// Gap 24: Verification stage - Proceed button
			$(document).on('click', '#sgbackup-verify-proceed', function() {
				var timestamp = $('#sgbackup-restore-modal').data('timestamp');
				self.doRestore(timestamp);
			});

			// Gap 24: Verification stage - Back button
			$(document).on('click', '#sgbackup-verify-back', function() {
				$('#sgbackup-restore-verify').hide();
				$('#sgbackup-restore-options').show();
				// Show the Next button again, hide it if dialog buttons are used
				$('.sgbackup-restore-next-btn').show();
			});
		},

		/**
		 * Gap 24: Show the verification stage between selection and restore.
		 * Analyzes the backup and displays warnings before proceeding.
		 */
		showRestoreVerification: function(timestamp) {
			var self = this;

			$('#sgbackup-restore-options').hide();
			$('#sgbackup-restore-verify').show();
			$('#sgbackup-verify-info').html('<p><em>Analyzing backup...</em></p>');
			$('#sgbackup-verify-warnings').hide().empty();
			$('.sgbackup-restore-next-btn').hide();

			// Fetch backup contents for analysis
			self.ajax('get_backup_contents', { timestamp: timestamp }, function(data) {
				var html = '';
				var warnings = [];

				// Show backup metadata
				html += '<table class="widefat" style="margin-bottom:10px;">';
				html += '<tbody>';
				if (data.timestamp) {
					var date = new Date(data.timestamp * 1000);
					html += '<tr><td><strong>Backup date:</strong></td><td>' + SGB.esc(date.toLocaleString()) + '</td></tr>';
				}
				if (data.size) {
					html += '<tr><td><strong>Backup size:</strong></td><td>' + SGB.esc(data.size) + '</td></tr>';
				}

				// Show entities available
				var entities = [];
				if (data.tables && data.tables.length) entities.push('Database (' + data.tables.length + ' tables)');
				if (data.plugins && data.plugins.length) entities.push('Plugins (' + data.plugins.length + ')');
				if (data.themes && data.themes.length) entities.push('Themes (' + data.themes.length + ')');
				if (entities.length) {
					html += '<tr><td><strong>Contents:</strong></td><td>' + SGB.esc(entities.join(', ')) + '</td></tr>';
				}

				// Detect migration (different siteurl)
				if (data.old_siteurl && data.current_siteurl && data.old_siteurl !== data.current_siteurl) {
					html += '<tr><td><strong>Migration:</strong></td><td>';
					html += SGB.esc(data.old_siteurl) + ' &rarr; ' + SGB.esc(data.current_siteurl);
					html += '</td></tr>';

					// HTTPS/HTTP transition warning
					var oldScheme = data.old_siteurl.split('://')[0];
					var newScheme = data.current_siteurl.split('://')[0];
					if (oldScheme !== newScheme) {
						if (oldScheme === 'http' && newScheme === 'https') {
							warnings.push('Protocol change detected: HTTP to HTTPS. Mixed content issues may occur after restore.');
						} else if (oldScheme === 'https' && newScheme === 'http') {
							warnings.push('Security downgrade: HTTPS to HTTP. Data will be transmitted unencrypted.');
						}
					}
				}

				html += '</tbody></table>';

				// Check for .htaccess warning when restoring wpcore or others
				var restoringWpcore = $('input[name="sgbackup_restore_wpcore"]').is(':checked');
				var restoringOthers = $('input[name="sgbackup_restore_others"]').is(':checked');
				if (restoringWpcore || restoringOthers) {
					warnings.push('.htaccess may be overwritten. If you have custom rewrite rules, consider backing it up first.');
				}

				$('#sgbackup-verify-info').html(html);

				// Display warnings if any
				if (warnings.length) {
					var warnHtml = '<strong>Warnings:</strong><ul style="margin:5px 0 0 20px;">';
					for (var i = 0; i < warnings.length; i++) {
						warnHtml += '<li>' + SGB.esc(warnings[i]) + '</li>';
					}
					warnHtml += '</ul>';
					$('#sgbackup-verify-warnings').html(warnHtml).show();
				}
			}, function(msg) {
				// If analysis fails, still allow proceeding
				$('#sgbackup-verify-info').html(
					'<p>Could not fully analyze backup: ' + SGB.esc(msg) + '</p>' +
					'<p>You may still proceed with the restore.</p>'
				);
			});
		},

		doRestore: function(timestamp) {
			$('#sgbackup-restore-options').hide();
			$('#sgbackup-restore-verify').hide();
			$('#sgbackup-restore-progress').show();
			$('.sgbackup-restore-next-btn').hide();

			var formData = new FormData();
			formData.append('action', 'sgbackup_restore');
			formData.append('nonce', sgbackup_settings.nonce);
			formData.append('timestamp', timestamp);

			$('input[name^="sgbackup_restore_"]').each(function() {
				if ($(this).is(':checkbox')) {
					if ($(this).is(':checked')) {
						formData.append(this.name, '1');
					}
				} else {
					formData.append(this.name, $(this).val());
				}
			});

			var encKey = $('input[name="sgbackup_restore_enc_key"]').val();
			if (encKey) formData.append('encryption_key', encKey);

			// Gap 23: Collect move_in_mode
			var moveInMode = $('select[name="sgbackup_move_in_mode"]').val();
			formData.append('move_in_mode', moveInMode || '0');

			// Gap 25: Collect include-unspecified options
			formData.append('include_unspecified_tables', $('input[name="sgbackup_include_unspecified_tables"]').is(':checked') ? '1' : '0');
			formData.append('include_unspecified_plugins', $('input[name="sgbackup_include_unspecified_plugins"]').is(':checked') ? '1' : '0');
			formData.append('include_unspecified_themes', $('input[name="sgbackup_include_unspecified_themes"]').is(':checked') ? '1' : '0');

			// Selective restore: pass individual tables, plugins, themes if selected
			$('#sgbackup-selective-items input[name="restore_tables[]"]:checked').each(function() {
				formData.append('restore_tables[]', $(this).val());
			});
			$('#sgbackup-selective-items input[name="restore_plugins_items[]"]:checked').each(function() {
				formData.append('restore_plugins_items[]', $(this).val());
			});
			$('#sgbackup-selective-items input[name="restore_themes_items[]"]:checked').each(function() {
				formData.append('restore_themes_items[]', $(this).val());
			});

			// Streaming XHR
			var xhr = new XMLHttpRequest();
			xhr.open('POST', sgbackup_settings.ajax_url, true);
			var lastIndex = 0;

			xhr.onprogress = function() {
				var newData = xhr.responseText.substring(lastIndex);
				lastIndex = xhr.responseText.length;

				var lines = newData.split('\n');
				for (var i = 0; i < lines.length; i++) {
					if (lines[i].indexOf('RINFO:') === 0) {
						try {
							var info = JSON.parse(lines[i].substring(6));
							var cls = '';
							if (info.type === 'error') cls = 'restore-error';
							if (info.type === 'warning') cls = 'restore-warning';
							if (info.type === 'complete' && info.success) cls = 'restore-success';
							$('#sgbackup-restore-log').append(
								'<div class="' + cls + '">' + SGB.esc(info.message || '') + '</div>'
							);
							$('#sgbackup-restore-log').scrollTop($('#sgbackup-restore-log')[0].scrollHeight);

							if (info.type === 'complete') {
								if (info.success) {
									$('#sgbackup-restore-log').append('<div class="restore-success">Restore complete!</div>');
								} else {
									$('#sgbackup-restore-log').append('<div class="restore-error">Restore finished with errors.</div>');
									if (info.errors) {
										for (var e = 0; e < info.errors.length; e++) {
											$('#sgbackup-restore-log').append('<div class="restore-error">' + SGB.esc(info.errors[e]) + '</div>');
										}
									}
								}
							}
						} catch (e) { /* skip invalid JSON */ }
					}
				}
			};

			xhr.onerror = function() {
				$('#sgbackup-restore-log').append('<div class="restore-error">Connection error during restore.</div>');
			};

			xhr.onload = function() {
				if (lastIndex < xhr.responseText.length) {
					xhr.onprogress();
				}
			};

			xhr.send(formData);
		},

		// ===== Delete =====
		bindDelete: function() {
			var self = this;
			$(document).on('click', '.sgbackup-delete-btn', function() {
				if (!confirm(sgbackup_settings.strings.confirm_delete)) return;
				var timestamp = $(this).data('timestamp');
				var $row = $(this).closest('tr');

				self.ajax('delete_backup', { timestamp: timestamp }, function() {
					$row.fadeOut(300, function() { $(this).remove(); });
				});
			});
		},

		// ===== Log Viewer =====
		bindLog: function() {
			var self = this;
			$(document).on('click', '.sgbackup-log-btn', function() {
				var nonce = $(this).data('nonce');
				self.ajax('get_log', { nonce: nonce }, function(data) {
					var $dialog = $('<div title="Backup Log"><pre style="max-height:400px;overflow:auto;font-size:12px;white-space:pre-wrap;"></pre></div>');
					$dialog.find('pre').text(data.log || 'No log available.');
					$dialog.dialog({ modal: true, width: 700 });
				});
			});
		},

		// ===== Always Keep =====
		bindKeep: function() {
			var self = this;
			$(document).on('click', '.sgbackup-keep-btn', function() {
				var $btn = $(this);
				var timestamp = $btn.data('timestamp');
				var currentKeep = $btn.data('keep') === 1 || $btn.data('keep') === '1';

				self.ajax('always_keep_this_backup', {
					timestamp: timestamp,
					always_keep: !currentKeep
				}, function() {
					$btn.data('keep', currentKeep ? '0' : '1');
					$btn.text(currentKeep ? 'Keep' : 'Unkeep');
					var $star = $btn.closest('tr').find('.sgbackup-badge-keep');
					if (currentKeep) {
						$star.remove();
					} else {
						$btn.closest('tr').find('td:first').append(' <span class="sgbackup-badge sgbackup-badge-keep" title="Always keep">&#9733;</span>');
					}
				});
			});
		},

		// ===== Verify Backup =====
		bindVerify: function() {
			var self = this;
			$(document).on('click', '.sgbackup-verify-btn', function() {
				var $btn = $(this);
				var timestamp = $btn.data('timestamp');
				$btn.prop('disabled', true).text('Verifying...');

				self.ajax('verify_backup', { timestamp: timestamp }, function(data) {
					$btn.prop('disabled', false).text('Verify');
					var html = '<div class="sgbackup-verify-result">';
					html += '<h3>Verification Result</h3>';
					if (data.passed) {
						html += '<p class="sgbackup-verify-pass">All checks passed.</p>';
					} else {
						html += '<p class="sgbackup-verify-fail">Some checks failed.</p>';
					}
					if (data.checks && data.checks.length) {
						html += '<ul>';
						for (var i = 0; i < data.checks.length; i++) {
							var cls = data.checks[i].pass ? 'sgbackup-verify-item-pass' : 'sgbackup-verify-item-fail';
							html += '<li class="' + cls + '">' + SGB.esc(data.checks[i].label) + ': ' + SGB.esc(data.checks[i].status) + '</li>';
						}
						html += '</ul>';
					}
					html += '</div>';
					var $dialog = $('<div title="Verify Backup">' + html + '</div>');
					$dialog.dialog({ modal: true, width: 500 });
				}, function(msg) {
					$btn.prop('disabled', false).text('Verify');
					alert('Verification error: ' + msg);
				});
			});
		},

		// ===== Selective Restore =====
		bindSelectiveRestore: function() {
			var self = this;
			$(document).on('click', '#sgbackup-selective-restore-link', function(e) {
				e.preventDefault();
				var $container = $('#sgbackup-selective-items');
				if ($container.is(':visible')) {
					$container.hide();
					return;
				}
				$container.show().html('<p><em>Loading backup contents...</em></p>');

				var timestamp = $('#sgbackup-restore-modal').data('timestamp');
				self.ajax('get_backup_contents', { timestamp: timestamp }, function(data) {
					var html = '';

					// M8: Database tables with multisite blog grouping
					if (data.tables && data.tables.length) {
						html += '<h4>Database Tables</h4>';
						html += '<label class="sgbackup-select-all"><input type="checkbox" checked class="sgbackup-toggle-all" data-group="restore_tables" /> Select all</label>';

						// Check if multisite blog groups are provided
						if (data.table_groups && typeof data.table_groups === 'object') {
							html += '<div class="sgbackup-selective-group">';
							$.each(data.table_groups, function(blogId, group) {
								var groupLabel = group.label || ('Blog ' + blogId);
								html += '<div class="sgbackup-blog-group" style="margin:8px 0;padding:6px 10px;border:1px solid #ddd;border-radius:4px;">';
								html += '<label style="font-weight:bold;cursor:pointer;"><input type="checkbox" checked class="sgbackup-toggle-blog" data-blog-id="' + SGB.esc(blogId) + '" /> ';
								html += SGB.esc(groupLabel) + ' (' + group.tables.length + ' tables)</label>';
								html += '<div class="sgbackup-blog-tables" style="margin-left:20px;margin-top:4px;">';
								for (var t = 0; t < group.tables.length; t++) {
									html += '<label style="display:block;"><input type="checkbox" name="restore_tables[]" value="' + SGB.esc(group.tables[t]) + '" checked data-blog-id="' + SGB.esc(blogId) + '" /> ' + SGB.esc(group.tables[t]) + '</label>';
								}
								html += '</div></div>';
							});
							html += '</div>';
						} else {
							// Standard flat list for single-site
							html += '<div class="sgbackup-selective-group">';
							for (var i = 0; i < data.tables.length; i++) {
								html += '<label><input type="checkbox" name="restore_tables[]" value="' + SGB.esc(data.tables[i]) + '" checked /> ' + SGB.esc(data.tables[i]) + '</label><br/>';
							}
							html += '</div>';
						}
					}

					// Plugins
					if (data.plugins && data.plugins.length) {
						html += '<h4>Plugins</h4>';
						html += '<label class="sgbackup-select-all"><input type="checkbox" checked class="sgbackup-toggle-all" data-group="restore_plugins_items" /> Select all</label>';
						html += '<div class="sgbackup-selective-group">';
						for (var i = 0; i < data.plugins.length; i++) {
							html += '<label><input type="checkbox" name="restore_plugins_items[]" value="' + SGB.esc(data.plugins[i]) + '" checked /> ' + SGB.esc(data.plugins[i]) + '</label><br/>';
						}
						html += '</div>';
					}

					// Themes
					if (data.themes && data.themes.length) {
						html += '<h4>Themes</h4>';
						html += '<label class="sgbackup-select-all"><input type="checkbox" checked class="sgbackup-toggle-all" data-group="restore_themes_items" /> Select all</label>';
						html += '<div class="sgbackup-selective-group">';
						for (var i = 0; i < data.themes.length; i++) {
							html += '<label><input type="checkbox" name="restore_themes_items[]" value="' + SGB.esc(data.themes[i]) + '" checked /> ' + SGB.esc(data.themes[i]) + '</label><br/>';
						}
						html += '</div>';
					}

					if (!html) {
						html = '<p><em>No selectable items found in this backup.</em></p>';
					}

					$container.html(html);

					// Toggle all handler
					$container.find('.sgbackup-toggle-all').on('change', function() {
						var group = $(this).data('group');
						var checked = $(this).is(':checked');
						$container.find('input[name="' + group + '[]"]').prop('checked', checked);
						// Also sync blog-level toggles
						$container.find('.sgbackup-toggle-blog').prop('checked', checked);
					});

					// M8: Toggle all tables for a specific blog
					$container.find('.sgbackup-toggle-blog').on('change', function() {
						var blogId = $(this).data('blog-id');
						var checked = $(this).is(':checked');
						$container.find('input[name="restore_tables[]"][data-blog-id="' + blogId + '"]').prop('checked', checked);
					});
				}, function(msg) {
					$container.html('<p class="sgbackup-verify-fail">Error loading contents: ' + SGB.esc(msg) + '</p>');
				});
			});
		},

		// ===== Settings =====
		bindSettings: function() {
			var self = this;
			$('#sgbackup-save-settings').on('click', function() {
				var params = {};
				var $form = $('#sgbackup-settings-form');

				// Collect standard form fields (skip instance fields which are handled separately)
				$form.find('input, select, textarea').each(function() {
					var name = $(this).attr('name');
					if (!name) return;
					// Skip instance-based fields (collected separately below)
					if ($(this).hasClass('sgbackup-instance-field') || $(this).hasClass('sgbackup-instance-enabled')) return;
					// Skip retention rule fields (collected separately below)
					if ($(this).closest('.sgbackup-retention-rule').length) return;
					// M9: Skip extra directory inputs (collected separately below)
					if ($(this).hasClass('sgbackup-extra-dir-input')) return;

					if ($(this).is(':checkbox')) {
						if (name.indexOf('[]') > -1) {
							var key = name.replace('[]', '');
							if (!params[key]) params[key] = [];
							if ($(this).is(':checked')) params[key].push($(this).val());
						} else {
							params[name] = $(this).is(':checked') ? 1 : 0;
						}
					} else if ($(this).is('textarea') && name === 'exclude_files') {
						params[name] = $(this).val().split('\n').filter(function(l) { return l.trim(); });
					} else {
						params[name] = $(this).val();
					}
				});

				// Resolve day-of-week/month selects into their target fields
				// Only take the value from the visible selector
				$form.find('.sgbackup-day-of-week-select, .sgbackup-day-of-month-select').each(function() {
					var target = $(this).data('target');
					if (!target) return;
					if ($(this).is(':visible')) {
						params[target] = $(this).val();
					} else if (!(target in params)) {
						params[target] = '';
					}
				});
				// Clean up the temporary _dow/_dom keys
				delete params.schedule_day_dow;
				delete params.schedule_day_dom;
				delete params.schedule_day_db_dow;
				delete params.schedule_day_db_dom;

				// Collect multi-instance storage settings
				params.method_settings = {};
				$('.sgbackup-instance-container').each(function() {
					var method = $(this).data('method');
					if (!method) return;
					params.method_settings[method] = { settings: {} };

					$(this).find('.sgbackup-instance-panel').each(function() {
						var instanceId = $(this).data('instance');
						var instanceData = {};

						// Get label from the matching tab
						var $tab = $(this).closest('.sgbackup-instance-container')
							.find('.sgbackup-instance-tab[data-instance="' + instanceId + '"] .sgbackup-instance-label');
						if ($tab.length) {
							instanceData.instance_label = $tab.text().trim();
						}

						// Get enabled state
						var $enabled = $(this).find('.sgbackup-instance-enabled');
						instanceData.instance_enabled = $enabled.length ? ($enabled.is(':checked') ? '1' : '0') : '1';

						// Collect all instance fields
						$(this).find('.sgbackup-instance-field').each(function() {
							var key = $(this).data('key');
							if (!key) return;
							if ($(this).is(':checkbox')) {
								instanceData[key] = $(this).is(':checked') ? '1' : '0';
							} else if ($(this).is('textarea')) {
								instanceData[key] = $(this).val();
							} else {
								instanceData[key] = $(this).val();
							}
						});

						params.method_settings[method].settings[instanceId] = instanceData;
					});
				});

				// M3: Collect external databases
				params.extradbs = [];
				$('#sgbackup-extradbs-container .sgbackup-extradb-row').each(function() {
					var db = {};
					$(this).find('.sgbackup-extradb-field').each(function() {
						var key = $(this).data('key');
						if (key) db[key] = $(this).val();
					});
					if (db.host && db.name) {
						params.extradbs.push(db);
					}
				});

				// Collect tiered retention rules
				params.retain_extrarules = { files: [], db: [] };
				$('.sgbackup-retention-rules').each(function() {
					var type = $(this).data('type');
					$(this).find('.sgbackup-retention-rule').each(function() {
						var rule = {
							after_count: parseInt($(this).find('.sgbackup-rr-after-count').val(), 10) || 0,
							after_period: parseInt($(this).find('.sgbackup-rr-after-period').val(), 10) || 0,
							keep_every: parseInt($(this).find('.sgbackup-rr-keep-every').val(), 10) || 0
						};
						if (rule.after_count > 0 && rule.after_period > 0 && rule.keep_every > 0) {
							params.retain_extrarules[type].push(rule);
						}
					});
				});

				// M9: Collect extra directories
				params.extra_dirs = [];
				$('#sgbackup-extra-dirs .sgbackup-extra-dir-input').each(function() {
					var dir = $(this).val().trim();
					if (dir) {
						params.extra_dirs.push(dir);
					}
				});

				$('#sgbackup-settings-status').text('Saving...').removeClass('success error');

				self.ajax('savesettings', params, function() {
					$('#sgbackup-settings-status').text('Settings saved!').addClass('success');
					setTimeout(function() { $('#sgbackup-settings-status').text(''); }, 3000);
				}, function(msg) {
					$('#sgbackup-settings-status').text('Error: ' + msg).addClass('error');
				});
			});
		},

		// ===== Upload =====
		bindUpload: function() {
			var self = this;
			$('#sgbackup-upload-btn').on('click', function() {
				var files = $('#sgbackup-upload-input')[0].files;
				if (!files.length) return;

				var completed = 0;
				$('#sgbackup-upload-status').text(sgbackup_settings.strings.uploading);

				for (var i = 0; i < files.length; i++) {
					(function(file) {
						var formData = new FormData();
						formData.append('action', 'sgbackup_upload');
						formData.append('nonce', sgbackup_settings.nonce);
						formData.append('sgbackup_file', file);

						$.ajax({
							url: sgbackup_settings.ajax_url,
							type: 'POST',
							data: formData,
							processData: false,
							contentType: false,
							success: function(response) {
								completed++;
								if (completed === files.length) {
									// L7: Check if uploaded file looks like a foreign backup
									var fname = response.data ? response.data.filename : file.name;
									var isSgBackup = /^backup_\d{4}-\d{2}-\d{2}-\d{4}_[^_]+_[a-zA-Z0-9]+/.test(fname);
									if (!isSgBackup && fname) {
										var doImport = confirm('This file does not match the SiteGuard Backup naming pattern.\n\nWould you like to import it as a foreign backup?');
										if (doImport) {
											self.ajax('import_foreign_backup', { file: fname }, function(importData) {
												$('#sgbackup-upload-status').text('Imported as backup!').addClass('success');
												$('#sgbackup-upload-input').val('');
												setTimeout(function() { location.reload(); }, 1500);
											}, function(msg) {
												$('#sgbackup-upload-status').text('Import error: ' + msg).addClass('error');
											});
											return;
										}
									}
									$('#sgbackup-upload-status').text('Upload complete!').addClass('success');
									$('#sgbackup-upload-input').val('');
									setTimeout(function() { location.reload(); }, 1500);
								}
							},
							error: function() {
								completed++;
								$('#sgbackup-upload-status').text('Upload error for: ' + file.name).addClass('error');
							}
						});
					})(files[i]);
				}
			});
		},

		// ===== Migration =====
		bindMigration: function() {
			var self = this;

			// Create key
			$('#sgbackup-create-key-btn').on('click', function() {
				var name = $('#sgbackup-migrate-keyname').val() || 'default';
				var keySize = $('#sgbackup-migrate-keysize').val();

				$('#sgbackup-key-status').text('Generating key...').removeClass('success error');

				self.ajax('migrate_key_create', { name: name, key_size: keySize }, function(data) {
					$('#sgbackup-key-status').text('Key created!').addClass('success');
					$('#sgbackup-key-bundle').val(data.bundle);
					$('#sgbackup-key-result').show();
				}, function(msg) {
					$('#sgbackup-key-status').text('Error: ' + msg).addClass('error');
				});
			});

			// Copy key
			$('#sgbackup-copy-key').on('click', function() {
				$('#sgbackup-key-bundle').select();
				document.execCommand('copy');
				$(this).text('Copied!');
				setTimeout(function() { $('#sgbackup-copy-key').text('Copy to Clipboard'); }, 2000);
			});

			// Add site
			$('#sgbackup-add-site-btn').on('click', function() {
				var bundle = $('#sgbackup-paste-key').val().trim();
				if (!bundle) return;

				$('#sgbackup-addsite-status').text('Adding site...').removeClass('success error');

				self.ajax('migrate_newdestination', { key_bundle: bundle }, function(data) {
					$('#sgbackup-addsite-status').text('Site added: ' + data.name).addClass('success');
					$('#sgbackup-paste-key').val('');
					setTimeout(function() { location.reload(); }, 1500);
				}, function(msg) {
					$('#sgbackup-addsite-status').text('Error: ' + msg).addClass('error');
				});
			});

			// Ping test
			$(document).on('click', '.sgbackup-ping-site', function() {
				var siteId = $(this).data('site-id');
				var $btn = $(this);
				$btn.text('Testing...');

				self.ajax('ping_test', { site_id: siteId }, function() {
					$btn.text('OK!');
					setTimeout(function() { $btn.text('Test'); }, 2000);
				}, function(msg) {
					$btn.text('Failed');
					setTimeout(function() { $btn.text('Test'); }, 3000);
				});
			});

			// Send backup to site
			$(document).on('click', '.sgbackup-send-site', function() {
				var siteId = $(this).data('site-id');
				$('#sgbackup-send-options').show().data('site-id', siteId);
			});

			// M6: Clone to site (backup + send in one step)
			$(document).on('click', '.sgbackup-clone-site', function() {
				var siteId = $(this).data('site-id');
				var $btn = $(this);
				if (!confirm('Create a full backup and send it to this site?')) return;

				$btn.prop('disabled', true).text('Cloning...');

				self.ajax('clone_to_site', { site_id: siteId }, function(data) {
					$btn.prop('disabled', false).text('Clone to Site');
					$('#sgbackup-send-status').text('Clone started! Job: ' + (data.nonce || '')).addClass('success');
					self.pollActiveJobs();
				}, function(msg) {
					$btn.prop('disabled', false).text('Clone to Site');
					alert('Clone error: ' + msg);
				});
			});

			// Delete remote site
			$(document).on('click', '.sgbackup-delete-site', function() {
				if (!confirm('Delete this destination?')) return;
				var siteId = $(this).data('site-id');
				var $row = $(this).closest('tr');
				self.ajax('delete_remotesite', { site_id: siteId }, function() {
					$row.fadeOut(300, function() { $(this).remove(); });
				});
			});

			// Delete key
			$(document).on('click', '.sgbackup-delete-key', function() {
				if (!confirm('Delete this key?')) return;
				var keyId = $(this).data('key-id');
				var $row = $(this).closest('tr');
				self.ajax('delete_migrator_key', { key_id: keyId }, function() {
					$row.fadeOut(300, function() { $(this).remove(); });
				});
			});

			// Actual send
			$('#sgbackup-send-btn').on('click', function() {
				var siteId = $('#sgbackup-send-options').data('site-id');
				var sendType = $('input[name="sgbackup_send_type"]:checked').val();

				$('#sgbackup-send-status').text('Sending...').removeClass('success error');

				if (sendType === 'existing') {
					var timestamp = $('#sgbackup-send-existing-select').val();
					if (!timestamp) {
						$('#sgbackup-send-status').text('Select a backup first').addClass('error');
						return;
					}
					self.ajax('migrate_send', { site_id: siteId, timestamp: timestamp }, function(data) {
						$('#sgbackup-send-status').text('Send started! Job: ' + data.nonce).addClass('success');
					});
				} else {
					// Create new backup and send
					self.ajax('backupnow', { service: ['remotesend/' + siteId] }, function(data) {
						$('#sgbackup-send-status').text('Backup + send started! Job: ' + data.nonce).addClass('success');
					});
				}
			});
		},

		// ===== Storage Config =====
		bindStorageConfig: function() {
			var self = this;

			// Toggle storage method config panels
			$('.sgbackup-service-toggle').on('change', function() {
				var svc = $(this).data('service');
				var $config = $('#sgbackup-config-' + svc);
				if ($config.length) {
					$config.toggle(this.checked);
				}
			});

			// Test credentials
			$(document).on('click', '.sgbackup-test-credentials', function() {
				var $btn = $(this);
				var method = $btn.data('method');
				var $result = $btn.siblings('.sgbackup-test-result');
				$result.text('Testing...').removeClass('success error');

				self.ajax('credentials_test', { method: method }, function(data) {
					$result.text(data.message || 'Success!').addClass('success');
				}, function(msg) {
					$result.text('Error: ' + msg).addClass('error');
				});
			});

			// OAuth auth buttons
			$(document).on('click', '.sgbackup-auth-btn', function() {
				var method = $(this).data('method');
				self.ajax('get_auth_url', { method: method }, function(data) {
					if (data.auth_url) {
						window.open(data.auth_url, 'sgbackup_auth', 'width=600,height=700');
					}
				});
			});

			// H1: Multi-instance - Instance tab switching
			$(document).on('click', '.sgbackup-instance-tab', function() {
				var instanceId = $(this).data('instance');
				var $container = $(this).closest('.sgbackup-instance-container');
				$container.find('.sgbackup-instance-tab').removeClass('active');
				$(this).addClass('active');
				$container.find('.sgbackup-instance-panel').hide();
				$container.find('.sgbackup-instance-panel[data-instance="' + instanceId + '"]').show();
			});

			// Initialize: activate the first tab for each container
			$('.sgbackup-instance-container').each(function() {
				var $tabs = $(this).find('.sgbackup-instance-tab');
				var $panels = $(this).find('.sgbackup-instance-panel');
				if ($tabs.length > 0) {
					$tabs.first().addClass('active');
					$panels.hide();
					$panels.first().show();
				}
			});

			// H1: Add storage instance
			$(document).on('click', '.sgbackup-add-instance', function(e) {
				e.preventDefault();
				var method = $(this).data('method');
				self.addStorageInstance(method);
			});

			// H1: Delete storage instance
			$(document).on('click', '.sgbackup-delete-instance', function(e) {
				e.stopPropagation();
				var method = $(this).data('method');
				var instanceId = $(this).data('instance');
				self.deleteStorageInstance(method, instanceId);
			});

			// H2: Day-of-week/month selector based on interval
			$(document).on('change', '.sgbackup-interval-select', function() {
				var val = $(this).val();
				var $parent = $(this).closest('td');
				var $dow = $parent.find('.sgbackup-day-of-week-select');
				var $dom = $parent.find('.sgbackup-day-of-month-select');

				if (val === 'weekly' || val === 'fortnightly') {
					$dow.show();
					$dom.hide();
				} else if (val === 'monthly') {
					$dow.hide();
					$dom.show();
				} else {
					$dow.hide();
					$dom.hide();
				}
			});

			// H3: Add retention rule
			$(document).on('click', '.sgbackup-add-retention-rule', function(e) {
				e.preventDefault();
				var type = $(this).data('type');
				self.addRetentionRule(type);
			});

			// H3: Delete retention rule
			$(document).on('click', '.sgbackup-delete-retention-rule', function() {
				$(this).closest('.sgbackup-retention-rule').remove();
			});
		},

		// H1: Add a new storage instance by cloning the first panel
		addStorageInstance: function(method) {
			var $container = $('.sgbackup-instance-container[data-method="' + method + '"]');
			if (!$container.length) return;

			// Generate random instance ID: s- + 12 hex chars
			var hexChars = '0123456789abcdef';
			var newId = 's-';
			for (var i = 0; i < 12; i++) {
				newId += hexChars.charAt(Math.floor(Math.random() * 16));
			}

			// Clone the first panel as template
			var $firstPanel = $container.find('.sgbackup-instance-panel').first();
			var $newPanel = $firstPanel.clone();

			// Update instance references
			$newPanel.attr('data-instance', newId);
			$newPanel.find('[data-instance]').attr('data-instance', newId);
			// Clear field values
			$newPanel.find('input[type="text"], input[type="password"], input[type="url"], input[type="number"]').val('');
			$newPanel.find('textarea').val('');
			$newPanel.find('.sgbackup-instance-enabled').prop('checked', true);
			$newPanel.find('select').each(function() {
				$(this).prop('selectedIndex', 0);
			});
			// Clear test result and auth status
			$newPanel.find('.sgbackup-test-result').text('');
			$newPanel.find('.sgbackup-auth-status').remove();
			$newPanel.hide();

			$container.append($newPanel);

			// Add tab
			var label = method.charAt(0).toUpperCase() + method.slice(1);
			var tabHtml = '<span class="sgbackup-instance-tab" data-instance="' + this.esc(newId) + '">';
			tabHtml += '<span class="sgbackup-instance-label" contenteditable="true">' + this.esc(label) + ' (new)</span>';
			tabHtml += ' <button type="button" class="sgbackup-delete-instance" data-method="' + this.esc(method) + '" data-instance="' + this.esc(newId) + '" title="Delete instance">&times;</button>';
			tabHtml += '</span>';
			$container.find('.sgbackup-instance-tabs').append(tabHtml);

			// Switch to the new tab
			$container.find('.sgbackup-instance-tab').removeClass('active');
			$container.find('.sgbackup-instance-tab[data-instance="' + newId + '"]').addClass('active');
			$container.find('.sgbackup-instance-panel').hide();
			$newPanel.show();
		},

		// H1: Delete a storage instance
		deleteStorageInstance: function(method, instanceId) {
			if (!confirm('Delete this storage instance? Any saved settings for this instance will be lost.')) return;

			var $container = $('.sgbackup-instance-container[data-method="' + method + '"]');
			$container.find('.sgbackup-instance-tab[data-instance="' + instanceId + '"]').remove();
			$container.find('.sgbackup-instance-panel[data-instance="' + instanceId + '"]').remove();

			// Activate first remaining tab
			var $tabs = $container.find('.sgbackup-instance-tab');
			var $panels = $container.find('.sgbackup-instance-panel');
			if ($tabs.length) {
				$tabs.first().addClass('active');
				$panels.hide().first().show();
			}
		},

		// H3: Add a retention rule row
		addRetentionRule: function(type) {
			var $container = $('#sgbackup-retention-rules-' + type);
			var index = $container.find('.sgbackup-retention-rule').length;

			var html = '<div class="sgbackup-retention-rule" data-index="' + index + '">';
			html += 'For backups after the first ';
			html += '<input type="number" class="sgbackup-rr-after-count" value="12" min="1" max="999" style="width:60px;" />';
			html += ' kept, older than ';
			html += '<select class="sgbackup-rr-after-period">';
			html += '<option value="3600">hours</option>';
			html += '<option value="86400">days</option>';
			html += '<option value="604800" selected>weeks</option>';
			html += '<option value="2592000">months</option>';
			html += '</select>';
			html += ', keep no more than 1 every ';
			html += '<select class="sgbackup-rr-keep-every">';
			html += '<option value="3600">hours</option>';
			html += '<option value="86400">days</option>';
			html += '<option value="604800" selected>weeks</option>';
			html += '<option value="2592000">months</option>';
			html += '</select> ';
			html += '<button type="button" class="button sgbackup-delete-retention-rule">Delete</button>';
			html += '</div>';

			$container.append(html);
		},

		// ===== M3: External Databases =====
		bindExternalDbs: function() {
			var self = this;

			// Add external database row
			$('#sgbackup-add-extdb').on('click', function(e) {
				e.preventDefault();
				self.addExternalDb();
			});

			// Remove external database row
			$(document).on('click', '.sgbackup-remove-extdb', function() {
				$(this).closest('.sgbackup-extradb-row').remove();
				self.reindexExternalDbs();
			});

			// Test external database connection
			$(document).on('click', '.sgbackup-test-extdb', function() {
				var $btn = $(this);
				var $row = $btn.closest('.sgbackup-extradb-row');
				var $result = $row.find('.sgbackup-extdb-test-result');
				var db = {};

				$row.find('.sgbackup-extradb-field').each(function() {
					var key = $(this).data('key');
					if (key) db[key] = $(this).val();
				});

				$result.text('Testing...').removeClass('success error');

				self.ajax('test_extdb', db, function(data) {
					$result.text(data.message || 'Connection successful!').addClass('success');
				}, function(msg) {
					$result.text('Error: ' + msg).addClass('error');
				});
			});
		},

		addExternalDb: function() {
			var $container = $('#sgbackup-extradbs-container');
			var index = $container.find('.sgbackup-extradb-row').length;

			var html = '<div class="sgbackup-extradb-row" data-index="' + index + '">';
			html += '<table class="form-table">';
			html += '<tr><th>Host</th><td><input type="text" class="sgbackup-extradb-field regular-text" data-key="host" value="" placeholder="localhost" /></td></tr>';
			html += '<tr><th>Port</th><td><input type="number" class="sgbackup-extradb-field" data-key="port" value="3306" style="width:80px;" /></td></tr>';
			html += '<tr><th>Username</th><td><input type="text" class="sgbackup-extradb-field regular-text" data-key="user" value="" /></td></tr>';
			html += '<tr><th>Password</th><td><input type="password" class="sgbackup-extradb-field regular-text" data-key="pass" value="" autocomplete="off" /></td></tr>';
			html += '<tr><th>Database Name</th><td><input type="text" class="sgbackup-extradb-field regular-text" data-key="name" value="" /></td></tr>';
			html += '<tr><th>Table Prefix (optional)</th><td><input type="text" class="sgbackup-extradb-field regular-text" data-key="prefix" value="" placeholder="All tables" />';
			html += '<p class="description">Only backup tables with this prefix. Leave empty to backup all tables.</p></td></tr>';
			html += '<tr><td colspan="2">';
			html += '<button type="button" class="button sgbackup-test-extdb" data-index="' + index + '">Test Connection</button> ';
			html += '<span class="sgbackup-extdb-test-result"></span>';
			html += '<button type="button" class="button sgbackup-remove-extdb" data-index="' + index + '" style="float:right;color:#a00;">Remove</button>';
			html += '</td></tr>';
			html += '</table><hr /></div>';

			$container.append(html);
		},

		reindexExternalDbs: function() {
			$('#sgbackup-extradbs-container .sgbackup-extradb-row').each(function(i) {
				$(this).attr('data-index', i);
				$(this).find('.sgbackup-test-extdb, .sgbackup-remove-extdb').attr('data-index', i);
			});
		},

		// ===== Advanced Tools =====
		bindAdvanced: function() {
			var self = this;

			// System info
			$('#sgbackup-sysinfo-btn').on('click', function() {
				self.ajax('get_sysinfo', {}, function(data) {
					var text = '';
					$.each(data, function(key, val) {
						if (typeof val === 'object') val = JSON.stringify(val);
						text += key + ': ' + val + '\n';
					});
					$('#sgbackup-sysinfo-text').val(text);
					$('#sgbackup-sysinfo').show();
				});
			});

			// Search & Replace
			$('#sgbackup-searchreplace-btn').on('click', function() {
				var search = $('#sgbackup-sr-search').val();
				var replace = $('#sgbackup-sr-replace').val();
				if (!search) return;
				if (!confirm('This will modify your database. Proceed?')) return;

				$('#sgbackup-sr-status').text('Running...').removeClass('success error');
				self.ajax('searchreplace', { search: search, replace: replace }, function(data) {
					$('#sgbackup-sr-status').text(
						'Done: ' + data.changes + ' changes, ' + data.updates + ' rows updated in ' + data.tables + ' tables'
					).addClass('success');
				}, function(msg) {
					$('#sgbackup-sr-status').text('Error: ' + msg).addClass('error');
				});
			});

			// Refresh jobs
			$('#sgbackup-refresh-jobs-btn').on('click', function() {
				self.ajax('activejobs_list', {}, function(data) {
					if ($.isEmptyObject(data)) {
						$('#sgbackup-jobs-list').html('<p><em>No active jobs.</em></p>');
						return;
					}
					var html = '';
					$.each(data, function(nonce, job) {
						html += '<div class="sgbackup-job">';
						html += '<strong>' + SGB.esc(nonce) + '</strong>: ' + SGB.esc(job.jobstatus);
						html += ' (resumption ' + SGB.esc(String(job.resumption)) + ')';
						html += '</div>';
					});
					$('#sgbackup-jobs-list').html(html);
				});
			});

			// Wipe settings
			$('#sgbackup-wipe-settings-btn').on('click', function() {
				if (!confirm('Reset all SiteGuard Backup settings to defaults?')) return;
				self.ajax('wipe_settings', {}, function() {
					alert('Settings reset. Reloading...');
					location.reload();
				});
			});
		},

		// ===== Gap 35: Mass Actions =====
		bindMassActions: function() {
			var self = this;

			// Select-all checkbox in header
			$(document).on('change', '#sgbackup-select-all', function() {
				var checked = $(this).is(':checked');
				$('.sgbackup-backups-table .sgbackup-row-check').prop('checked', checked);
			});

			// Select All text link
			$(document).on('click', '#sgbackup-select-all-link', function(e) {
				e.preventDefault();
				$('.sgbackup-backups-table .sgbackup-row-check').prop('checked', true);
				$('#sgbackup-select-all').prop('checked', true);
			});

			// Deselect All text link
			$(document).on('click', '#sgbackup-deselect-all-link', function(e) {
				e.preventDefault();
				$('.sgbackup-backups-table .sgbackup-row-check').prop('checked', false);
				$('#sgbackup-select-all').prop('checked', false);
			});

			// Delete Selected
			$(document).on('click', '#sgbackup-delete-selected', function() {
				var checked = $('.sgbackup-backups-table .sgbackup-row-check:checked');
				if (checked.length === 0) {
					alert('No backups selected.');
					return;
				}

				if (!confirm('Delete ' + checked.length + ' selected backup(s)?')) return;

				var timestamps = [];
				checked.each(function() {
					timestamps.push($(this).val());
				});

				var completed = 0;
				var total = timestamps.length;
				var $btn = $(this);
				$btn.prop('disabled', true).text('Deleting...');

				timestamps.forEach(function(ts) {
					self.ajax('delete_backup', { timestamp: ts }, function() {
						$('.sgbackup-backups-table tr[data-timestamp="' + ts + '"]').fadeOut(200, function() { $(this).remove(); });
						completed++;
						if (completed >= total) {
							$btn.prop('disabled', false).text('Delete Selected');
							$('#sgbackup-select-all').prop('checked', false);
						}
					}, function() {
						completed++;
						if (completed >= total) {
							$btn.prop('disabled', false).text('Delete Selected');
						}
					});
				});
			});
		},

		// ===== Gap 36: Pagination =====
		bindPagination: function() {
			// Show More
			$(document).on('click', '#sgbackup-show-more', function(e) {
				e.preventDefault();
				var pageSize = parseInt($(this).data('page-size'), 10) || 20;
				var $hidden = $('.sgbackup-backups-table .sgbackup-row-hidden');
				var toShow = $hidden.slice(0, pageSize);
				toShow.removeClass('sgbackup-row-hidden');

				// Update remaining count
				var remaining = $hidden.length - toShow.length;
				if (remaining > 0) {
					$(this).text('Show more backups... (' + remaining + ' hidden)');
				} else {
					$(this).hide();
					$('#sgbackup-show-all').hide();
				}
			});

			// Show All
			$(document).on('click', '#sgbackup-show-all', function(e) {
				e.preventDefault();
				$('.sgbackup-backups-table .sgbackup-row-hidden').removeClass('sgbackup-row-hidden');
				$(this).hide();
				$('#sgbackup-show-more').hide();
			});
		},

		// ===== Gap 37: Upload to Remote =====
		bindUploadToRemote: function() {
			var self = this;

			$(document).on('click', '.sgbackup-upload-remote-btn', function() {
				var timestamp = $(this).data('timestamp');

				// Build a modal with checkboxes for each configured storage method
				var methods = ['email', 'ftp', 'sftp', 's3', 'googledrive', 'dropbox', 'onedrive', 'webdav', 'azure', 'gcs', 'pcloud'];
				var html = '<div id="sgbackup-upload-remote-dialog" title="Upload to Remote Storage">';
				html += '<p>Select storage destinations:</p>';
				for (var i = 0; i < methods.length; i++) {
					html += '<label style="display:block;margin:4px 0;">';
					html += '<input type="checkbox" class="sgbackup-remote-method-check" value="' + SGB.esc(methods[i]) + '" /> ';
					html += SGB.esc(methods[i].charAt(0).toUpperCase() + methods[i].slice(1));
					html += '</label>';
				}
				html += '<div id="sgbackup-upload-remote-status" class="sgbackup-status" style="margin-top:10px;"></div>';
				html += '</div>';

				var $dialog = $(html);
				$dialog.dialog({
					modal: true,
					width: 400,
					buttons: [
						{
							text: 'Upload',
							class: 'button button-primary',
							click: function() {
								var selected = [];
								$dialog.find('.sgbackup-remote-method-check:checked').each(function() {
									selected.push($(this).val());
								});
								if (selected.length === 0) {
									$dialog.find('#sgbackup-upload-remote-status').text('Select at least one destination.').addClass('error');
									return;
								}
								$dialog.find('#sgbackup-upload-remote-status').text('Uploading...').removeClass('error success');
								self.ajax('upload_to_remote', { timestamp: timestamp, services: selected }, function(data) {
									var msgs = [];
									if (data.results) {
										$.each(data.results, function(svc, res) {
											if (res.success) {
												msgs.push(svc + ': ' + res.uploaded + ' file(s) uploaded');
											} else {
												msgs.push(svc + ': FAILED - ' + (res.errors ? res.errors.join('; ') : res.error));
											}
										});
									}
									$dialog.find('#sgbackup-upload-remote-status').text(msgs.join('\n')).addClass('success');
								}, function(msg) {
									$dialog.find('#sgbackup-upload-remote-status').text('Error: ' + msg).addClass('error');
								});
							}
						},
						{
							text: 'Close',
							class: 'button',
							click: function() { $(this).dialog('close'); }
						}
					]
				});
			});
		},

		// ===== Gap 38: Rescan Local / Remote =====
		bindRescan: function() {
			var self = this;

			$('#sgbackup-rescan-local').on('click', function() {
				var $btn = $(this);
				var $status = $('#sgbackup-rescan-status');
				$btn.prop('disabled', true);
				$status.text('Scanning...').removeClass('success error');

				self.ajax('rescan_local', {}, function(data) {
					$btn.prop('disabled', false);
					$status.text('Found ' + (data.new_files || 0) + ' new file(s).').addClass('success');
					if (data.new_files > 0) {
						setTimeout(function() { location.reload(); }, 1500);
					}
				}, function(msg) {
					$btn.prop('disabled', false);
					$status.text('Error: ' + msg).addClass('error');
				});
			});

			$('#sgbackup-rescan-remote').on('click', function() {
				var $btn = $(this);
				var $status = $('#sgbackup-rescan-status');
				$btn.prop('disabled', true);
				$status.text('Scanning remote...').removeClass('success error');

				self.ajax('rescan_remote', {}, function(data) {
					$btn.prop('disabled', false);
					var msgs = [];
					if (data.results) {
						$.each(data.results, function(svc, count) {
							msgs.push(svc + ': ' + count + ' new');
						});
					}
					$status.text(msgs.length ? msgs.join(', ') : 'No new files found.').addClass('success');
					var totalNew = 0;
					if (data.results) {
						$.each(data.results, function(svc, count) { totalNew += count; });
					}
					if (totalNew > 0) {
						setTimeout(function() { location.reload(); }, 1500);
					}
				}, function(msg) {
					$btn.prop('disabled', false);
					$status.text('Error: ' + msg).addClass('error');
				});
			});
		},

		// ===== Gap 39: Site Size =====
		bindSiteSize: function() {
			var self = this;

			$('#sgbackup-site-size-btn').on('click', function() {
				var $btn = $(this);
				var $status = $('#sgbackup-site-size-status');
				$btn.prop('disabled', true);
				$status.text('Calculating...').removeClass('success error');

				self.ajax('get_site_size', {}, function(data) {
					$btn.prop('disabled', false);
					$status.text('').addClass('success');

					var labels = {
						plugins: 'Plugins',
						themes: 'Themes',
						uploads: 'Uploads',
						others: 'Others (wp-content)',
						wpcore: 'WordPress Core',
						database: 'Database',
						total: 'Total'
					};

					var $tbody = $('#sgbackup-site-size-table tbody');
					$tbody.empty();
					$.each(['plugins', 'themes', 'uploads', 'others', 'wpcore', 'database', 'total'], function(i, key) {
						var size = data[key];
						var sizeStr = (size === -1) ? 'Timeout' : self.formatBytes(size || 0);
						var cls = (key === 'total') ? ' style="font-weight:bold;"' : '';
						$tbody.append('<tr' + cls + '><td>' + SGB.esc(labels[key] || key) + '</td><td>' + SGB.esc(sizeStr) + '</td></tr>');
					});

					$('#sgbackup-site-size-result').show();
				}, function(msg) {
					$btn.prop('disabled', false);
					$status.text('Error: ' + msg).addClass('error');
				});
			});
		},

		// ===== Gap 40: Database Size =====
		bindDbSize: function() {
			var self = this;

			$('#sgbackup-db-size-btn').on('click', function() {
				var $btn = $(this);
				var $status = $('#sgbackup-db-size-status');
				$btn.prop('disabled', true);
				$status.text('Calculating...').removeClass('success error');

				self.ajax('get_db_size', {}, function(data) {
					$btn.prop('disabled', false);
					$status.text('').addClass('success');

					var $tbody = $('#sgbackup-db-size-table tbody');
					$tbody.empty();

					var renderTable = function(tables, groupLabel) {
						if (tables && tables.length) {
							$tbody.append('<tr style="background:#f0f0f1;"><td colspan="5"><strong>' + SGB.esc(groupLabel) + '</strong></td></tr>');
							for (var i = 0; i < tables.length; i++) {
								var t = tables[i];
								$tbody.append(
									'<tr><td>' + SGB.esc(t.name) + '</td>' +
									'<td>' + SGB.esc(String(t.rows)) + '</td>' +
									'<td>' + SGB.esc(self.formatBytes(t.data)) + '</td>' +
									'<td>' + SGB.esc(self.formatBytes(t['index'])) + '</td>' +
									'<td>' + SGB.esc(self.formatBytes(t.size)) + '</td></tr>'
								);
							}
						}
					};

					renderTable(data.wp_tables, 'WordPress Tables (' + self.formatBytes(data.wp_total) + ')');
					renderTable(data.non_wp_tables, 'Non-WordPress Tables (' + self.formatBytes(data.non_wp_total) + ')');

					$tbody.append(
						'<tr style="font-weight:bold;"><td>Total</td><td></td><td></td><td></td>' +
						'<td>' + SGB.esc(self.formatBytes(data.total)) + '</td></tr>'
					);

					$('#sgbackup-db-size-result').show();
				}, function(msg) {
					$btn.prop('disabled', false);
					$status.text('Error: ' + msg).addClass('error');
				});
			});
		},

		// ===== Gap 41: Cron Events =====
		bindCronEvents: function() {
			var self = this;

			$('#sgbackup-cron-events-btn').on('click', function() {
				var $btn = $(this);
				var filter = $('#sgbackup-cron-filter-sgbackup').is(':checked') ? 'sgbackup' : '';

				$btn.prop('disabled', true);

				self.ajax('get_cron_events', { filter: filter }, function(data) {
					$btn.prop('disabled', false);

					var $tbody = $('#sgbackup-cron-events-table tbody');
					$tbody.empty();

					if (!data.events || data.events.length === 0) {
						$tbody.append('<tr><td colspan="3"><em>No cron events found.</em></td></tr>');
					} else {
						for (var i = 0; i < data.events.length; i++) {
							var ev = data.events[i];
							$tbody.append(
								'<tr><td>' + SGB.esc(ev.hook) + '</td>' +
								'<td>' + SGB.esc(ev.next_run_date) + '</td>' +
								'<td>' + SGB.esc(ev.interval) + '</td></tr>'
							);
						}
					}

					$('#sgbackup-cron-events-result').show();
				}, function(msg) {
					$btn.prop('disabled', false);
					alert('Error: ' + msg);
				});
			});
		},

		// ===== Gap 42: Export / Import Settings =====
		bindExportImportSettings: function() {
			var self = this;

			// Export
			$('#sgbackup-export-settings-btn').on('click', function() {
				var $btn = $(this);
				var $status = $('#sgbackup-export-status');
				$btn.prop('disabled', true);
				$status.text('Exporting...').removeClass('success error');

				self.ajax('export_settings', {}, function(data) {
					$btn.prop('disabled', false);
					$status.text('').addClass('success');

					// Create downloadable JSON blob
					var json = JSON.stringify(data, null, 2);
					var blob = new Blob([json], { type: 'application/json' });
					var url = URL.createObjectURL(blob);
					var a = document.createElement('a');
					a.href = url;
					a.download = 'sgbackup-settings-' + new Date().toISOString().slice(0, 10) + '.json';
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
					URL.revokeObjectURL(url);

					$status.text('Settings exported!').addClass('success');
				}, function(msg) {
					$btn.prop('disabled', false);
					$status.text('Error: ' + msg).addClass('error');
				});
			});

			// Import
			$('#sgbackup-import-settings-btn').on('click', function() {
				var $btn = $(this);
				var $status = $('#sgbackup-import-status');
				var fileInput = $('#sgbackup-import-settings-file')[0];

				if (!fileInput.files || !fileInput.files.length) {
					$status.text('Please select a JSON file.').addClass('error');
					return;
				}

				var file = fileInput.files[0];
				if (!file.name.match(/\.json$/i)) {
					$status.text('Please select a .json file.').addClass('error');
					return;
				}

				var reader = new FileReader();
				reader.onload = function(e) {
					var content;
					try {
						content = JSON.parse(e.target.result);
					} catch (parseErr) {
						$status.text('Invalid JSON file.').addClass('error');
						return;
					}

					if (!content.settings) {
						$status.text('Invalid settings file: missing "settings" key.').addClass('error');
						return;
					}

					if (!confirm('Import settings from this file? This will overwrite your current settings.')) return;

					$btn.prop('disabled', true);
					$status.text('Importing...').removeClass('success error');

					self.ajax('import_settings', { settings: content.settings }, function(data) {
						$btn.prop('disabled', false);
						$status.text('Imported ' + (data.imported || 0) + ' setting(s). Reloading...').addClass('success');
						setTimeout(function() { location.reload(); }, 1500);
					}, function(msg) {
						$btn.prop('disabled', false);
						$status.text('Error: ' + msg).addClass('error');
					});
				};
				reader.readAsText(file);
			});
		},

		// ===== Utility: Format bytes =====
		formatBytes: function(bytes) {
			if (bytes === 0) return '0 B';
			var units = ['B', 'KB', 'MB', 'GB', 'TB'];
			var i = Math.floor(Math.log(bytes) / Math.log(1024));
			if (i >= units.length) i = units.length - 1;
			return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 2 : 0) + ' ' + units[i];
		}
	};

	// H5: Unlock form handler
	$(document).on('submit', '#sgbackup-unlock-form', function(e) {
		e.preventDefault();
		var password = $(this).find('[name="sgbackup_password"]').val();
		if (!password) return;

		$('#sgbackup-unlock-error').hide();

		$.ajax({
			url: sgbackup_settings.ajax_url,
			type: 'POST',
			data: {
				action: 'sgbackup_unlock',
				nonce: sgbackup_settings.nonce,
				password: password
			},
			success: function(response) {
				if (response.success) {
					location.reload();
				} else {
					var msg = response.data && response.data.message ? response.data.message : 'Incorrect password.';
					$('#sgbackup-unlock-error').text(msg).show();
				}
			},
			error: function() {
				$('#sgbackup-unlock-error').text('An error occurred.').show();
			}
		});
	});

	$(document).ready(function() {
		SGB.init();

		// H6: Show/hide table selection when "Include database" is toggled
		$('input[name="sgbackup_include_db"]').on('change', function() {
			$('#sgbackup-table-selection').toggle(this.checked);
		});
		// Initialize on page load
		if ($('input[name="sgbackup_include_db"]').is(':checked')) {
			$('#sgbackup-table-selection').show();
		}

		// H6: "Select individual tables..." link
		$(document).on('click', '#sgbackup-select-tables-link', function(e) {
			e.preventDefault();
			var $list = $('#sgbackup-table-list');
			if ($list.is(':visible')) {
				$list.hide();
				return;
			}
			$list.show().html('<p><em>Loading tables...</em></p>');

			SGB.ajax('get_database_tables', {}, function(data) {
				if (!data.tables || !data.tables.length) {
					$list.html('<p><em>No tables found.</em></p>');
					return;
				}

				var html = '<p><a href="#" class="sgbackup-tables-select-all">Select all</a> | <a href="#" class="sgbackup-tables-deselect-all">Deselect all</a></p>';
				var includeNonWp = $('input[name="sgbackup_include_nonwp_tables"]').is(':checked');
				for (var i = 0; i < data.tables.length; i++) {
					var t = data.tables[i];
					var isWp = t.is_wp;
					var show = isWp || includeNonWp;
					html += '<label style="display:' + (show ? 'block' : 'none') + ';" data-is-wp="' + (isWp ? '1' : '0') + '">';
					html += '<input type="checkbox" name="sgbackup_include_tables[]" value="' + SGB.esc(t.name) + '" checked /> ';
					html += SGB.esc(t.name);
					if (!isWp) html += ' <small>(non-WP)</small>';
					html += '</label>';
				}
				$list.html(html);
			}, function(msg) {
				$list.html('<p class="sgbackup-verify-fail">Error: ' + SGB.esc(msg) + '</p>');
			});
		});

		// H6: Select all / Deselect all for tables
		$(document).on('click', '.sgbackup-tables-select-all', function(e) {
			e.preventDefault();
			$('#sgbackup-table-list label:visible input[type="checkbox"]').prop('checked', true);
		});
		$(document).on('click', '.sgbackup-tables-deselect-all', function(e) {
			e.preventDefault();
			$('#sgbackup-table-list label:visible input[type="checkbox"]').prop('checked', false);
		});

		// H6: Toggle non-WP tables visibility
		$('input[name="sgbackup_include_nonwp_tables"]').on('change', function() {
			var show = this.checked;
			$('#sgbackup-table-list label[data-is-wp="0"]').toggle(show);
		});

		// M5: Show/hide anonymise section when "Include database" is toggled
		$('input[name="sgbackup_include_db"]').on('change', function() {
			$('#sgbackup-db-anonymise-section').toggle(this.checked);
		});
		// Initialize anonymise section visibility on page load
		if ($('input[name="sgbackup_include_db"]').is(':checked')) {
			$('#sgbackup-db-anonymise-section').show();
		}

		// M5: Toggle anonymise mode options in backup-now modal
		$('input[name="sgbackup_anonymise"]').on('change', function() {
			$('#sgbackup-anonymise-mode-options').toggle(this.checked);
		});

		// M5: Toggle anonymise mode row in settings page
		$('input[name="db_anonymise"]').on('change', function() {
			$('.sgbackup-anonymise-mode-row').toggle(this.checked);
		});

		// M9: Add extra directory row
		$(document).on('click', '#sgbackup-add-extra-dir', function() {
			var html = '<div class="sgbackup-extra-dir-row" style="margin-bottom:4px;">';
			html += '<input type="text" class="regular-text sgbackup-extra-dir-input" value="" placeholder="/absolute/path/to/directory" />';
			html += ' <button type="button" class="button sgbackup-remove-extra-dir">Remove</button>';
			html += '</div>';
			$('#sgbackup-extra-dirs').append(html);
		});

		// M9: Remove extra directory row
		$(document).on('click', '.sgbackup-remove-extra-dir', function() {
			$(this).closest('.sgbackup-extra-dir-row').remove();
		});
	});

})(jQuery);
