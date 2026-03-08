/**
 * SiteGuard Backup - Admin JavaScript
 */
(function($) {
	'use strict';

	var SGB = {
		pollTimer: null,
		lastKnownJobs: {},
		uploadAbortController: null,

		// HTML escape utility to prevent XSS
		esc: function(str) {
			var div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		},

		/**
		 * Compute mirror domain for a source domain.
		 * For subdomains of oldHost, replaces oldHost with newHost.
		 * For independent domains, strips TLD and uses as subdomain of newHost.
		 */
		mirrorDomain: function(srcDomain, oldHost, newHost) {
			if (!oldHost) return srcDomain;
			if (srcDomain.indexOf(oldHost) !== -1) {
				return srcDomain.replace(new RegExp(oldHost.replace(/\./g, '\\\\.'), 'g'), newHost);
			}
			var parts = srcDomain.split('.');
			parts.pop();
			return parts.join('.') + '.' + newHost;
		},

		/**
		 * Show a WordPress-style admin notice.
		 * @param {string} message - The notice text.
		 * @param {string} type    - 'success', 'error', or 'warning'.
		 */
		showNotice: function(message, type) {
			type = type || 'info';
			var $container = $('#sgbackup-notices');
			if (!$container.length) $container = $('#sgbackup-notices-migrate');
			if (!$container.length) return;

			var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + this.esc(message) + '</p>' +
				'<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>');
			$notice.find('.notice-dismiss').on('click', function() { $notice.fadeOut(200, function() { $(this).remove(); }); });
			$container.append($notice);

			// Auto-dismiss success notices after 8 seconds
			if (type === 'success') {
				setTimeout(function() { $notice.fadeOut(400, function() { $(this).remove(); }); }, 8000);
			}
		},

		// Fallback copy using deprecated execCommand for older browsers
		fallbackCopy: function(text) {
			var textarea = document.createElement('textarea');
			textarea.value = text;
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild(textarea);
			textarea.select();
			try { document.execCommand('copy'); } catch (e) { /* ignore */ }
			document.body.removeChild(textarea);
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
			this.bindDownloadAll();
			this.bindRescan();
			this.bindSiteSize();
			this.bindDbSize();
			this.bindCronEvents();
			this.bindExportImportSettings();
			this.bindWebhook();
			this.bindFailover();
			this.bindRestorePresets();
			this.bindRemoteSendSchedule();
			this.pollActiveJobs();

			$(window).on('beforeunload', function(e) {
				if (SGB.pollTimer) { clearInterval(SGB.pollTimer); SGB.pollTimer = null; }
				if (SGB.uploadAbortController) {
					e.preventDefault();
					return 'A file upload is in progress. Are you sure you want to leave?';
				}
			});
		},

		// ===== AJAX Helper =====
		ajax: function(subaction, data, callback, errorCallback) {
			$.ajax({
				url: sgbackup_settings.ajax_url,
				type: 'POST',
				data: {
					action: 'sgbackup_ajax',
					subaction: subaction,
					nonce: (sgbackup_settings.nonces && sgbackup_settings.nonces[subaction]) || sgbackup_settings.nonce,
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
			// Restore active tab from URL hash on page load
			var hash = window.location.hash.replace('#', '');
			if (hash) {
				var $tab = $('.sgbackup-tabs .nav-tab[data-tab="' + hash + '"]');
				if ($tab.length) {
					$('.sgbackup-tabs .nav-tab').removeClass('nav-tab-active');
					$tab.addClass('nav-tab-active');
					$('.sgbackup-tab-content').hide();
					$('#tab-' + hash).show();
				}
			}

			$('.sgbackup-tabs .nav-tab').on('click', function(e) {
				e.preventDefault();
				var tab = $(this).data('tab');
				$('.sgbackup-tabs .nav-tab').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');
				$('.sgbackup-tab-content').hide();
				$('#tab-' + tab).show();
				// Persist active tab in URL hash so page reloads stay on same tab
				if (history.replaceState) {
					history.replaceState(null, null, '#' + tab);
				} else {
					window.location.hash = tab;
				}
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

			// Show subsite backup option when on a multisite subsite
			if (sgbackup_settings.is_multisite && sgbackup_settings.current_blog_id > 1) {
				$('#sgbackup-subsite-backup-option').show();
			}
		},

		doBackupNow: function() {
			// Prevent launching a second backup while one is running
			if (this._activeJobNonce) {
				SGB.showNotice('A backup is already running (job: ' + this._activeJobNonce + '). Please wait for it to finish before starting another.', 'error');
				return;
			}

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

			// Subsite-only backup
			if ($('input[name="sgbackup_subsite_backup"]').is(':checked')) {
				params.subsite_backup = true;
			}

			var sendTo = $('#sgbackup-send-to-select').val();
			if (sendTo) {
				params.service = [sendTo];
			}

			$('#sgbackup-backup-status').text(sgbackup_settings.strings.backup_started).removeClass('success error');

			// Mark backup start time so polling knows not to declare "complete"
			// prematurely during the grace period while the job registers.
			this._backupStartedAt = Date.now();

			this.ajax('backupnow', params, function(data) {
				$('#sgbackup-backup-status').html(
					sgbackup_settings.strings.backup_started +
					' <span class="sgbackup-spinner"></span> Job: ' + data.nonce
				);
			});

			// Start interval polling immediately since we just launched a backup
			this.startJobPolling();
		},

		// ===== Active Jobs Polling =====
		pollActiveJobs: function() {
			var self = this;
			if (this.pollTimer) clearInterval(this.pollTimer);
			this.pollTimer = null;

			// Single initial check - only start interval polling if jobs are active
			this.checkActiveJobs(function(hasJobs) {
				if (hasJobs) {
					self.startJobPolling();
				}
			});
		},

		checkActiveJobs: function(callback) {
			var self = this;
			this.ajax('activejobs_list', {}, function(data) {
				if ($.isEmptyObject(data)) {
					$('#sgbackup-active-jobs').hide();
					$('#sgbackup-active-jobs-list').empty();
					self._activeJobNonce = null;
					if (callback) callback(false);
					return;
				}
				// Track first active job nonce to prevent concurrent starts
				self._activeJobNonce = Object.keys(data)[0];
				self.renderActiveJobs(data);
				if (callback) callback(true);
			}, function() {
				// Silently ignore errors during job check
				if (callback) callback(false);
			});
		},

		startJobPolling: function() {
			var self = this;
			if (this.pollTimer) clearInterval(this.pollTimer);

			this.pollTimer = setInterval(function() {
				self.ajax('activejobs_list', {}, function(data) {
					if ($.isEmptyObject(data)) {
						// Grace period: if we just started a backup, don't declare
						// "complete" for 20s - the job may not be visible yet due to
						// cron event timing during the direct-chain execution.
						if (self._backupStartedAt && (Date.now() - self._backupStartedAt) < 20000) {
							return; // keep polling
						}

						$('#sgbackup-active-jobs').hide();
						$('#sgbackup-active-jobs-list').empty();
						$('#sgbackup-backup-status').text('').removeClass('success error');
						clearInterval(self.pollTimer);
						self.pollTimer = null;
						self._activeJobNonce = null;
						self._backupStartedAt = null;

						// Check last known jobs for errors or warnings
						var hadErrors = false;
						$.each(self.lastKnownJobs, function(nonce, job) {
							if (job.jobstatus === 'error' || job.jobstatus === 'failed' || job.jobstatus === 'aborted' || job.has_errors) {
								hadErrors = true;
								var errMsg = 'Backup job failed';
								if (job.errors && job.errors.length) {
									errMsg = job.errors[job.errors.length - 1];
								} else if (job.last_log) {
									errMsg = job.last_log;
								}
								self.showNotice(errMsg, 'error');
							} else if (job.jobstatus === 'warning') {
								hadErrors = true;
								var warnMsg = 'Backup finished with errors - some entities failed. Check the log for details.';
								if (job.errors && job.errors.length) {
									warnMsg = job.errors[job.errors.length - 1];
								}
								self.showNotice(warnMsg, 'error');
							}
						});

						if (!hadErrors) {
							self.showNotice('Backup complete!', 'success');
						}
						self.refreshBackupsList();
						self.lastKnownJobs = {};
						return;
					}
					// Job found - clear grace period since we've confirmed visibility
					self._backupStartedAt = null;
					self.lastKnownJobs = data;
					self._activeJobNonce = Object.keys(data)[0];
					self.renderActiveJobs(data);
				}, function() {
					// Silently ignore errors during polling
				});
			}, 5000);
		},

		renderActiveJobs: function(data) {
			var self = this;
			$('#sgbackup-active-jobs').show();
			var html = '';
			$.each(data, function(nonce, job) {
				// Build progress bar sections
				var steps = [];
				if (job.backup_files === 'yes') {
					var filesIcon = job.files_phase_complete ? '&#10003;' : '&#9679;';
					var filesClass = job.files_phase_complete ? 'sgbackup-phase-done' : 'sgbackup-phase-active';
					steps.push('<span class="' + filesClass + '">' + filesIcon + ' Files</span>');
				}
				if (job.backup_database === 'yes') {
					var dbDone = job.db_phase_complete;
					var dbActive = !dbDone && job.files_phase_complete;
					var dbIcon = dbDone ? '&#10003;' : (dbActive ? '&#9679;' : '&#9675;');
					var dbClass = dbDone ? 'sgbackup-phase-done' : (dbActive ? 'sgbackup-phase-active' : 'sgbackup-phase-pending');
					steps.push('<span class="' + dbClass + '">' + dbIcon + ' Database</span>');
				}
				if (job.upload_phase_complete) {
					steps.push('<span class="sgbackup-phase-done">&#10003; Upload</span>');
				}

				// Entity-level detail
				var entityDetail = '';
				if (job.entity_progress) {
					var parts = [];
					$.each(job.entity_progress, function(ent, st) {
						var icon = (st === 'complete') ? '&#10003;' : ((st === 'pending') ? '&#9675;' : '&#9679;');
						parts.push(icon + ' ' + SGB.esc(ent));
					});
					if (parts.length) {
						entityDetail = '<div class="sgbackup-job-entities">' + parts.join(' &nbsp; ') + '</div>';
					}
				}

				// Elapsed time
				var elapsed = '';
				if (job.elapsed > 0) {
					var mins = Math.floor(job.elapsed / 60);
					var secs = job.elapsed % 60;
					elapsed = mins > 0 ? (mins + 'm ' + secs + 's') : (secs + 's');
				}

				html += '<div class="sgbackup-job' + (job.has_errors ? ' sgbackup-job-error' : '') + '">';
				html += '<button type="button" class="sgbackup-abort-btn" data-nonce="' + SGB.esc(nonce) + '">Abort</button>';
				html += '<div class="sgbackup-job-title">' + SGB.esc(job.job_type) + ' backup [' + SGB.esc(nonce) + '] (' + elapsed + ')</div>';

				// Progress bar
				var pct = 0;
				var progressText = '';
				if (!job.files_phase_complete && job.files_total_count > 0) {
					pct = Math.round((job.files_added_count / job.files_total_count) * 100);
					progressText = 'Files: ' + job.files_added_count + ' / ' + job.files_total_count + ' (' + pct + '%)';
				} else if (job.files_phase_complete && !job.db_phase_complete && job.db_tables_total > 0) {
					pct = Math.round((job.db_tables_done / job.db_tables_total) * 100);
					progressText = 'Tables: ' + job.db_tables_done + ' / ' + job.db_tables_total + ' (' + pct + '%)';
				} else if (job.files_phase_complete && job.db_phase_complete && !job.upload_phase_complete) {
					pct = 75;
					progressText = 'Uploading to remote storage...';
				} else if (job.files_phase_complete && job.db_phase_complete) {
					pct = 100;
					progressText = 'Finalizing...';
				}
				if (progressText) {
					html += '<div style="background:#e0e0e0;border-radius:3px;height:20px;margin:6px 0;overflow:hidden;">';
					html += '<div style="background:#2271b1;height:100%;width:' + pct + '%;min-width:2%;transition:width 0.3s;line-height:20px;color:#fff;font-size:11px;padding:0 6px;white-space:nowrap;">' + SGB.esc(progressText) + '</div>';
					html += '</div>';
				}

				// Current activity
				html += '<div class="sgbackup-job-phase" style="font-weight:600;">' + SGB.esc(job.phase || job.jobstatus) + '</div>';
				if (job.last_activity) {
					html += '<div class="sgbackup-job-log" style="color:#666;font-size:12px;">' + SGB.esc(job.last_activity) + '</div>';
				} else if (job.last_log) {
					html += '<div class="sgbackup-job-log" style="color:#666;font-size:12px;">' + SGB.esc(job.last_log) + '</div>';
				}

				// Phase steps
				html += '<div class="sgbackup-job-steps" style="margin-top:4px;">' + steps.join(' &rarr; ') + '</div>';
				html += entityDetail;

				// Errors
				if (job.has_errors && job.errors && job.errors.length) {
					html += '<div class="sgbackup-job-error-msg" style="color:#d63638;margin-top:4px;">';
					for (var ei = 0; ei < job.errors.length; ei++) {
						html += SGB.esc(job.errors[ei]) + '<br>';
					}
					html += '</div>';
				}

				// View log button
				html += '<div style="margin-top:6px;">';
				html += '<button type="button" class="button button-small sgbackup-viewlog-btn" data-nonce="' + SGB.esc(nonce) + '">View Log</button>';
				html += '</div>';

				html += '</div>';
			});
			$('#sgbackup-active-jobs-list').html(html);

			// Bind abort buttons
			$('.sgbackup-abort-btn').off('click').on('click', function() {
				var nonce = $(this).data('nonce');
				self.ajax('activejobs_delete', { job_id: nonce });
			});

			// Bind view-log buttons - opens the separate log panel below
			$('.sgbackup-viewlog-btn').off('click').on('click', function() {
				var nonce = $(this).data('nonce');
				self._openLogPanel(nonce);
			});
		},

		_logPanelNonce: null,

		_openLogPanel: function(nonce) {
			var self = this;
			this._logPanelNonce = nonce;
			var $panel = $('#sgbackup-log-panel');
			var $content = $('#sgbackup-log-content');

			$panel.show();
			$content.html('<em>Loading log...</em>');

			this._fetchLog(nonce);

			// Bind refresh and close (only once)
			$('#sgbackup-log-refresh').off('click').on('click', function() {
				self._fetchLog(self._logPanelNonce);
			});
			$('#sgbackup-log-close').off('click').on('click', function() {
				$('#sgbackup-log-panel').hide();
				self._logPanelNonce = null;
			});
		},

		_fetchLog: function(nonce) {
			var $content = $('#sgbackup-log-content');
			// Capture scroll state BEFORE replacing content
			var el = $content[0];
			var wasNearBottom = !el || (el.scrollHeight - el.scrollTop - el.clientHeight < 100);

			this.ajax('get_log', { nonce: nonce }, function(data) {
				if (data && data.log) {
					var logHtml = SGB.esc(data.log).replace(/\n/g, '<br>');
					$content.html(logHtml);
					if (wasNearBottom) {
						$content[0].scrollTop = $content[0].scrollHeight;
					}
				}
			});
		},

		refreshBackupsList: function() {
			// Show completion notice and reload just the backup table via AJAX
			$('#sgbackup-backup-status').text('Backup complete! Refreshing list...').addClass('success');
			var self = this;
			$.get(window.location.href, function(responseHtml) {
				var $newPage = $(responseHtml);
				var $newTable = $newPage.find('.sgbackup-backups-table').closest('#tab-backup-restore');
				if ($newTable.length) {
					$('#tab-backup-restore').html($newTable.html());
					self.bindMassActions();
					self.bindPagination();
				}
				setTimeout(function() { $('#sgbackup-backup-status').text('').removeClass('success error'); }, 3000);
			}).fail(function() {
				// Fallback: if AJAX refresh fails, show a manual reload link
				$('#sgbackup-backup-status').html('Backup complete! <a href="javascript:location.reload()">Refresh page</a> to see results.').addClass('success');
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
				$('#sgbackup-subsite-import').hide();
				$('#sgbackup-target-subsite-select').val('');
				$('#sgbackup-subsite-newdomain-row').hide();
				$('input[name="sgbackup_target_subsite_newdomain"]').val('');
				$('input[name="sgbackup_target_subsite_domain"]').val('');
				$('input[name="sgbackup_target_subsite_id"]').val('');

				// Initialize DB restore mode dropdown from site default
				$('select[name="sgbackup_db_restore_mode"]').val(sgbackup_settings.default_db_restore_mode || 0);

				// Initialize subsite mode from site default
				$('select[name="sgbackup_subsite_mode"]').val(sgbackup_settings.default_subsite_mode || 0);

				// Show subsite mode when multisite AND DB mode = Migrate (0) or Overwrite (1)
				var initDbMode = $('select[name="sgbackup_db_restore_mode"]').val();
				$('#sgbackup-subsite-mode-row').toggle(sgbackup_settings.is_multisite && (initDbMode === '0' || initDbMode === '1'));

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
				// Validate: if subsite import picker is visible, a target must be selected
				if ($('#sgbackup-subsite-import').is(':visible')) {
					var targetDomain = $.trim($('input[name="sgbackup_target_subsite_domain"]').val());
					if (!targetDomain) {
						alert('Please select a target subsite before proceeding. This is a per-subsite backup and must be imported into a specific subsite.');
						return;
					}
				}
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

			// Close button after restore completes
			$(document).on('click', '#sgbackup-restore-close', function() {
				$('#sgbackup-restore-modal').dialog('close');
				$('#sgbackup-restore-done').hide();
			});

			// Show/hide subsite mode based on DB restore mode (multisite only)
			$(document).on('change', 'select[name="sgbackup_db_restore_mode"]', function() {
				var val = $(this).val();
				var showSubsite = sgbackup_settings.is_multisite && (val === '0' || val === '1');
				$('#sgbackup-subsite-mode-row').toggle(showSubsite);
				if (!showSubsite) {
					$('#sgbackup-subsite-map-container').hide();
					$('#sgbackup-subsite-import').hide();
				}
			});

			// Subsite import picker: show/hide "Create new" text input and sync hidden fields
			$(document).on('change', '#sgbackup-target-subsite-select', function() {
				var val = $(this).val();
				if (val === '__new__') {
					$('#sgbackup-subsite-newdomain-row').show();
					$('input[name="sgbackup_target_subsite_domain"]').val('');
					$('input[name="sgbackup_target_subsite_id"]').val('');
				} else if (val) {
					$('#sgbackup-subsite-newdomain-row').hide();
					var domain = $(this).find(':selected').data('domain') || '';
					$('input[name="sgbackup_target_subsite_domain"]').val(domain);
					$('input[name="sgbackup_target_subsite_id"]').val(val);
				} else {
					$('#sgbackup-subsite-newdomain-row').hide();
					$('input[name="sgbackup_target_subsite_domain"]').val('');
					$('input[name="sgbackup_target_subsite_id"]').val('');
				}
			});
			$(document).on('input', 'input[name="sgbackup_target_subsite_newdomain"]', function() {
				$('input[name="sgbackup_target_subsite_domain"]').val($.trim($(this).val()));
			});

			// Show/hide subsite mapping container based on subsite mode
			$(document).on('change', 'select[name="sgbackup_subsite_mode"]', function() {
				var mode = $(this).val();
				var showMap = (mode === '0' || mode === '1' || mode === '3' || mode === '5');
				var showImport = (mode === '4');
				$('#sgbackup-subsite-map-container').toggle(showMap);
				$('#sgbackup-subsite-import').toggle(showImport);
				if (showMap) {
					self.loadSubsiteMap(mode);
				}
				if (showImport) {
					self.loadSubsiteImportPicker();
				}
			});
		},

		/**
		 * Populate the subsite import picker with current destination subsites.
		 * Used by mode 4 (Import as subsite) to let the user choose where to restore.
		 */
		loadSubsiteImportPicker: function() {
			var timestamp = $('#sgbackup-restore-modal').data('timestamp');
			var $sel = $('#sgbackup-target-subsite-select');

			// Avoid re-loading if already populated for this timestamp
			if ($sel.data('loaded-ts') === timestamp && $sel.find('option').length > 2) return;

			$('#sgbackup-subsite-import-notice').text('Choose which subsite to restore this backup into:');
			$sel.find('option:not(:first)').remove();

			this.ajax('get_backup_contents', { timestamp: timestamp }, function(data) {
				var blogs = data.current_multisite_blogs || [];
				for (var i = 0; i < blogs.length; i++) {
					var b = blogs[i];
					if (b.blog_id === 1) continue; // Skip main site - import targets subsites only
					var label = SGB.esc(b.domain);
					if (b.path && b.path !== '/') label += SGB.esc(b.path);
					if (b.blogname) label += ' \u2014 ' + SGB.esc(b.blogname);
					$sel.append('<option value="' + parseInt(b.blog_id) + '" data-domain="' + SGB.esc(b.domain) + '">' + label + '</option>');
				}
				$sel.append('<option value="__new__">+ Create new subsite...</option>');
				$sel.data('loaded-ts', timestamp);
			});
		},

		/**
		 * Load subsite list from the backup and build mode-specific mapping table.
		 * @param {string} mode - Subsite mode: '0' mirror, '1' main-only, '3' custom
		 */
		loadSubsiteMap: function(mode) {
			mode = mode || '0';
			var timestamp = $('#sgbackup-restore-modal').data('timestamp');
			var $container = $('#sgbackup-subsite-map-container');

			// Check if already populated for this timestamp+mode
			if ($container.data('loaded-ts') === timestamp && $container.data('loaded-mode') === mode && $container.find('table').length) return;

			$container.html('<p><em>Loading subsite list from backup...</em></p>');

			this.ajax('get_backup_contents', { timestamp: timestamp }, function(data) {
				if (!data.multisite_blogs || data.multisite_blogs.length === 0) {
					$container.html('<p><em>No multisite blogs detected in this backup.</em></p>');
					return;
				}

				var oldHost = '';
				var newHost = '';
				if (data.old_siteurl && data.current_siteurl) {
					oldHost = data.old_siteurl.replace(/https?:\/\//, '').replace(/\/.*/, '');
					newHost = data.current_siteurl.replace(/https?:\/\//, '').replace(/\/.*/, '');
				}

				// Normalize source blogs: support both structured {blog_id, domain, path} and plain strings
				var sourceBlogs = [];
				for (var i = 0; i < data.multisite_blogs.length; i++) {
					var entry = data.multisite_blogs[i];
					if (typeof entry === 'object' && entry.domain) {
						sourceBlogs.push({ blog_id: entry.blog_id, domain: entry.domain, path: entry.path || '/' });
					} else {
						sourceBlogs.push({ blog_id: 0, domain: entry, path: '/' });
					}
				}

				// Sort: blog_id=1 first, then by blog_id
				sourceBlogs.sort(function(a, b) {
					if (a.blog_id === 1) return -1;
					if (b.blog_id === 1) return 1;
					return a.blog_id - b.blog_id;
				});

				// Build destination blog lookup (current site)
				var destBlogs = data.current_multisite_blogs || [];
				var hasDestBlogs = destBlogs.length > 0;

				var html = '';

				// ===== Mode 1: Main-only preview =====
				if (mode === '1') {
					html += '<p><strong>Main-only mode preview:</strong></p>';
					html += '<table class="sgbackup-subsite-map-table widefat">';
					html += '<thead><tr><th>Source (Backup)</th><th>Action</th></tr></thead><tbody>';

					for (var i = 0; i < sourceBlogs.length; i++) {
						var src = sourceBlogs[i];
						var isMainSite = (src.blog_id === 1);
						var srcLabel = SGB.esc(src.domain);
						if (src.blog_id > 0) srcLabel += ' <small style="color:#888;">#' + src.blog_id + '</small>';

						html += '<tr class="sgbackup-subsite-map-row" data-source-domain="' + SGB.esc(src.domain) + '">';
						html += '<td>' + srcLabel + '</td>';
						if (isMainSite) {
							html += '<td><span class="sgbackup-action-badge sgbackup-action-overwrite">RESTORE</span></td>';
						} else {
							html += '<td><span class="sgbackup-action-badge sgbackup-action-skip">REMOVE</span></td>';
						}
						html += '</tr>';
					}

					html += '</tbody></table>';
					html += '<p class="description">Only the main site will be restored. All ' + (sourceBlogs.length - 1) + ' subsites will be removed.</p>';

					$container.html(html).data('loaded-ts', timestamp).data('loaded-mode', mode);
					return;
				}

				// ===== Mode 0: Mirror with checkboxes =====
				if (mode === '0') {
					html += '<p><strong>Select subsites to include in mirror:</strong></p>';
					html += '<table class="sgbackup-subsite-map-table widefat">';
					html += '<thead><tr><th class="sgbackup-include-col">Include</th><th>Source (Backup)</th><th>Destination (Mirror)</th></tr></thead><tbody>';

					for (var i = 0; i < sourceBlogs.length; i++) {
						var src = sourceBlogs[i];
						var isMainSite = (src.blog_id === 1);
						var mirrorDomain = SGB.mirrorDomain(src.domain, oldHost, newHost);
						var srcLabel = SGB.esc(src.domain);
						if (src.blog_id > 0) srcLabel += ' <small style="color:#888;">#' + src.blog_id + '</small>';

						html += '<tr class="sgbackup-subsite-map-row" data-source-domain="' + SGB.esc(src.domain) + '">';
						html += '<td class="sgbackup-include-col">';
						if (isMainSite) {
							html += '<input type="checkbox" class="sgbackup-subsite-include" checked disabled />';
						} else {
							html += '<input type="checkbox" class="sgbackup-subsite-include" checked />';
						}
						html += '</td>';
						html += '<td>' + srcLabel + '</td>';
						html += '<td>' + SGB.esc(mirrorDomain) + '</td>';
						html += '</tr>';
					}

					html += '</tbody></table>';
					html += '<p class="description">Uncheck subsites you don\'t want. Unchecked subsites will be removed from the destination.</p>';

					$container.html(html).data('loaded-ts', timestamp).data('loaded-mode', mode);
					return;
				}

				// ===== Mode 5: Batch subsite import =====
				if (mode === '5') {
					// Build destination options with blog_id values (for overwrite)
					var destBlogOptionsHtml = '';
					if (hasDestBlogs) {
						for (var d = 0; d < destBlogs.length; d++) {
							var db = destBlogs[d];
							if (db.blog_id === 1) continue; // skip main site
							var label = db.domain + (db.blogname ? ' (' + db.blogname + ')' : '') + ' #' + db.blog_id;
							destBlogOptionsHtml += '<option value="' + parseInt(db.blog_id) + '" data-domain="' + SGB.esc(db.domain) + '">' + SGB.esc(label) + '</option>';
						}
					}

					// Auto-match helper for mode 5
					var findDestMatchBatch = function(srcDomain) {
						if (!hasDestBlogs) return null;
						for (var d = 0; d < destBlogs.length; d++) {
							if (destBlogs[d].blog_id === 1) continue;
							if (destBlogs[d].domain === srcDomain) return destBlogs[d];
						}
						if (oldHost && newHost) {
							var oldHostEsc = oldHost.replace(/\./g, '\\.');
							var prefixMatch = srcDomain.match(new RegExp('^(.+)\\.' + oldHostEsc + '$'));
							if (prefixMatch) {
								var srcPrefix = prefixMatch[1];
								var newHostEsc = newHost.replace(/\./g, '\\.');
								for (var d = 0; d < destBlogs.length; d++) {
									if (destBlogs[d].blog_id === 1) continue;
									var dMatch = destBlogs[d].domain.match(new RegExp('^(.+)\\.' + newHostEsc + '$'));
									if (dMatch && dMatch[1] === srcPrefix) return destBlogs[d];
								}
							}
						}
						return null;
					};

					html += '<p><strong>Select subsites to import (main site is protected):</strong></p>';
					html += '<table class="sgbackup-subsite-map-table widefat">';
					html += '<thead><tr><th>Source (Backup)</th><th>Action</th><th>Destination</th></tr></thead><tbody>';

					for (var i = 0; i < sourceBlogs.length; i++) {
						var src = sourceBlogs[i];
						var isMainSite = (src.blog_id === 1);
						var mirrorDomain = SGB.mirrorDomain(src.domain, oldHost, newHost);
						var srcLabel = SGB.esc(src.domain);
						if (src.blog_id > 0) srcLabel += ' <small style="color:#888;">#' + src.blog_id + '</small>';

						html += '<tr class="sgbackup-subsite-map-row" data-source-domain="' + SGB.esc(src.domain) + '" data-source-blog-id="' + (src.blog_id || 0) + '">';
						html += '<td>' + srcLabel + '</td>';

						if (isMainSite) {
							// Main site is always protected
							html += '<td><span class="sgbackup-action-badge sgbackup-action-skip" style="background:#e8f4fd;color:#0073aa;">PROTECTED</span></td>';
							html += '<td style="color:#888;font-style:italic;">Not imported</td>';
						} else {
							// Determine default action
							var destMatch = findDestMatchBatch(src.domain);
							var defaultAction = destMatch ? 'overwrite' : 'create';

							html += '<td>';
							html += '<select class="sgbackup-subsite-action">';
							if (destBlogOptionsHtml) {
								html += '<option value="overwrite"' + (defaultAction === 'overwrite' ? ' selected' : '') + '>Overwrite existing</option>';
							}
							html += '<option value="create"' + (defaultAction === 'create' ? ' selected' : '') + '>Create new site</option>';
							html += '<option value="skip">Skip</option>';
							html += '</select>';
							html += '</td>';

							// Destination column
							html += '<td class="sgbackup-subsite-dest-cell">';
							if (defaultAction === 'overwrite' && destBlogOptionsHtml) {
								html += '<select class="sgbackup-subsite-dest-select">' + destBlogOptionsHtml + '</select>';
								html += '<input type="text" class="sgbackup-subsite-dest-input regular-text" value="' + SGB.esc(mirrorDomain) + '" style="display:none;width:100%;" />';
								html += '<span class="sgbackup-subsite-skip-label" style="display:none;color:#888;font-style:italic;">Skipped</span>';
							} else {
								html += '<select class="sgbackup-subsite-dest-select" style="display:none;">' + destBlogOptionsHtml + '</select>';
								html += '<input type="text" class="sgbackup-subsite-dest-input regular-text" value="' + SGB.esc(mirrorDomain) + '" style="width:100%;" />';
								html += '<span class="sgbackup-subsite-skip-label" style="display:none;color:#888;font-style:italic;">Skipped</span>';
							}
							html += '<span class="sgbackup-collision-warning" style="display:none;"> Collision!</span>';
							html += '</td>';
						}
						html += '</tr>';
					}

					html += '</tbody></table>';
					html += '<p class="description">Main site tables (wp_options, wp_users, etc.) are never imported. Only selected subsites will be restored.</p>';

					$container.html(html).data('loaded-ts', timestamp).data('loaded-mode', mode);

					// Pre-select matched destinations
					$container.find('.sgbackup-subsite-map-row').each(function() {
						var $row = $(this);
						var srcDomain = $row.data('source-domain');
						var blogId = parseInt($row.data('source-blog-id'));
						if (blogId === 1) return;
						var destMatch = findDestMatchBatch(srcDomain);
						if (destMatch) {
							$row.find('.sgbackup-subsite-dest-select').val(destMatch.blog_id);
						}
					});

					// Bind action change events (reuse same handler as mode 3)
					$container.on('change', '.sgbackup-subsite-action', function() {
						var $row = $(this).closest('.sgbackup-subsite-map-row');
						var action = $(this).val();
						var $destSelect = $row.find('.sgbackup-subsite-dest-select');
						var $destInput = $row.find('.sgbackup-subsite-dest-input');
						var $skipLabel = $row.find('.sgbackup-subsite-skip-label');

						if (action === 'overwrite') {
							$destSelect.show();
							$destInput.hide();
							$skipLabel.hide();
						} else if (action === 'create') {
							$destSelect.hide();
							$destInput.show();
							$skipLabel.hide();
						} else {
							$destSelect.hide();
							$destInput.hide();
							$skipLabel.show();
						}
						SGB.checkSubsiteCollisions();
					});

					$container.on('change', '.sgbackup-subsite-dest-select, .sgbackup-subsite-dest-input', function() {
						SGB.checkSubsiteCollisions();
					});

					SGB.checkSubsiteCollisions();
					return;
				}

				// ===== Mode 3: Custom mapping (existing logic) =====

				// Build destination options HTML for <select>
				var destOptionsHtml = '';
				if (hasDestBlogs) {
					for (var d = 0; d < destBlogs.length; d++) {
						var db = destBlogs[d];
						var label = db.domain + (db.blogname ? ' (' + db.blogname + ')' : '') + ' #' + db.blog_id;
						destOptionsHtml += '<option value="' + SGB.esc(db.domain) + '">' + SGB.esc(label) + '</option>';
					}
				}

				// Auto-match helper: find destination blog matching the source
				var findDestMatch = function(srcDomain) {
					if (!hasDestBlogs) return null;

					// 1. Exact domain match
					for (var d = 0; d < destBlogs.length; d++) {
						if (destBlogs[d].domain === srcDomain) return destBlogs[d].domain;
					}

					// 2. Subdomain prefix match (blog.old.com → blog.new.com)
					if (oldHost && newHost) {
						var srcPrefix = '';
						var oldHostEsc = oldHost.replace(/\./g, '\\.');
						var prefixMatch = srcDomain.match(new RegExp('^(.+)\\.' + oldHostEsc + '$'));
						if (prefixMatch) {
							srcPrefix = prefixMatch[1];
						}
						if (srcPrefix) {
							for (var d = 0; d < destBlogs.length; d++) {
								var destPrefix = '';
								var newHostEsc = newHost.replace(/\./g, '\\.');
								var dMatch = destBlogs[d].domain.match(new RegExp('^(.+)\\.' + newHostEsc + '$'));
								if (dMatch) destPrefix = dMatch[1];
								if (srcPrefix === destPrefix) return destBlogs[d].domain;
							}
						}
					}

					return null;
				};

				html += '<p><strong>Map each backup subsite to a destination:</strong></p>';
				html += '<table class="sgbackup-subsite-map-table widefat">';
				html += '<thead><tr><th>Source (Backup)</th><th>Action</th><th>Destination</th></tr></thead><tbody>';

				for (var i = 0; i < sourceBlogs.length; i++) {
					var src = sourceBlogs[i];
					var isMainSite = (src.blog_id === 1);
					var mirrorDomain = SGB.mirrorDomain(src.domain, oldHost, newHost);

					// Determine default action and destination
					var defaultAction, defaultDest;
					if (isMainSite) {
						defaultAction = 'overwrite';
						defaultDest = newHost || mirrorDomain;
					} else {
						var destMatch = findDestMatch(src.domain);
						if (destMatch) {
							defaultAction = 'overwrite';
							defaultDest = destMatch;
						} else {
							defaultAction = 'create';
							defaultDest = mirrorDomain;
						}
					}

					var srcLabel = SGB.esc(src.domain);
					if (src.blog_id > 0) srcLabel += ' <small style="color:#888;">#' + src.blog_id + '</small>';

					html += '<tr class="sgbackup-subsite-map-row" data-source-domain="' + SGB.esc(src.domain) + '">';
					html += '<td>' + srcLabel + '</td>';

					// Action dropdown
					html += '<td>';
					if (isMainSite) {
						html += '<select class="sgbackup-subsite-action" disabled><option value="overwrite">Overwrite main site</option></select>';
						html += '<input type="hidden" class="sgbackup-subsite-action-val" value="overwrite" />';
					} else {
						html += '<select class="sgbackup-subsite-action">';
						if (hasDestBlogs) {
							html += '<option value="overwrite"' + (defaultAction === 'overwrite' ? ' selected' : '') + '>Overwrite existing</option>';
						}
						html += '<option value="create"' + (defaultAction === 'create' ? ' selected' : '') + '>Create new site</option>';
						html += '<option value="skip">Skip</option>';
						html += '</select>';
					}
					html += '</td>';

					// Destination column
					html += '<td class="sgbackup-subsite-dest-cell">';
					if (isMainSite) {
						var mainDestLabel = newHost || mirrorDomain;
						if (hasDestBlogs && destBlogs[0]) {
							mainDestLabel = destBlogs[0].domain + (destBlogs[0].blogname ? ' (' + destBlogs[0].blogname + ')' : '');
						}
						html += '<span>' + SGB.esc(mainDestLabel) + '</span>';
						html += '<input type="hidden" class="sgbackup-subsite-dest-select" value="' + SGB.esc(defaultDest) + '" />';
					} else if (defaultAction === 'overwrite' && hasDestBlogs) {
						html += '<select class="sgbackup-subsite-dest-select">' + destOptionsHtml + '</select>';
						html += '<input type="text" class="sgbackup-subsite-dest-input regular-text" value="' + SGB.esc(mirrorDomain) + '" style="display:none;width:100%;" />';
						html += '<span class="sgbackup-subsite-skip-label" style="display:none;color:#888;font-style:italic;">Skipped</span>';
					} else {
						html += '<select class="sgbackup-subsite-dest-select" style="display:none;">' + destOptionsHtml + '</select>';
						html += '<input type="text" class="sgbackup-subsite-dest-input regular-text" value="' + SGB.esc(mirrorDomain) + '" style="width:100%;" />';
						html += '<span class="sgbackup-subsite-skip-label" style="display:none;color:#888;font-style:italic;">Skipped</span>';
					}
					html += '<span class="sgbackup-collision-warning" style="display:none;"> Collision!</span>';
					html += '</td>';

					html += '</tr>';
				}

				html += '</tbody></table>';
				html += '<p class="description">Choose an action for each subsite: overwrite an existing destination site, create a new site, or skip.</p>';

				$container.html(html).data('loaded-ts', timestamp).data('loaded-mode', mode);

				// Pre-select matched destinations
				$container.find('.sgbackup-subsite-map-row').each(function() {
					var $row = $(this);
					var srcDomain = $row.data('source-domain');
					var src = null;
					for (var s = 0; s < sourceBlogs.length; s++) {
						if (sourceBlogs[s].domain === srcDomain) { src = sourceBlogs[s]; break; }
					}
					if (!src || src.blog_id === 1) return;

					var destMatch = findDestMatch(src.domain);
					if (destMatch) {
						$row.find('.sgbackup-subsite-dest-select').val(destMatch);
					}
				});

				// Bind action change events
				$container.on('change', '.sgbackup-subsite-action', function() {
					var $row = $(this).closest('.sgbackup-subsite-map-row');
					var action = $(this).val();
					var $destSelect = $row.find('.sgbackup-subsite-dest-select');
					var $destInput = $row.find('.sgbackup-subsite-dest-input');
					var $skipLabel = $row.find('.sgbackup-subsite-skip-label');

					if (action === 'overwrite') {
						$destSelect.show();
						$destInput.hide();
						$skipLabel.hide();
					} else if (action === 'create') {
						$destSelect.hide();
						$destInput.show();
						$skipLabel.hide();
					} else { // skip
						$destSelect.hide();
						$destInput.hide();
						$skipLabel.show();
					}
					SGB.checkSubsiteCollisions();
				});

				// Bind destination change for collision detection
				$container.on('change', '.sgbackup-subsite-dest-select, .sgbackup-subsite-dest-input', function() {
					SGB.checkSubsiteCollisions();
				});

				SGB.checkSubsiteCollisions();
			}, function(msg) {
				$container.html('<p class="sgbackup-verify-fail">Error loading subsites: ' + SGB.esc(msg) + '</p>');
			});
		},

		/**
		 * Check for destination collisions in the subsite mapping table.
		 */
		checkSubsiteCollisions: function() {
			var destinations = {};
			$('.sgbackup-subsite-map-row').each(function() {
				var $row = $(this);
				var action = $row.find('.sgbackup-subsite-action').val() || $row.find('.sgbackup-subsite-action-val').val();
				if (action === 'skip') return;

				var dest = '';
				if (action === 'overwrite') {
					dest = $row.find('.sgbackup-subsite-dest-select').val();
				} else {
					dest = $.trim($row.find('.sgbackup-subsite-dest-input').val());
				}
				if (!dest) return;

				if (!destinations[dest]) destinations[dest] = [];
				destinations[dest].push($row);
			});

			// Clear all warnings first
			$('.sgbackup-collision-warning').hide();

			// Show warnings for collisions
			for (var dest in destinations) {
				if (destinations[dest].length > 1) {
					for (var i = 0; i < destinations[dest].length; i++) {
						destinations[dest][i].find('.sgbackup-collision-warning').show();
					}
				}
			}
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

				// Detect subsite backup and show import UI with subsite picker
				if (data.is_subsite_backup && sgbackup_settings.is_multisite) {
					var srcLabel = data.subsite_domain || ('blog #' + data.subsite_blog_id);
					html += '<tr><td><strong>Subsite backup:</strong></td><td>' + SGB.esc(srcLabel) + '</td></tr>';
					$('#sgbackup-subsite-import-notice').text('This is a per-subsite backup from ' + srcLabel + '. Select which subsite to restore it into:');

					// Populate the subsite picker dropdown
					var $sel = $('#sgbackup-target-subsite-select');
					$sel.find('option:not(:first)').remove();
					var blogs = data.current_multisite_blogs || [];
					for (var bi = 0; bi < blogs.length; bi++) {
						var b = blogs[bi];
						if (b.blog_id === 1) continue; // Skip main site
						var label = SGB.esc(b.domain);
						if (b.path && b.path !== '/') label += SGB.esc(b.path);
						if (b.blogname) label += ' \u2014 ' + SGB.esc(b.blogname);
						$sel.append('<option value="' + parseInt(b.blog_id) + '" data-domain="' + SGB.esc(b.domain) + '">' + label + '</option>');
					}
					$sel.append('<option value="__new__">+ Create new subsite...</option>');

					$('#sgbackup-subsite-import').show();
					// Force Import-as-subsite mode and hide normal subsite mode row
					$('select[name="sgbackup_subsite_mode"]').val('4');
					$('#sgbackup-subsite-mode-row').hide();
				} else {
					$('#sgbackup-subsite-import').hide();
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
						var keepChecked = sgbackup_settings.default_keep_protocol ? ' checked' : '';
						warnings.push('<label><input type="checkbox" id="sgbackup-keep-protocol" value="1"' + keepChecked + ' /> Keep original protocol (' + oldScheme.toUpperCase() + ') - do not convert URLs to ' + newScheme.toUpperCase() + '</label>');
					}

					// Multisite blog mapping
					if (data.multisite_blogs && data.multisite_blogs.length > 0) {
						var oldHost = data.old_siteurl.replace(/https?:\/\//, '').replace(/\/.*/, '');
						var newHost = data.current_siteurl.replace(/https?:\/\//, '').replace(/\/.*/, '');
						html += '<tr><td><strong>Multisite blogs:</strong></td><td>' + data.multisite_blogs.length + ' sites</td></tr>';

						// Show selected subsite mode
						var subsiteModeVal = $('select[name="sgbackup_subsite_mode"]').val() || '0';
						var subsiteModeLabels = {'0': 'Mirror all subsites', '1': 'Main site only', '2': 'Keep original domains', '3': 'Custom mapping', '4': 'Import as subsite', '5': 'Batch subsite import (main site protected)'};
						html += '<tr><td><strong>Subsite mode:</strong></td><td>' + SGB.esc(subsiteModeLabels[subsiteModeVal] || 'Mirror') + '</td></tr>';

						if (subsiteModeVal === '1') {
							warnings.push('Main-only mode: ' + (data.multisite_blogs.length - 1) + ' subsites will be REMOVED from wp_blogs after restore.');
						} else if (subsiteModeVal === '5') {
							// Batch mode verification summary
							var batchHtml = '<table class="widefat" style="margin-top:10px;"><thead><tr><th>Source</th><th>Action</th><th>Destination</th></tr></thead><tbody>';
							var batchCount = 0;
							$('.sgbackup-subsite-map-row').each(function() {
								var blogId = parseInt($(this).data('source-blog-id'));
								var srcDomain = $(this).data('source-domain');
								var actionLabel, actionClass, destLabel;
								if (blogId === 1) {
									actionLabel = 'PROTECTED';
									actionClass = 'sgbackup-action-skip';
									destLabel = 'Not imported';
								} else {
									var action = $(this).find('.sgbackup-subsite-action').val();
									if (action === 'skip') {
										actionLabel = 'SKIP';
										actionClass = 'sgbackup-action-skip';
										destLabel = '\u2014';
									} else if (action === 'overwrite') {
										actionLabel = 'OVERWRITE';
										actionClass = 'sgbackup-action-overwrite';
										destLabel = $(this).find('.sgbackup-subsite-dest-select option:selected').text();
										batchCount++;
									} else {
										actionLabel = 'CREATE';
										actionClass = 'sgbackup-action-create';
										destLabel = $.trim($(this).find('.sgbackup-subsite-dest-input').val());
										batchCount++;
									}
								}
								var badge = '<span class="sgbackup-action-badge ' + actionClass + '">' + actionLabel + '</span>';
								batchHtml += '<tr><td>' + SGB.esc(srcDomain) + '</td><td>' + badge + '</td>';
								batchHtml += '<td>' + (destLabel === '\u2014' ? '\u2014' : SGB.esc(destLabel)) + '</td></tr>';
							});
							batchHtml += '</tbody></table>';
							html += '<tr><td colspan="2">' + batchHtml + '</td></tr>';
							warnings.push('Batch mode: ' + batchCount + ' subsite(s) will be imported. Main site tables are never touched.');
						} else if (subsiteModeVal === '0' || subsiteModeVal === '3') {
							var mapHtml = '<table class="widefat" style="margin-top:10px;"><thead><tr><th>Source</th><th>Action</th><th>Destination</th></tr></thead><tbody>';
							for (var b = 0; b < data.multisite_blogs.length; b++) {
								var blogEntry = data.multisite_blogs[b];
								var oldBlog = (typeof blogEntry === 'object') ? blogEntry.domain : blogEntry;
								var newBlog, actionLabel, actionClass;

								if (subsiteModeVal === '3') {
									// Read from custom mapping UI
									var $row = $('.sgbackup-subsite-map-row[data-source-domain="' + oldBlog + '"]');
									if ($row.length) {
										var rowAction = $row.find('.sgbackup-subsite-action').val() || $row.find('.sgbackup-subsite-action-val').val();
										if (rowAction === 'skip') {
											actionLabel = 'SKIP';
											actionClass = 'sgbackup-action-skip';
											newBlog = '\u2014';
										} else if (rowAction === 'overwrite') {
											actionLabel = 'OVERWRITE';
											actionClass = 'sgbackup-action-overwrite';
											newBlog = $row.find('.sgbackup-subsite-dest-select').val();
										} else {
											actionLabel = 'CREATE';
											actionClass = 'sgbackup-action-create';
											newBlog = $.trim($row.find('.sgbackup-subsite-dest-input').val());
										}
									} else {
										actionLabel = 'CREATE';
										actionClass = 'sgbackup-action-create';
										newBlog = SGB.mirrorDomain(oldBlog, oldHost, newHost);
									}
								} else {
									// Mirror mode - check checkbox state for skips
									var $mirrorRow = $('.sgbackup-subsite-map-row[data-source-domain="' + oldBlog + '"]');
									var $mirrorCb = $mirrorRow.find('.sgbackup-subsite-include');
									if ($mirrorCb.length && !$mirrorCb.is(':checked') && !$mirrorCb.is(':disabled')) {
										actionLabel = 'SKIP';
										actionClass = 'sgbackup-action-skip';
										newBlog = '\u2014';
									} else {
										actionLabel = 'MIRROR';
										actionClass = 'sgbackup-action-create';
										newBlog = SGB.mirrorDomain(oldBlog, oldHost, newHost);
									}
								}
								var badge = '<span class="sgbackup-action-badge ' + actionClass + '">' + actionLabel + '</span>';
								mapHtml += '<tr><td>' + SGB.esc(oldBlog) + '</td><td>' + badge + '</td>';
								mapHtml += '<td>' + (newBlog === '\u2014' ? '\u2014' : SGB.esc(newBlog)) + '</td></tr>';
							}
							mapHtml += '</tbody></table>';
							html += '<tr><td colspan="2">' + mapHtml + '</td></tr>';
						}

						// Count skipped subsites in mirror mode
						if (subsiteModeVal === '0') {
							var skipCount = 0;
							$('.sgbackup-subsite-map-row').each(function() {
								var $cb = $(this).find('.sgbackup-subsite-include');
								if ($cb.length && !$cb.is(':checked') && !$cb.is(':disabled')) skipCount++;
							});
							if (skipCount > 0) {
								warnings.push(skipCount + ' subsite(s) will be REMOVED (unchecked in mirror mode). Their blog entries and tables will be deleted.');
							}
						}

						if (data.multisite_blogs.length > 1 && subsiteModeVal !== '1') {
							warnings.push('Subsites require wildcard DNS (*.' + newHost + ') and web server config to accept subdomains.');
						}
						warnings.push('This is a multisite migration. All ' + data.multisite_blogs.length + ' blog domains will be updated automatically.');

						// Check if any blogs have independent domains (not subdomains of old main site)
						if (subsiteModeVal === '0' || subsiteModeVal === '3') {
							var independentCount = 0;
							for (var ib = 0; ib < data.multisite_blogs.length; ib++) {
								var ibDomain = (typeof data.multisite_blogs[ib] === 'object') ? data.multisite_blogs[ib].domain : data.multisite_blogs[ib];
								if (ibDomain.indexOf(oldHost) === -1) independentCount++;
							}
							if (independentCount > 0) {
								var remapChecked = ' checked';
								warnings.push('<label><input type="checkbox" id="sgbackup-remap-independent" value="1"' + remapChecked + ' /> Remap ' + independentCount + ' independent domain(s) to subdomains of ' + SGB.esc(newHost) + ' (e.g. example.com &rarr; example.' + SGB.esc(newHost) + ')</label>');
							}
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
						// Allow HTML in warnings that start with '<' (checkboxes, labels)
						var w = warnings[i];
						warnHtml += '<li>' + (w.charAt(0) === '<' ? w : SGB.esc(w)) + '</li>';
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
			// Capture subsite state BEFORE hiding divs (visibility check needs them shown)
			var importPickerVisible = $('#sgbackup-subsite-import').is(':visible');
			var subsiteMode = $('select[name="sgbackup_subsite_mode"]').val();
			if (importPickerVisible) {
				subsiteMode = '4';
			}

			// Validate target subsite selection for Import-as-subsite mode
			if (subsiteMode === '4') {
				var targetDomain = $('input[name="sgbackup_target_subsite_domain"]').val();
				var targetId = $('input[name="sgbackup_target_subsite_id"]').val();
				if (!targetDomain && !targetId) {
					SGB.showNotice('Please select a target subsite before restoring. Use the dropdown to choose an existing subsite or create a new one.', 'error');
					return;
				}
			}

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

			// Collect db_restore_mode
			var dbRestoreMode = $('select[name="sgbackup_db_restore_mode"]').val();
			formData.append('db_restore_mode', dbRestoreMode || '0');

			// Subsite mode (already captured above, before divs were hidden)
			formData.append('subsite_mode', subsiteMode || '0');

			// Keep original protocol (skip HTTP/HTTPS conversion)
			if ($('#sgbackup-keep-protocol').is(':checked')) {
				formData.append('keep_protocol', '1');
			}

			// Remap independent domains to subdomains
			var $remapCb = $('#sgbackup-remap-independent');
			if ($remapCb.length) {
				formData.append('remap_independent_domains', $remapCb.is(':checked') ? '1' : '0');
			}

			// Collect subsite mapping for custom mode or mirror-mode skips
			if (subsiteMode === '3') {
				var subsiteMap = {};
				$('.sgbackup-subsite-map-row').each(function() {
					var oldDomain = $(this).data('source-domain');
					var action = $(this).find('.sgbackup-subsite-action').val() || $(this).find('.sgbackup-subsite-action-val').val();
					if (action === 'skip') {
						subsiteMap[oldDomain] = '__skip__';
					} else if (action === 'overwrite') {
						subsiteMap[oldDomain] = $(this).find('.sgbackup-subsite-dest-select').val();
					} else { // create
						subsiteMap[oldDomain] = $.trim($(this).find('.sgbackup-subsite-dest-input').val());
					}
				});
				formData.append('subsite_map', JSON.stringify(subsiteMap));
			} else if (subsiteMode === '0') {
				// Mirror mode: collect unchecked subsites as skips
				var mirrorSkips = {};
				$('.sgbackup-subsite-map-row').each(function() {
					var $cb = $(this).find('.sgbackup-subsite-include');
					if ($cb.length && !$cb.is(':checked') && !$cb.is(':disabled')) {
						mirrorSkips[$(this).data('source-domain')] = '__skip__';
					}
				});
				if (Object.keys(mirrorSkips).length) {
					formData.append('subsite_map', JSON.stringify(mirrorSkips));
				}
			}

			// Mode 5: Batch subsite map
			if (subsiteMode === '5') {
				var batchMap = [];
				$('.sgbackup-subsite-map-row').each(function() {
					var srcBlogId = parseInt($(this).data('source-blog-id'));
					var srcDomain = $(this).data('source-domain');
					if (!srcBlogId || srcBlogId === 1) return; // skip main site
					var action = $(this).find('.sgbackup-subsite-action').val();
					if (!action || action === 'skip') return;
					var entry = {
						source_blog_id: srcBlogId,
						source_domain: srcDomain,
						action: action
					};
					if (action === 'overwrite') {
						entry.target_blog_id = parseInt($(this).find('.sgbackup-subsite-dest-select').val());
					} else if (action === 'create') {
						entry.target_domain = $.trim($(this).find('.sgbackup-subsite-dest-input').val());
					}
					batchMap.push(entry);
				});
				if (batchMap.length === 0) {
					SGB.showNotice('No subsites selected for batch import. Please select at least one subsite to overwrite or create.', 'error');
					$('#sgbackup-restore-options').show();
					$('#sgbackup-restore-progress').hide();
					return;
				}
				formData.append('batch_subsite_map', JSON.stringify(batchMap));
			}

			// Target domain/blog_id for subsite import
			var targetSubsiteDomain = $('input[name="sgbackup_target_subsite_domain"]').val();
			var targetSubsiteId = $('input[name="sgbackup_target_subsite_id"]').val();
			if (targetSubsiteDomain) {
				formData.append('target_subsite_domain', targetSubsiteDomain);
			}
			if (targetSubsiteId) {
				formData.append('target_subsite_id', targetSubsiteId);
			}

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

							if (info.type === 'search_replace') {
								var srHtml = '<div class="sgbackup-sr-report">';
								srHtml += '<h4>Migration Search-Replace</h4>';
								srHtml += '<p>' + SGB.esc(String(info.changes)) + ' changes across ' + SGB.esc(String(info.tables)) + ' tables</p>';
								if (info.pairs) {
									srHtml += '<ul>';
									$.each(info.pairs, function(oldVal, newVal) {
										srHtml += '<li>' + SGB.esc(oldVal) + ' &rarr; ' + SGB.esc(newVal) + '</li>';
									});
									srHtml += '</ul>';
								}
								srHtml += '</div>';
								$('#sgbackup-restore-log').append(srHtml);
							} else {
								$('#sgbackup-restore-log').append(
									'<div class="' + cls + '">' + SGB.esc(info.message || '') + '</div>'
								);
							}
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
								// Show the close button
								$('#sgbackup-restore-done').show();
							}
						} catch (e) { /* skip invalid JSON */ }
					}
				}
			};

			xhr.onerror = function() {
				$('#sgbackup-restore-log').append('<div class="restore-error">Connection error during restore.</div>');
				$('#sgbackup-restore-done').show();
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

				// Also collect from Advanced and Failover tabs (outside settings form)
				$('#tab-advanced, #tab-failover').find('input[name], select[name], textarea[name]').each(function() {
					var name = $(this).attr('name');
					if (!name) return;
					if ($(this).is(':checkbox')) {
						params[name] = $(this).is(':checked') ? 1 : 0;
					} else {
						params[name] = $(this).val();
					}
				});

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

					var $saveBtn = $('#sgbackup-save-settings');
				$saveBtn.prop('disabled', true);
				$('#sgbackup-settings-status').text('Saving...').removeClass('success error');

				self.ajax('savesettings', params, function() {
					$saveBtn.prop('disabled', false);
					$('#sgbackup-settings-status').text('Settings saved!').addClass('success');
					setTimeout(function() { $('#sgbackup-settings-status').text(''); }, 3000);
				}, function(msg) {
					$saveBtn.prop('disabled', false);
					$('#sgbackup-settings-status').text('Error: ' + msg).addClass('error');
				});
			});
		},

		// ===== Upload =====
		formatBytes: function(bytes) {
			if (bytes === 0) return '0 B';
			var units = ['B', 'KB', 'MB', 'GB', 'TB'];
			var i = Math.floor(Math.log(bytes) / Math.log(1024));
			return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
		},

		bindUpload: function() {
			var self = this;

			$('#sgbackup-upload-btn').on('click', function() {
				var files = $('#sgbackup-upload-input')[0].files;
				if (!files.length) return;
				var file = files[0];

				// Validate extension client-side
				var ext = file.name.split('.').pop().toLowerCase();
				if (['zip', 'gz', 'sql', 'crypt'].indexOf(ext) === -1) {
					self.showNotice('Only .zip, .gz, .sql, .crypt files are allowed.', 'error');
					return;
				}

				var chunkSize = parseInt(sgbackup_settings.chunk_size, 10) || (2 * 1024 * 1024);

				// Check for existing partial upload
				self.ajax('upload_chunk_status', { filename: file.name }, function(statusData) {
					if (statusData.status === 1) {
						// Already fully uploaded
						self.showNotice('This file has already been uploaded.', 'warning');
						return;
					}
					if (statusData.status === 0 && statusData.size > 0) {
						// Partial upload exists - offer resume
						var resumeMsg = 'A partial upload exists (' + self.formatBytes(statusData.size) + ' of ' + self.formatBytes(file.size) + ').\n\nResume upload?';
						if (confirm(resumeMsg)) {
							self.startChunkedUpload(file, chunkSize, statusData.size);
						} else {
							// Cancel existing and start fresh
							self.ajax('upload_chunk_cancel', { filename: file.name }, function() {
								self.startChunkedUpload(file, chunkSize, 0);
							});
						}
						return;
					}
					// No existing upload - start fresh
					self.startChunkedUpload(file, chunkSize, 0);
				}, function() {
					// Status check failed - start fresh
					self.startChunkedUpload(file, chunkSize, 0);
				});
			});

			// Cancel button
			$('#sgbackup-upload-cancel-btn').on('click', function() {
				var files = $('#sgbackup-upload-input')[0].files;
				var filename = files.length ? files[0].name : '';
				if (self.uploadAbortController) {
					self.uploadAbortController.abort();
					self.uploadAbortController = null;
				}
				if (filename) {
					self.ajax('upload_chunk_cancel', { filename: filename });
				}
				self.uploadReset('Upload cancelled.');
			});
		},

		startChunkedUpload: function(file, chunkSize, startOffset) {
			var self = this;
			var offset = startOffset;
			var retries = 0;
			var maxRetries = 3;
			var startTime = Date.now();
			var startBytes = startOffset;
			var cancelled = false;

			// Set up abort controller
			self.uploadAbortController = { abort: function() { cancelled = true; } };

			// UI state
			$('#sgbackup-upload-btn').prop('disabled', true);
			$('#sgbackup-upload-input').prop('disabled', true);
			$('#sgbackup-upload-cancel-btn').show();
			$('#sgbackup-upload-progress-wrap').show();
			$('#sgbackup-upload-status').text('').removeClass('success error');

			self.updateUploadProgress(offset, file.size, startTime, startBytes);

			function sendNextChunk() {
				if (cancelled) return;

				var end = Math.min(offset + chunkSize, file.size);
				var chunk = file.slice(offset, end);
				var isLast = (end >= file.size);

				var formData = new FormData();
				formData.append('action', 'sgbackup_ajax');
				formData.append('subaction', 'upload_chunk');
				formData.append('nonce', (sgbackup_settings.nonces && sgbackup_settings.nonces.upload_chunk) || sgbackup_settings.nonce);
				formData.append('data', JSON.stringify({
					filename: file.name,
					offset: offset,
					total_size: file.size,
					is_last: isLast ? 1 : 0
				}));
				formData.append('chunk_data', chunk, 'chunk');

				$.ajax({
					url: sgbackup_settings.ajax_url,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					timeout: 60000,
					success: function(response) {
						if (cancelled) return;
						if (!response.success) {
							var errMsg = response.data && response.data.message ? response.data.message : 'Upload error';
							// Handle out-of-sequence by re-syncing offset
							if (response.data && response.data.error_code === 'out_of_sequence' && response.data.error_data && typeof response.data.error_data.expected !== 'undefined') {
								offset = response.data.error_data.expected;
								retries = 0;
								sendNextChunk();
								return;
							}
							retryOrFail(errMsg);
							return;
						}

						retries = 0;
						var data = response.data;
						offset = data.size; // Use server-reported size as next offset

						if (data.status === 1) {
							// Upload complete
							self.uploadAbortController = null;
							self.updateUploadProgress(file.size, file.size, startTime, startBytes);
							self.uploadComplete(file.name, data);
							return;
						}

						self.updateUploadProgress(offset, file.size, startTime, startBytes);
						sendNextChunk();
					},
					error: function(xhr, status, errorThrown) {
						if (cancelled) return;
						if (status === 'abort') return;
						retryOrFail('Network error (' + status + ')');
					}
				});
			}

			function retryOrFail(errMsg) {
				retries++;
				if (retries > maxRetries) {
					self.uploadReset('Upload failed: ' + errMsg, true);
					return;
				}
				// Exponential backoff: 1s, 2s, 4s
				var delay = Math.pow(2, retries - 1) * 1000;
				$('#sgbackup-upload-progress-text').text('Retrying in ' + (delay / 1000) + 's... (' + errMsg + ')');
				setTimeout(function() {
					if (cancelled) return;
					// Re-sync offset from server before retry
					self.ajax('upload_chunk_status', { filename: file.name }, function(statusData) {
						if (statusData.status === 1) {
							// Already complete on server (upload finished despite timeout)
							self.uploadComplete(file.name, { status: 1 });
							return;
						}
						if (statusData.status === -1) {
							// File gone - bundle was already extracted and deleted,
							// or upload was processed. Don't restart from scratch.
							$('#sgbackup-upload-status').text('Upload complete!').addClass('success');
							$('#sgbackup-upload-input').val('');
							setTimeout(function() { location.reload(); }, 1500);
							return;
						}
						if (statusData.status === 0 && statusData.size > 0) {
							offset = statusData.size;
						}
						sendNextChunk();
					}, function() {
						sendNextChunk();
					});
				}, delay);
			}

			sendNextChunk();
		},

		updateUploadProgress: function(uploaded, total, startTime, startBytes) {
			var pct = total > 0 ? (uploaded / total * 100) : 0;
			$('#sgbackup-upload-progress-bar').css('width', pct.toFixed(1) + '%');

			var elapsed = (Date.now() - startTime) / 1000;
			var bytesThisSession = uploaded - startBytes;
			var speed = elapsed > 0 ? bytesThisSession / elapsed : 0;
			var remaining = total - uploaded;
			var eta = speed > 0 ? remaining / speed : 0;

			var etaStr = '';
			if (eta > 3600) {
				etaStr = Math.floor(eta / 3600) + 'h ' + Math.floor((eta % 3600) / 60) + 'm';
			} else if (eta > 60) {
				etaStr = Math.floor(eta / 60) + 'm ' + Math.floor(eta % 60) + 's';
			} else if (eta > 0) {
				etaStr = Math.floor(eta) + 's';
			}

			var text = pct.toFixed(1) + '% | ' + this.formatBytes(uploaded) + ' / ' + this.formatBytes(total);
			if (speed > 0) text += ' | ' + this.formatBytes(speed) + '/s';
			if (etaStr && uploaded < total) text += ' | ETA: ' + etaStr;

			$('#sgbackup-upload-progress-text').text(text);
		},

		uploadComplete: function(filename, data) {
			var self = this;
			$('#sgbackup-upload-cancel-btn').hide();

			if (data.bundle) {
				$('#sgbackup-upload-status').text(data.message || 'Bundle extracted!').addClass('success');
				$('#sgbackup-upload-input').val('');
				setTimeout(function() { location.reload(); }, 1500);
				return;
			}

			// Check for foreign backup format
			var isSgBackup = /^backup_\d{4}-\d{2}-\d{2}-\d{4}_[^_]+_[a-zA-Z0-9]+/.test(filename);
			if (!isSgBackup && filename) {
				self.ajax('import_foreign_backup', { file: filename, detect_only: true }, function(detectData) {
					var detections = detectData.detections || [];
					var detected = detections.length > 0 && detections[0].detected;
					var formatLabel = detected ? detections[0].label : 'Unknown';
					var entities = detected && detections[0].entities ? detections[0].entities.join(', ') : '';
					var msg = 'Detected format: ' + formatLabel;
					if (entities) msg += ' (entities: ' + entities + ')';
					msg += '\n\nWould you like to import it as a foreign backup?';
					if (confirm(msg)) {
						self.ajax('import_foreign_backup', { file: filename }, function(importData) {
							var importMsg = 'Imported as ' + (importData.label || importData.format || 'backup') + '!';
							$('#sgbackup-upload-status').text(importMsg).addClass('success');
							$('#sgbackup-upload-input').val('');
							setTimeout(function() { location.reload(); }, 1500);
						}, function(importErr) {
							self.uploadReset('Import error: ' + importErr);
						});
					} else {
						self.uploadReset('Upload complete.');
					}
				}, function() {
					if (confirm('This file does not match the SiteGuard Backup naming pattern.\n\nWould you like to import it as a foreign backup?')) {
						self.ajax('import_foreign_backup', { file: filename }, function() {
							$('#sgbackup-upload-status').text('Imported as backup!').addClass('success');
							$('#sgbackup-upload-input').val('');
							setTimeout(function() { location.reload(); }, 1500);
						}, function(importErr) {
							self.uploadReset('Import error: ' + importErr);
						});
					} else {
						self.uploadReset('Upload complete.');
					}
				});
				return;
			}

			$('#sgbackup-upload-status').text('Upload complete!').addClass('success');
			$('#sgbackup-upload-input').val('');
			setTimeout(function() { location.reload(); }, 1500);
		},

		uploadReset: function(errorMsg, keepProgress) {
			this.uploadAbortController = null;
			$('#sgbackup-upload-btn').prop('disabled', false);
			$('#sgbackup-upload-input').prop('disabled', false);
			$('#sgbackup-upload-cancel-btn').hide();
			if (!keepProgress) {
				$('#sgbackup-upload-progress-wrap').hide();
				$('#sgbackup-upload-progress-bar').css('width', '0');
				$('#sgbackup-upload-progress-text').text('');
			}
			if (errorMsg) {
				$('#sgbackup-upload-status').text(errorMsg).addClass('error');
			}
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
				var text = $('#sgbackup-key-bundle').val();
				var $btn = $(this);
				var showCopied = function() {
					$btn.text('Copied!');
					setTimeout(function() { $btn.text('Copy to Clipboard'); }, 2000);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function() {
						showCopied();
					}).catch(function() {
						self.fallbackCopy(text);
						showCopied();
					});
				} else {
					self.fallbackCopy(text);
					showCopied();
				}
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

				// Populate the existing-backup dropdown with rescan first
				var $select = $('#sgbackup-send-existing-select');
				$select.html('<option value="">Scanning backups...</option>');

				var populateDropdown = function() {
					self.ajax('get_existing_backups', {}, function(data) {
						var html = '<option value="">Select backup...</option>';
						if (data && data.length) {
							for (var i = 0; i < data.length; i++) {
								var b = data[i];
								// Only show backups that have at least one entity with files on disk
								var entities = [];
								var hasFile = false;
								$.each(['db','plugins','themes','uploads','others','wpcore'], function(_, e) {
									if (b[e]) {
										entities.push(e);
										// Entity is an object: {files, size, exists}
										if (b[e].exists) hasFile = true;
									}
								});
								if (!hasFile) return true; // skip backups with no files on disk
								var optLabel = b.date + ' (' + entities.join(', ') + ')';
								if (b.label) optLabel += ' \u2014 ' + b.label;
								html += '<option value="' + SGB.esc(String(b.timestamp)) + '">'
									+ SGB.esc(optLabel) + '</option>';
							}
						}
						$select.html(html);
					}, function() {
						$select.html('<option value="">Error loading backups</option>');
					});
				};

				// Rescan local backup directory first to pick up untracked files
				self.ajax('rescan_local', {}, function() {
					populateDropdown();
				}, function() {
					// Even if rescan fails, still populate from existing history
					populateDropdown();
				});
			});

			// M6: Clone to site (backup + send in one step)
			$(document).on('click', '.sgbackup-clone-site', function() {
				var siteId = $(this).data('site-id');
				var $btn = $(this);
				if (!confirm('Create a full backup and send it to this site?')) return;

				$btn.prop('disabled', true).text('Cloning...');

				var cloneData = { site_id: siteId };
				var label = $('#sgbackup-send-label').val();
				if (label) cloneData.label = label;
				self.ajax('clone_to_site', cloneData, function(data) {
					$btn.prop('disabled', false).text('Clone to Site');
					$('#sgbackup-send-status').text('Clone started! Job: ' + (data.nonce || '')).addClass('success');
					self.startJobPolling();
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

				var label = $('#sgbackup-send-label').val();

				if (sendType === 'existing') {
					var timestamp = $('#sgbackup-send-existing-select').val();
					if (!timestamp) {
						$('#sgbackup-send-status').text('Select a backup first').addClass('error');
						return;
					}
					var sendData = { site_id: siteId, timestamp: timestamp };
					if (label) sendData.label = label;
					self.ajax('migrate_send', sendData, function(data) {
						$('#sgbackup-send-status').text('Send started! Job: ' + data.nonce).addClass('success');
						self.startJobPolling();
					});
				} else {
					// Create new backup and send
					var newData = { service: ['remotesend/' + siteId] };
					if (label) newData.label = label;
					self.ajax('backupnow', newData, function(data) {
						$('#sgbackup-send-status').text('Backup + send started! Job: ' + data.nonce).addClass('success');
						self.startJobPolling();
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
				}, function(msg) {
					$('#sgbackup-jobs-list').html('<p><em>Error: ' + SGB.esc(msg) + '</em></p>');
				});
			});

			// WP-CLI Reference toggle
			$('#sgbackup-cli-ref-toggle').on('click', function() {
				var $ref = $('#sgbackup-cli-reference');
				$ref.toggle();
				$(this).text($ref.is(':visible') ? 'Hide Command Reference' : 'Show Command Reference');
			});

			// Security Audit Log toggle
			$('#sgbackup-audit-log-toggle').on('change', function() {
				var enabled = $(this).is(':checked');
				$('#sgbackup-audit-log-status').text('Saving...');
				self.ajax('toggle_audit_log', { enabled: enabled ? 1 : 0 }, function() {
					$('#sgbackup-audit-log-status').text(enabled ? 'Enabled' : 'Disabled').addClass('success');
					$('#sgbackup-audit-log-section').toggle(enabled);
					setTimeout(function() { $('#sgbackup-audit-log-status').text(''); }, 2000);
				}, function(msg) {
					$('#sgbackup-audit-log-status').text('Error: ' + msg).addClass('error');
				});
			});

			// Load audit log entries
			$('#sgbackup-audit-log-load').on('click', function() {
				self.ajax('get_audit_log', { limit: 50 }, function(data) {
					$('#sgbackup-audit-log-count').text(data.total + ' total entries');
					var $tbody = $('#sgbackup-audit-log-table tbody');
					$tbody.empty();
					if (!data.entries || data.entries.length === 0) {
						$tbody.append('<tr><td colspan="5"><em>No audit log entries.</em></td></tr>');
					} else {
						$.each(data.entries, function(i, entry) {
							$tbody.append(
								'<tr>' +
								'<td>' + SGB.esc(entry.date) + '</td>' +
								'<td>' + SGB.esc(entry.action_label) + '</td>' +
								'<td>' + SGB.esc(entry.user_login) + '</td>' +
								'<td>' + SGB.esc(entry.ip) + '</td>' +
								'<td>' + SGB.esc(entry.details) + '</td>' +
								'</tr>'
							);
						});
					}
					$('#sgbackup-audit-log-result').show();
				});
			});

			// Clear audit log
			$('#sgbackup-audit-log-clear').on('click', function() {
				if (!confirm('Clear all audit log entries? This cannot be undone.')) return;
				self.ajax('clear_audit_log', {}, function() {
					$('#sgbackup-audit-log-table tbody').html('<tr><td colspan="5"><em>Log cleared.</em></td></tr>');
					$('#sgbackup-audit-log-count').text('0 total entries');
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

		// ===== Download All =====
		bindDownloadAll: function() {
			$(document).on('click', '.sgbackup-download-all-btn', function() {
				var files = $(this).data('files');
				var nonce = $(this).data('nonce');
				if (!files || !files.length) return;

				// Download each file with a small delay so the browser doesn't block multiple downloads
				var baseUrl = sgbackup_settings.ajax_url + '?action=sgbackup_download&_wpnonce=' + encodeURIComponent(nonce) + '&file=';
				$.each(files, function(i, file) {
					setTimeout(function() {
						var link = document.createElement('a');
						link.href = baseUrl + encodeURIComponent(file);
						link.download = file;
						link.style.display = 'none';
						document.body.appendChild(link);
						link.click();
						document.body.removeChild(link);
					}, i * 500); // 500ms between each to avoid browser blocking
				});
			});
		},

		// ===== Gap 37: Upload to Remote =====
		bindUploadToRemote: function() {
			var self = this;

			$(document).on('click', '.sgbackup-upload-remote-btn', function() {
				var timestamp = $(this).data('timestamp');

				// Only show methods that are actually configured (have settings saved)
				var allMethods = {
					's3': 'Amazon S3',
					's3generic': 'S3-Compatible',
					'googledrive': 'Google Drive',
					'dropbox': 'Dropbox',
					'onedrive': 'OneDrive',
					'azure': 'Microsoft Azure',
					'gcs': 'Google Cloud Storage',
					'pcloud': 'pCloud',
					'ftp': 'FTP',
					'sftp': 'SFTP / SCP',
					'webdav': 'WebDAV',
					'openstack': 'OpenStack',
					'email': 'Email'
				};
				// Get configured methods from localized settings
				var configured = sgbackup_settings.configured_methods || [];
				var html = '<div id="sgbackup-upload-remote-dialog" title="Upload to Remote Storage">';
				if (configured.length === 0) {
					html += '<p>No remote storage methods are configured. Go to Settings to set one up.</p>';
				} else {
					html += '<p>Select storage destinations:</p>';
					for (var i = 0; i < configured.length; i++) {
						var m = configured[i];
						var label = allMethods[m] || m;
						html += '<label style="display:block;margin:4px 0;">';
						html += '<input type="checkbox" class="sgbackup-remote-method-check" value="' + SGB.esc(m) + '" /> ';
						html += SGB.esc(label);
						html += '</label>';
					}
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

		// ===== Webhook Test =====
		bindWebhook: function() {
			var self = this;

			$('.sgbackup-test-webhook').on('click', function() {
				var url = $('input[name="webhook_url"]').val();
				if (!url) {
					$('.sgbackup-test-result').text('Enter a webhook URL first.').removeClass('success').addClass('error');
					return;
				}
				var $btn = $(this);
				var $result = $('.sgbackup-test-result');
				$btn.prop('disabled', true);
				$result.text('Sending...').removeClass('success error');

				self.ajax('test_webhook', { url: url }, function(data) {
					$btn.prop('disabled', false);
					var text = data.message + (data.http_code ? ' (HTTP ' + data.http_code + ')' : '');
					if (data.success) {
						$result.text(text).addClass('success').removeClass('error');
					} else {
						$result.text(text).addClass('error').removeClass('success');
					}
				}, function(msg) {
					$btn.prop('disabled', false);
					$result.text('Error: ' + msg).addClass('error').removeClass('success');
				});
			});
		},

		// ===== Utility: Format bytes =====
		formatBytes: function(bytes) {
			if (bytes === 0) return '0 B';
			var units = ['B', 'KB', 'MB', 'GB', 'TB'];
			var i = Math.floor(Math.log(bytes) / Math.log(1024));
			if (i >= units.length) i = units.length - 1;
			return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 2 : 0) + ' ' + units[i];
		},

		// ===== Phase 4: Failover UI =====
		bindFailover: function() {
			var self = this;

			// Role dropdown: show/hide production URL field
			$('#sgbackup-failover-role').on('change', function() {
				$('#sgbackup-failover-production-url-row').toggle($(this).val() === 'standby');
			});

			// Status check
			$('#sgbackup-failover-status-btn').on('click', function() {
				var $btn = $(this);
				var $spinner = $('#sgbackup-failover-status-spinner');
				$btn.prop('disabled', true);
				$spinner.text('Checking...');

				self.ajax('failover_status', {}, function(data) {
					$btn.prop('disabled', false);
					$spinner.text('');
					$('#sgbackup-failover-status-result').show();
					var $tbody = $('#sgbackup-failover-status-table tbody').empty();

					var rows = [];
					if (data.environment) {
						rows.push(['Docker', data.environment.is_docker ? '<span class="sgbackup-failover-status-ok">Yes</span>' : 'No']);
						rows.push(['Azure', data.environment.is_azure ? '<span class="sgbackup-failover-status-ok">Yes</span>' : 'No']);
						rows.push(['Multisite', data.environment.is_multisite ? '<span class="sgbackup-failover-status-ok">Yes</span>' : 'No']);
						rows.push(['Cloudflare Scripts', data.environment.has_cf_scripts ? '<span class="sgbackup-failover-status-ok">Found</span>' : 'Not found']);
					}
					rows.push(['Role', self.esc(data.role || 'standalone')]);
					if (data.backup) {
						var ageClass = '';
						if (data.backup.age_seconds !== null) {
							if (data.backup.age_seconds > 86400 * 7) ageClass = 'sgbackup-failover-status-error';
							else if (data.backup.age_seconds > 86400 * 2) ageClass = 'sgbackup-failover-status-warn';
							else ageClass = 'sgbackup-failover-status-ok';
						}
						rows.push(['Last Backup', data.backup.has_backup
							? '<span class="' + ageClass + '">' + self.esc(data.backup.age_human) + '</span> (' + self.esc(data.backup.last_backup) + ' UTC)'
							: '<span class="sgbackup-failover-status-error">Never</span>'
						]);
					}
					rows.push(['Database', data.db_ok ? '<span class="sgbackup-failover-status-ok">Connected</span>' : '<span class="sgbackup-failover-status-error">Error</span>']);
					if (data.disk) {
						var freePct = data.disk.total > 0 ? Math.round((data.disk.free / data.disk.total) * 100) : 0;
						var diskClass = freePct < 5 ? 'sgbackup-failover-status-error' : (freePct < 15 ? 'sgbackup-failover-status-warn' : 'sgbackup-failover-status-ok');
						rows.push(['Disk Space', self.formatBytes(data.disk.free) + ' free <span class="' + diskClass + '">(' + freePct + '%)</span>']);
					}
					if (data.dns && data.dns.host) {
						var dnsText = self.esc(data.dns.host) + ': ';
						if (data.dns.a_records && data.dns.a_records.length) {
							dnsText += 'A=' + data.dns.a_records.map(function(ip) { return self.esc(ip); }).join(', ');
						}
						if (data.dns.cname_records && data.dns.cname_records.length) {
							dnsText += ' CNAME=' + data.dns.cname_records.map(function(c) { return self.esc(c); }).join(', ');
						}
						rows.push(['DNS', dnsText]);
					}

					for (var i = 0; i < rows.length; i++) {
						$tbody.append('<tr><td>' + self.esc(rows[i][0]) + '</td><td>' + rows[i][1] + '</td></tr>');
					}
				}, function(msg) {
					$btn.prop('disabled', false);
					$spinner.text('Error: ' + msg);
				});
			});

			// Pre-failover backup
			$('#sgbackup-failover-prefailover-btn').on('click', function() {
				if (!confirm('This will create a database-only backup. Continue?')) return;

				var $btn = $(this);
				var $status = $('#sgbackup-failover-prefailover-status');
				$btn.prop('disabled', true);
				$status.text('Running backup...');

				self.ajax('failover_prefailover', {}, function(data) {
					$btn.prop('disabled', false);
					$status.text(data.message || 'Complete').addClass('success');
					if (data.db_files && data.db_files.length) {
						$('#sgbackup-failover-action-result').show();
						$('#sgbackup-failover-action-output').text('DB files: ' + data.db_files.join(', '));
					}
				}, function(msg) {
					$btn.prop('disabled', false);
					$status.text('Error: ' + msg).addClass('error');
				});
			});

			// Post-failover cleanup
			$('#sgbackup-failover-postfailover-btn').on('click', function() {
				if (!confirm('This will flush all caches and verify the system. Continue?')) return;
				if (!confirm('Are you sure? This clears transients, object cache, and rewrite rules.')) return;

				var $btn = $(this);
				var $status = $('#sgbackup-failover-postfailover-status');
				$btn.prop('disabled', true);
				$status.text('Running cleanup...');

				self.ajax('failover_postfailover', {}, function(data) {
					$btn.prop('disabled', false);
					$status.text(data.message || 'Complete').addClass('success');
					$('#sgbackup-failover-action-result').show();
					$('#sgbackup-failover-action-output').text(JSON.stringify(data.results || {}, null, 2));
				}, function(msg) {
					$btn.prop('disabled', false);
					$status.text('Error: ' + msg).addClass('error');
				});
			});

			// Healthcheck (reuses failover_status)
			$('#sgbackup-failover-healthcheck-btn').on('click', function() {
				var $btn = $(this);
				var $status = $('#sgbackup-failover-healthcheck-status');
				$btn.prop('disabled', true);
				$status.text('Running...');

				self.ajax('failover_status', {}, function(data) {
					$btn.prop('disabled', false);
					$status.text('Complete').addClass('success');
					$('#sgbackup-failover-action-result').show();
					$('#sgbackup-failover-action-output').text(JSON.stringify(data, null, 2));
				}, function(msg) {
					$btn.prop('disabled', false);
					$status.text('Error: ' + msg).addClass('error');
				});
			});
		},

		// ===== Restore Presets =====
		loadRestorePresets: function(callback) {
			var self = this;
			self.ajax('get_restore_presets', {}, function(presets) {
				var $sel = $('#sgbackup-preset-select');
				$sel.find('option:not(:first)').remove();
				if (presets && typeof presets === 'object') {
					var keys = Object.keys(presets);
					for (var i = 0; i < keys.length; i++) {
						var id = keys[i];
						$sel.append('<option value="' + self.esc(id) + '">' + self.esc(presets[id].name) + '</option>');
					}
				}
				if (callback) callback(presets);
			});
		},

		bindRestorePresets: function() {
			var self = this;
			var presetCache = {};

			// Load presets when restore modal opens
			$(document).on('click', '.sgbackup-restore-btn', function() {
				self.loadRestorePresets(function(presets) { presetCache = presets || {}; });
			});

			// Apply preset on select change
			$('#sgbackup-preset-select').on('change', function() {
				var id = $(this).val();
				$('#sgbackup-preset-delete').toggle(!!id);
				if (!id || !presetCache[id]) return;

				var opts = presetCache[id].restore_options || {};
				$('input[name="sgbackup_restore_db"]').prop('checked', !!opts.db);
				$('input[name="sgbackup_restore_plugins"]').prop('checked', !!opts.plugins);
				$('input[name="sgbackup_restore_themes"]').prop('checked', !!opts.themes);
				$('input[name="sgbackup_restore_uploads"]').prop('checked', !!opts.uploads);
				$('input[name="sgbackup_restore_others"]').prop('checked', !!opts.others);
				$('input[name="sgbackup_restore_wpcore"]').prop('checked', !!opts.wpcore);
				$('select[name="sgbackup_move_in_mode"]').val(opts.move_in_mode || 0);
				$('select[name="sgbackup_db_restore_mode"]').val(opts.db_restore_mode || 0);
				$('select[name="sgbackup_subsite_mode"]').val(opts.subsite_mode || 0);
				var presetDbMode = String(opts.db_restore_mode || 0);
				var presetSubsiteMode = String(opts.subsite_mode || 0);
				var presetShowSubsite = sgbackup_settings.is_multisite && (presetDbMode === '0' || presetDbMode === '1');
				$('#sgbackup-subsite-mode-row').toggle(presetShowSubsite);
				$('#sgbackup-subsite-map-container').toggle(presetShowSubsite && (presetSubsiteMode === '0' || presetSubsiteMode === '1' || presetSubsiteMode === '3'));
				$('#sgbackup-subsite-import').toggle(presetShowSubsite && presetSubsiteMode === '4');
				$('input[name="sgbackup_restore_pre_backup"]').prop('checked', opts.pre_restore_backup !== false);
				$('input[name="sgbackup_include_unspecified_tables"]').prop('checked', opts.include_unspecified_tables !== false);
				$('input[name="sgbackup_include_unspecified_plugins"]').prop('checked', opts.include_unspecified_plugins !== false);
				$('input[name="sgbackup_include_unspecified_themes"]').prop('checked', opts.include_unspecified_themes !== false);
			});

			// Save preset
			$('#sgbackup-preset-save').on('click', function() {
				var name = prompt('Preset name:');
				if (!name) return;

				var params = {
					name: name,
					db: $('input[name="sgbackup_restore_db"]').is(':checked'),
					plugins: $('input[name="sgbackup_restore_plugins"]').is(':checked'),
					themes: $('input[name="sgbackup_restore_themes"]').is(':checked'),
					uploads: $('input[name="sgbackup_restore_uploads"]').is(':checked'),
					others: $('input[name="sgbackup_restore_others"]').is(':checked'),
					wpcore: $('input[name="sgbackup_restore_wpcore"]').is(':checked'),
					move_in_mode: $('select[name="sgbackup_move_in_mode"]').val(),
					db_restore_mode: $('select[name="sgbackup_db_restore_mode"]').val(),
					subsite_mode: $('select[name="sgbackup_subsite_mode"]').val(),
					subsite_map: (function() {
						var sMode = $('select[name="sgbackup_subsite_mode"]').val();
						var map = {};
						if (sMode === '3') {
							$('.sgbackup-subsite-map-row').each(function() {
								var oldDomain = $(this).data('source-domain');
								var action = $(this).find('.sgbackup-subsite-action').val() || $(this).find('.sgbackup-subsite-action-val').val();
								if (action === 'skip') {
									map[oldDomain] = '__skip__';
								} else if (action === 'overwrite') {
									map[oldDomain] = $(this).find('.sgbackup-subsite-dest-select').val();
								} else {
									map[oldDomain] = $.trim($(this).find('.sgbackup-subsite-dest-input').val());
								}
							});
						} else if (sMode === '0') {
							$('.sgbackup-subsite-map-row').each(function() {
								var $cb = $(this).find('.sgbackup-subsite-include');
								if ($cb.length && !$cb.is(':checked') && !$cb.is(':disabled')) {
									map[$(this).data('source-domain')] = '__skip__';
								}
							});
						}
						return Object.keys(map).length ? map : null;
					})(),
					pre_restore_backup: $('input[name="sgbackup_restore_pre_backup"]').is(':checked'),
					include_unspecified_tables: $('input[name="sgbackup_include_unspecified_tables"]').is(':checked'),
					include_unspecified_plugins: $('input[name="sgbackup_include_unspecified_plugins"]').is(':checked'),
					include_unspecified_themes: $('input[name="sgbackup_include_unspecified_themes"]').is(':checked')
				};

				self.ajax('save_restore_preset', params, function(data) {
					presetCache = data.presets || {};
					self.loadRestorePresets(function(p) { presetCache = p || {}; });
					self.showNotice('Preset saved: ' + name, 'success');
				});
			});

			// Delete preset
			$('#sgbackup-preset-delete').on('click', function() {
				var id = $('#sgbackup-preset-select').val();
				if (!id) return;
				if (!confirm('Delete this preset?')) return;

				self.ajax('delete_restore_preset', { preset_id: id }, function(data) {
					presetCache = data.presets || {};
					self.loadRestorePresets(function(p) { presetCache = p || {}; });
					$('#sgbackup-preset-delete').hide();
					self.showNotice('Preset deleted.', 'success');
				});
			});
		},

		// ===== Scheduled Remote Send =====
		bindRemoteSendSchedule: function() {
			var self = this;

			// Load existing schedule on page load
			self.ajax('get_remotesend_schedule', {}, function(data) {
				if (!data) return;
				$('#sgbackup-remotesend-interval').val(data.interval || 'manual');
				$('#sgbackup-remotesend-retain').val(data.retain || 5);

				// Check destination boxes
				if (data.destinations && data.destinations.length) {
					for (var i = 0; i < data.destinations.length; i++) {
						$('input[name="sgbackup_remotesend_dest[]"][value="' + data.destinations[i] + '"]').prop('checked', true);
					}
				}

				// Check entity boxes
				if (data.entities && data.entities.length) {
					for (var j = 0; j < data.entities.length; j++) {
						$('input[name="sgbackup_remotesend_entity[]"][value="' + data.entities[j] + '"]').prop('checked', true);
					}
				}

				// Show next scheduled time
				if (data.next) {
					var d = new Date(data.next * 1000);
					$('#sgbackup-remotesend-next').text('Next: ' + d.toUTCString());
				}
			});

			// Save schedule
			$('#sgbackup-save-remotesend-schedule').on('click', function() {
				var destinations = [];
				$('input[name="sgbackup_remotesend_dest[]"]:checked').each(function() {
					destinations.push($(this).val());
				});

				var entities = [];
				$('input[name="sgbackup_remotesend_entity[]"]:checked').each(function() {
					entities.push($(this).val());
				});

				var params = {
					interval: $('#sgbackup-remotesend-interval').val(),
					destinations: destinations,
					entities: entities,
					retain: $('#sgbackup-remotesend-retain').val()
				};

				var $status = $('#sgbackup-remotesend-schedule-status');
				$status.text('Saving...').removeClass('success error');

				self.ajax('save_remotesend_schedule', params, function(data) {
					$status.text('Saved!').addClass('success');
					setTimeout(function() { $status.text(''); }, 3000);

					// Update next display
					self.ajax('get_remotesend_schedule', {}, function(sched) {
						if (sched && sched.next) {
							var d = new Date(sched.next * 1000);
							$('#sgbackup-remotesend-next').text('Next: ' + d.toUTCString());
						} else {
							$('#sgbackup-remotesend-next').text('');
						}
					});
				}, function(msg) {
					$status.text('Error: ' + msg).addClass('error');
				});
			});
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
