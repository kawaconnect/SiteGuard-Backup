# Implementation Plan: Error Alerts, Migration Labels, Multisite Search-Replace Fix, Restore Reporting

## Context

A real-world restore of a old-host.example multisite backup onto new-host.example broke the entire site because the restorer's search-replace:
- Only replaced the main domain (`old-host.example` → `new-host.example`)
- Missed subdomain blogs (`wp1.old-host.example` → `wp1.new-host.example`)
- Missed HTTP→HTTPS protocol transitions for subsites
- Left `wp_sitemeta.siteurl` pointing to `wp.new-host.example` instead of `new-host.example`

The user also has no visibility into migration job errors (they fire and forget) and can't label migration backups.

## Working Directory

All work happens in: `/opt/docker/wordpressmultisite/plugindev/fork/updraftplus/`

## Implementation Status

- [x] = Done (already in working tree, uncommitted)
- [ ] = Still needs to be implemented

---

## Step 0: Deferred Features Doc

- [x] Create `docs/future-features.md` documenting Restore Presets and Scheduled Cron Backups via Key Exchange

---

## Step 1: Frontend Error Alerts

### Backend: `includes/class-commands.php` - `activejobs_list()` (~line 168)

- [x] Add `'errors'` array to per-job response (from `$jobdata['errors']`)
- [x] Add `'has_errors'` boolean to per-job response

### Frontend: `js/admin.js`

- [x] **`showNotice(message, type)` helper** - Appends WordPress-style admin notice (`<div class="notice notice-{type} is-dismissible">`) to `#sgbackup-notices`. Auto-dismiss success after 8s. Error/warning persist.

- [x] **`startJobPolling()`** - Track `lastKnownJobs`. When jobs go empty, check lastKnownJobs for error/failed/aborted/has_errors. Show error notice or success notice accordingly.

- [x] **`renderActiveJobs()`** - After the phase line, add `job.last_log` as subtitle: `<div class="sgbackup-job-log">{last_log}</div>`. If `job.has_errors`: add `.sgbackup-job-error` class (red left border). If `job.errors` has entries: show last error message in red below phase.

- [x] **Send button handler (line ~1108-1130)** - After both `migrate_send` and `backupnow` success callbacks in the send button click handler, call `self.startJobPolling()`. Currently only `clone_to_site` starts polling after triggering a job.

### Templates

- [x] `templates/tab-backup-restore.php` - Add `<div id="sgbackup-notices" class="sgbackup-notices"></div>` at top of tab content
- [x] `templates/tab-migrate.php` - Add `<div id="sgbackup-notices-migrate" class="sgbackup-notices"></div>` at top

### CSS: `css/admin.css`

- [x] `.sgbackup-job-error` - red left border, light red background
- [x] `.sgbackup-job-log` - small gray text, ellipsis overflow
- [x] `.sgbackup-job-error-msg` - red text for error messages
- [x] `.sgbackup-notices` container style
- [x] `.sgbackup-sr-report` - search-replace report styling

---

## Step 2: Backup Label for Migration Sends

### Template: `templates/tab-migrate.php`

- [x] Add label input inside `#sgbackup-send-options` before the Send button:
  ```html
  <p>
    <label>Backup label:
      <input type="text" id="sgbackup-send-label" maxlength="40"
             placeholder="e.g., pre-migration-2024" class="regular-text" />
    </label>
  </p>
  ```

### Backend: `includes/class-commands.php`

- [x] **`migrate_send()`** - Pass label to backup options: `if (!empty($params['label'])) $options['label'] = sanitize_text_field(substr($params['label'], 0, 40));`

- [x] **`clone_to_site()`** - Use user-provided label if set, else fall back to auto-generated `'Migration clone ' . gmdate('Y-m-d H:i')`

### Frontend: `js/admin.js`

- [x] **Send button handler** - Read `$('#sgbackup-send-label').val()`. For `migrate_send`: pass `{ site_id, timestamp, label }`. For `backupnow` (new backup + send): pass `{ service: [...], label }`.

- [x] **Clone button handler** - Read label from the same input. Pass `{ site_id, label }` to `clone_to_site`.

---

## Step 3: Multisite-Aware Search-Replace (THE CRITICAL FIX)

This is the most important change. Everything in this section is in `restorer.php`.

### Problem

The current `do_search_replace()` method (line ~449) only builds replacement pairs for:
- `old_siteurl → current_siteurl`
- `old_home → current_home`
- `old_uploads → current_uploads`

For multisite-to-multisite migrations, this misses:
1. **Subdomain blogs**: `wp1.old-host.example` → `wp1.new-host.example` (21 blogs)
2. **Protocol per subsite**: `http://wp1.old-host.example` → `https://wp1.new-host.example`
3. **wp_sitemeta edge case**: `wp.old-host.example` → `new-host.example` (not `wp.new-host.example`)
4. **Domain-only replacement**: `old-host.example` → `new-host.example` (catches any remaining references)

### Implementation: `restorer.php`

**Status: [x] Implemented**

#### 1. Enhance `do_search_replace()` method

After the existing replacement pairs are built (after line ~476 where `$search`/`$replace` arrays are populated), add multisite detection:

```php
// Multisite: enumerate all blogs and build per-blog replacements
if (is_multisite()) {
    $this->build_multisite_replacements($search, $replace);
}
```

#### 2. New method: `build_multisite_replacements(&$search, &$replace)`

```php
/**
 * Build search-replace pairs for all multisite blogs.
 *
 * For each blog in wp_blogs whose domain contains the old site's domain,
 * compute the new domain and add protocol-aware replacement pairs.
 * Specific (longer) replacements are added first; bare domain goes last.
 */
private function build_multisite_replacements(&$search, &$replace) {
    global $wpdb;

    $old_domain = parse_url($this->old_siteurl, PHP_URL_HOST);
    $new_domain = parse_url($this->pre_import_siteurl, PHP_URL_HOST);
    if (!$old_domain || !$new_domain || $old_domain === $new_domain) return;

    $old_scheme = parse_url($this->old_siteurl, PHP_URL_SCHEME);
    $new_scheme = parse_url($this->pre_import_siteurl, PHP_URL_SCHEME);

    // Read all blog domains from wp_blogs
    $blogs_table = $wpdb->base_prefix . 'blogs';
    $blogs = $wpdb->get_col("SELECT DISTINCT domain FROM {$blogs_table}");
    if (empty($blogs)) return;

    $this->sgbackup->log("Multisite migration: found " . count($blogs) . " blog domains");

    $added = array(); // Track what we've added to avoid duplicates

    foreach ($blogs as $blog_domain) {
        // Skip if this blog domain doesn't contain the old domain
        if (strpos($blog_domain, $old_domain) === false) continue;

        // Compute new blog domain by replacing old domain with new
        $new_blog_domain = str_replace($old_domain, $new_domain, $blog_domain);

        // Skip if already covered by existing pairs
        if ($blog_domain === $old_domain) continue; // Will be caught by bare domain below
        if (in_array($blog_domain, $added, true)) continue;

        // Full URL replacements (with protocol)
        $old_url = $old_scheme . '://' . $blog_domain;
        $new_url = $new_scheme . '://' . $new_blog_domain;

        if (!in_array($old_url, $search, true)) {
            $search[] = $old_url;
            $replace[] = $new_url;
            $this->sgbackup->log("  Blog: {$old_url} -> {$new_url}");
        }

        // If protocols differ, also add cross-protocol pair
        // e.g., http://wp1.old.com -> https://wp1.new.com (if old was http, new is https)
        if ($old_scheme !== $new_scheme) {
            $alt_old_url = $new_scheme . '://' . $blog_domain;
            if (!in_array($alt_old_url, $search, true)) {
                $search[] = $alt_old_url;
                $replace[] = $new_url;
            }
        }

        // Domain-only replacement for this blog (no protocol)
        if (!in_array($blog_domain, $search, true) && $blog_domain !== $new_blog_domain) {
            $search[] = $blog_domain;
            $replace[] = $new_blog_domain;
        }

        $added[] = $blog_domain;
    }

    // Bare domain catch-all (LAST so specific replacements run first)
    if (!in_array($old_domain, $search, true)) {
        $search[] = $old_domain;
        $replace[] = $new_domain;
        $this->sgbackup->log("  Bare domain catch-all: {$old_domain} -> {$new_domain}");
    }
}
```

#### 3. New method: `fix_multisite_sitemeta()`

Called after `$sr->run()` completes in `do_search_replace()`:

```php
/**
 * Directly fix wp_sitemeta and wp_site after multisite migration.
 *
 * String replacement can produce wrong results for wp_sitemeta.siteurl
 * (e.g., wp.old-host.example → wp.new-host.example instead of new-host.example).
 * This method sets the correct values directly via SQL.
 */
private function fix_multisite_sitemeta() {
    if (!is_multisite()) return;

    global $wpdb;
    $new_domain = parse_url($this->pre_import_siteurl, PHP_URL_HOST);
    $new_scheme = parse_url($this->pre_import_siteurl, PHP_URL_SCHEME);
    if (!$new_domain) return;

    $correct_siteurl = $new_scheme . '://' . $new_domain . '/';

    // Fix wp_sitemeta
    $sitemeta_table = $wpdb->base_prefix . 'sitemeta';
    $wpdb->update(
        $sitemeta_table,
        array('meta_value' => $correct_siteurl),
        array('meta_key' => 'siteurl'),
        array('%s'),
        array('%s')
    );
    $this->sgbackup->log("Fixed wp_sitemeta siteurl -> {$correct_siteurl}");

    // Fix wp_site.domain
    $site_table = $wpdb->base_prefix . 'site';
    $wpdb->update(
        $site_table,
        array('domain' => $new_domain),
        array('id' => 1),
        array('%s'),
        array('%d')
    );
    $this->sgbackup->log("Fixed wp_site.domain -> {$new_domain}");

    // Fix wp_blogs for the main site (blog_id = 1)
    $blogs_table = $wpdb->base_prefix . 'blogs';
    $wpdb->update(
        $blogs_table,
        array('domain' => $new_domain),
        array('blog_id' => 1),
        array('%s'),
        array('%d')
    );
    $this->sgbackup->log("Fixed wp_blogs main site domain -> {$new_domain}");
}
```

#### 4. Wire it into `do_search_replace()`

After `$sr->run()` completes (line ~505), add:

```php
// Fix multisite-specific tables that string replacement can't handle correctly
$this->fix_multisite_sitemeta();
```

### Ordering Matters

The search-replace arrays must be ordered so **specific (longer) URLs come first** and **bare domain comes last**. This prevents partial replacements (e.g., replacing `old-host.example` before `wp1.old-host.example` would produce `wp1.new-host.example` correctly only if the bare domain replacement doesn't run first and produce `wp1.new-host.example` - actually in this case it works, but `wp.old-host.example` → `wp.new-host.example` is wrong when it should be `new-host.example`, hence the direct SQL fix in `fix_multisite_sitemeta()`).

---

## Step 4: Search-Replace Reporting in Restore UI

### Backend: `restorer.php` - `do_search_replace()`

**Status: [x] Implemented**

After `$sr->run()` completes:

```php
// Log detailed search-replace results
$this->sgbackup->log("Migration search-replace: {$report['changes']} changes across {$report['tables']} tables, {$report['updates']} rows updated");
foreach ($search as $i => $s) {
    $this->sgbackup->log("  {$s} -> {$replace[$i]}");
}
if (!empty($report['errors'])) {
    $this->sgbackup->log("Search-replace warnings: " . count($report['errors']), 'warning');
}

// Store report for inclusion in final progress message
$this->search_replace_report = array(
    'tables'  => $report['tables'],
    'changes' => $report['changes'],
    'updates' => $report['updates'],
    'pairs'   => array_combine($search, $replace),
    'errors'  => $report['errors'],
);
```

Add `private $search_replace_report = null;` as a class property.

### Backend: `restorer.php` - `perform_restore()`

After the search-replace runs and before `post_restore_cleanup()`, emit a progress message with the report:

```php
if ($this->search_replace_report) {
    $sr = $this->search_replace_report;
    $this->progress("Migration search-replace: {$sr['changes']} changes across {$sr['tables']} tables");
    // Emit structured RINFO for the JS to parse
    if (defined('DOING_AJAX') && DOING_AJAX) {
        echo 'RINFO:' . json_encode(array(
            'type' => 'search_replace',
            'tables' => $sr['tables'],
            'changes' => $sr['changes'],
            'updates' => $sr['updates'],
            'pairs' => $sr['pairs'],
        )) . "\n";
        if (ob_get_level()) @ob_flush();
        @flush();
    }
}
```

### Frontend: `js/admin.js` - Restore XHR onprogress handler (~line 540)

**Status: [x] Implemented**

When parsing RINFO lines, handle the new `search_replace` type:

```javascript
if (info.type === 'search_replace') {
    var srHtml = '<div class="sgbackup-sr-report">';
    srHtml += '<h4>Migration Search-Replace</h4>';
    srHtml += '<p>' + info.changes + ' changes across ' + info.tables + ' tables</p>';
    if (info.pairs) {
        srHtml += '<ul>';
        $.each(info.pairs, function(old, replacement) {
            srHtml += '<li>' + SGB.esc(old) + ' &rarr; ' + SGB.esc(replacement) + '</li>';
        });
        srHtml += '</ul>';
    }
    srHtml += '</div>';
    $('#sgbackup-restore-log').append(srHtml);
}
```

### Backend: `includes/class-commands.php` - `get_backup_contents()`

**Status: [x] Implemented**

Add multisite blog detection to the response. When scanning the SQL dump and `is_multisite()`, also look for `wp_blogs` INSERT statements to extract blog domains:

```php
// After the existing old_siteurl detection in the SQL scan loop, also detect blogs:
if (preg_match("/INSERT INTO\s+`[^`]*blogs`/i", $line)) {
    // Extract domains from the INSERT values
    if (preg_match_all("/'([a-zA-Z0-9._-]+\.[a-zA-Z]{2,})'/", $line, $domain_matches)) {
        foreach ($domain_matches[1] as $d) {
            $multisite_blogs[] = $d;
        }
    }
}
```

Then include in the response:
```php
if (!empty($multisite_blogs)) {
    $result['multisite_blogs'] = array_unique($multisite_blogs);
}
```

### Frontend: `js/admin.js` - `showRestoreVerification()`

**Status: [x] Implemented**

When migration is detected AND `data.multisite_blogs` exists, show a site mapping table in the verification stage:

```javascript
if (data.multisite_blogs && data.multisite_blogs.length > 0) {
    var oldHost = data.old_siteurl.replace(/https?:\/\//, '').replace(/\/.*/, '');
    var newHost = data.current_siteurl.replace(/https?:\/\//, '').replace(/\/.*/, '');
    html += '<tr><td><strong>Multisite blogs:</strong></td><td>' + data.multisite_blogs.length + ' sites</td></tr>';

    // Show mapping table
    var mapHtml = '<table class="widefat" style="margin-top:10px;"><thead><tr><th>Old Domain</th><th>New Domain</th></tr></thead><tbody>';
    for (var b = 0; b < data.multisite_blogs.length; b++) {
        var oldBlog = data.multisite_blogs[b];
        var newBlog = oldBlog.replace(oldHost, newHost);
        mapHtml += '<tr><td>' + SGB.esc(oldBlog) + '</td><td>' + SGB.esc(newBlog) + '</td></tr>';
    }
    mapHtml += '</tbody></table>';
    html += '<tr><td colspan="2">' + mapHtml + '</td></tr>';

    warnings.push('This is a multisite migration. All ' + data.multisite_blogs.length + ' blog domains will be updated automatically.');
}
```

---

## Files to Modify (Summary)

| File | Status | Changes |
|------|--------|---------|
| `docs/future-features.md` | [x] Done | Deferred features |
| `docs/implementation-plan.md` | [x] Done | This file |
| `css/admin.css` | [x] Done | Error job styling, notices, SR report |
| `templates/tab-backup-restore.php` | [x] Done | Notices div |
| `templates/tab-migrate.php` | [x] Done | Notices div, label input |
| `includes/class-commands.php` | [x] Done | errors in activejobs_list, label in migrate_send, label in clone_to_site, multisite blogs in get_backup_contents |
| `js/admin.js` | [x] Done | showNotice, startJobPolling error tracking, renderActiveJobs error display, send button polling, label passing, restore SR display, multisite verification |
| `restorer.php` | [x] Done | `build_multisite_replacements()`, `fix_multisite_sitemeta()`, SR reporting |

---

## Verification Checklist

1. **Error alerts**: Trigger a migration send with invalid key → verify red error banner appears
2. **Labels**: Send a backup with custom label → verify label shows in history on both sites
3. **Multisite search-replace**: Restore old-host.example backup on new-host.example:
   - All 21 blog domains replaced correctly
   - HTTP→HTTPS handled
   - `wp_sitemeta.siteurl` = `https://new-host.example/` (not `wp.new-host.example`)
   - `wp_site.domain` = `new-host.example`
   - `wp_blogs` blog_id=1 domain = `new-host.example`
4. **Restore reporting**: Verify restore log shows search-replace summary with pair listing and change counts
5. Deploy to container and test end-to-end:
   ```bash
   cd /opt/docker/wordpressmultisite
   docker compose cp plugindev/fork/updraftplus/. wordpress:/var/www/html/wp-content/plugins/siteguardbackup/
   ```
