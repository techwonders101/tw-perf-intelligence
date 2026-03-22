<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- All public methods call $this->verify_nonce() which wraps check_ajax_referer()
class TW_Perf_Ajax {

    public function init(): void {
        $actions = [
            'twperf_analyse'       => 'handle_analyse',
            'twperf_save_rule'     => 'handle_save_rule',
            'twperf_save_bulk'     => 'handle_save_bulk',
            'twperf_toggle_preview'=> 'handle_toggle_preview',
            'twperf_run_psi'       => 'handle_run_psi',
            'twperf_save_lcp'      => 'handle_save_lcp',
            'twperf_get_rules'     => 'handle_get_rules',
            'twperf_delete_rule'   => 'handle_delete_rule',
            'twperf_get_all_rules' => 'handle_get_all_rules',
            'twperf_purge_cache'   => 'handle_purge_cache',
            'twperf_dep_tree'      => 'handle_dep_tree',
            'twperf_export_rules'  => 'handle_export_rules',
            'twperf_import_rules'  => 'handle_import_rules',
            'twperf_fix_from_psi'  => 'handle_fix_from_psi',
            'twperf_conflict_check'  => 'handle_conflict_check',
            'twperf_apply_audit_fix'    => 'handle_apply_audit_fix',
            'twperf_save_quick_win'     => 'handle_save_quick_win',
            'twperf_save_critical_css'  => 'handle_save_critical_css',
            'twperf_save_font_preload'  => 'handle_save_font_preload',
            'twperf_delete_font_preload'=> 'handle_delete_font_preload',
            'twperf_save_preconnect'    => 'handle_save_preconnect',
            'twperf_delete_preconnect'  => 'handle_delete_preconnect',
            'twperf_get_preload_data'   => 'handle_get_preload_data',
            'twperf_go_live'            => 'handle_go_live',
        ];

        foreach ($actions as $action => $method) {
            add_action('wp_ajax_' . $action, [$this, $method]);
        }
    }

    // -------------------------------------------------------------------------
    // Analyse enqueued assets + DOM usage → return recommendations
    // -------------------------------------------------------------------------
    public function handle_analyse(): void {
        $this->verify_nonce();

        $post_id    = absint(wp_unslash($_POST['post_id'] ?? 0));
        $post_type  = sanitize_text_field(wp_unslash($_POST['post_type'] ?? ''));
        $url        = sanitize_url(wp_unslash($_POST['url'] ?? ''));
        $dom_usage  = json_decode(wp_unslash($_POST['dom_usage'] ?? '{}'), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately

        // Assets are sent from the client because $wp_scripts/$wp_styles
        // are empty during AJAX — frontend assets aren't enqueued in admin-ajax.php
        $client_scripts = json_decode(wp_unslash($_POST['enqueued_scripts'] ?? '{}'), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately
        $client_styles  = json_decode(wp_unslash($_POST['enqueued_styles']  ?? '{}'), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately

        // Extra client-side detection data (jQuery inline usage, etc.)
        $extra = json_decode(wp_unslash($_POST['extra'] ?? '{}'), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately

        $slider_handles      = [];
        $slider_category_map = TW_Perf_Intelligence::get_handle_map();
        $results             = [];

        // Our own panel assets — never include in analysis
        $own_handles = ['twperf-panel'];

        // Analyse scripts (from client-supplied data)
        foreach ($client_scripts as $handle => $asset) {
            if (in_array($handle, $own_handles, true)) continue;

            $src  = $asset['src']  ?? '';
            $deps = $asset['deps'] ?? [];

            $dependents = TW_Perf_Intelligence::get_dependents_from_data($handle, $client_scripts);
            $usage      = $dom_usage[$handle] ?? ['found' => false, 'above_fold' => false, 'count' => 0];
            $map_info   = $slider_category_map[$handle] ?? [];

            if (($map_info['category'] ?? '') === 'slider') {
                $slider_handles[] = $handle;
            }

            $rec = TW_Perf_Intelligence::get_recommendation($handle, 'script', $usage, $dependents, $dom_usage, $extra);

            $results[ 'script:' . $handle ] = [
                'handle'     => $handle,
                'asset_type' => 'script',
                'src'        => $src,
                'deps'       => $deps,
                'dependents' => $dependents,
                'dep_tree'   => TW_Perf_Intelligence::build_dep_tree_from_data($handle, $client_scripts),
                'size_bytes' => TW_Perf_Intelligence::estimate_size($src),
                'plugin_info'=> $map_info,
                'dom_usage'  => $usage,
                'rec'        => $rec,
            ];
        }

        // Analyse styles (from client-supplied data)
        foreach ($client_styles as $handle => $asset) {
            if (in_array($handle, $own_handles, true)) continue;

            $src  = $asset['src']  ?? '';
            $deps = $asset['deps'] ?? [];

            $dependents = TW_Perf_Intelligence::get_dependents_from_data($handle, $client_styles);
            $usage      = $dom_usage[$handle] ?? ['found' => false, 'above_fold' => false, 'count' => 0];
            $map_info   = $slider_category_map[$handle] ?? [];

            $rec = TW_Perf_Intelligence::get_recommendation($handle, 'style', $usage, $dependents, $dom_usage, $extra);

            $results[ 'style:' . $handle ] = [
                'handle'     => $handle,
                'asset_type' => 'style',
                'src'        => $src,
                'deps'       => $deps,
                'dependents' => $dependents,
                'dep_tree'   => TW_Perf_Intelligence::build_dep_tree_from_data($handle, $client_styles),
                'size_bytes' => TW_Perf_Intelligence::estimate_size($src),
                'plugin_info'=> $map_info,
                'dom_usage'  => $usage,
                'rec'        => $rec,
            ];
        }

        // Flag multiple slider libraries
        if (count($slider_handles) > 1) {
            foreach ($slider_handles as $h) {
                $key = 'script:' . $h;
                if (isset($results[$key])) {
                    $results[$key]['rec']['action']     = 'investigate';
                    $results[$key]['rec']['confidence'] = 'high';
                    $results[$key]['rec']['reason']     = count($slider_handles) . ' slider libraries loaded (' . implode(', ', $slider_handles) . '). Only one should be active.';
                    $results[$key]['rec']['badge']      = 'duplicate';
                }
            }
        }

        // Build current rules using the post_id/post_type from the request
        // since get_queried_object_id() doesn't work in AJAX context
        $current_rules = $this->get_rules_for_context($post_id, $post_type);

        wp_send_json_success([
            'assets'        => array_values($results),
            'current_rules' => $current_rules,
            'summary'       => $this->build_summary($results),
            'cache_plugins' => TW_Perf_Cache_Purger::detect_active(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Save a single rule
    // -------------------------------------------------------------------------
    public function handle_save_rule(): void {
        $this->verify_nonce();

        $allowed_rule_types = ['global', 'post_type', 'page'];
        $allowed_actions    = ['unload', 'defer', 'delay', 'async_css', 'preload', 'keep', 'remove'];
        $allowed_asset_types = ['script', 'style'];

        $rule_type  = sanitize_text_field(wp_unslash($_POST['rule_type']  ?? 'page'));
        $target     = sanitize_text_field(wp_unslash($_POST['target']     ?? ''));
        $asset_type = sanitize_text_field(wp_unslash($_POST['asset_type'] ?? 'script'));
        $handle     = sanitize_text_field(wp_unslash($_POST['handle']     ?? ''));
        $action     = sanitize_text_field(wp_unslash($_POST['rule_action'] ?? ''));
        $context_raw  = sanitize_key(wp_unslash($_POST['context'] ?? 'frontend'));
        $context      = in_array($context_raw, ['frontend', 'admin', 'both'], true) ? $context_raw : 'frontend';
        $preview_only = !empty($_POST['preview_only']);

        if (!$handle || !$action) {
            wp_send_json_error('Missing handle or action');
        }
        if (!in_array($rule_type, $allowed_rule_types, true))   wp_send_json_error('Invalid rule_type');
        if (!in_array($action, $allowed_actions, true))         wp_send_json_error('Invalid action');
        if (!in_array($asset_type, $allowed_asset_types, true)) wp_send_json_error('Invalid asset_type');

        $ok = TW_Perf_Rules::save_rule($rule_type, $target, $asset_type, $handle, $action, $context, $preview_only);

        // Purge cache so change is visible immediately
        $purged = TW_Perf_Cache_Purger::purge(sanitize_url(wp_unslash($_POST['url'] ?? '')));

        wp_send_json_success(['saved' => $ok, 'purged' => $purged]);
    }

    // -------------------------------------------------------------------------
    // Promote a preview-only rule to always-active ("Go Live")
    // -------------------------------------------------------------------------
    public function handle_go_live(): void {
        $this->verify_nonce();

        $allowed_rule_types  = ['global', 'post_type', 'page'];
        $allowed_asset_types = ['script', 'style'];

        $rule_type  = sanitize_text_field(wp_unslash($_POST['rule_type']  ?? 'page'));
        $target     = sanitize_text_field(wp_unslash($_POST['target']     ?? ''));
        $asset_type = sanitize_text_field(wp_unslash($_POST['asset_type'] ?? 'script'));
        $handle     = sanitize_text_field(wp_unslash($_POST['handle']     ?? ''));

        if (!$handle) wp_send_json_error('Missing handle');
        if (!in_array($rule_type,  $allowed_rule_types,  true)) wp_send_json_error('Invalid rule_type');
        if (!in_array($asset_type, $allowed_asset_types, true)) wp_send_json_error('Invalid asset_type');

        $ok     = TW_Perf_Rules::go_live($rule_type, $target, $asset_type, $handle);
        $purged = TW_Perf_Cache_Purger::purge(sanitize_url(wp_unslash($_POST['url'] ?? '')));

        wp_send_json_success(['saved' => $ok, 'purged' => $purged]);
    }

    // -------------------------------------------------------------------------
    // Save bulk rules (apply all recommendations)
    // -------------------------------------------------------------------------
    public function handle_save_bulk(): void {
        $this->verify_nonce();

        $rule_type = sanitize_text_field(wp_unslash($_POST['rule_type'] ?? 'page'));
        $target    = sanitize_text_field(wp_unslash($_POST['target']    ?? ''));
        $rules_raw = json_decode(wp_unslash($_POST['rules'] ?? '[]'), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately

        $allowed_actions     = ['unload', 'defer', 'delay', 'async_css', 'preload', 'keep', 'remove'];
        $allowed_asset_types = ['script', 'style'];

        $rules = array_map(function ($r) use ($allowed_actions, $allowed_asset_types) {
            $ctx    = sanitize_key($r['context'] ?? 'frontend');
            $action = sanitize_text_field($r['action'] ?? '');
            $type   = sanitize_text_field($r['asset_type'] ?? 'script');
            return [
                'asset_type' => in_array($type, $allowed_asset_types, true) ? $type : null,
                'handle'     => sanitize_text_field($r['handle'] ?? ''),
                'action'     => in_array($action, $allowed_actions, true) ? $action : null,
                'context'    => in_array($ctx, ['frontend', 'admin', 'both'], true) ? $ctx : 'frontend',
            ];
        }, $rules_raw);

        $rules = array_filter($rules, fn($r) => $r['handle'] && $r['action'] && $r['asset_type']);

        $ok = TW_Perf_Rules::save_bulk($rules, $rule_type, $target);

        // Purge cache
        $purged = TW_Perf_Cache_Purger::purge(sanitize_url(wp_unslash($_POST['url'] ?? '')));

        wp_send_json_success(['saved' => $ok, 'count' => count($rules), 'purged' => $purged]);
    }

    // -------------------------------------------------------------------------
    // Toggle preview mode cookie
    // -------------------------------------------------------------------------
    public function handle_toggle_preview(): void {
        $this->verify_nonce();

        if (isset($_COOKIE['twperf_preview'])) {
            setcookie('twperf_preview', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            wp_send_json_success(['preview' => false]);
        } else {
            setcookie('twperf_preview', '1', time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
            wp_send_json_success(['preview' => true]);
        }
    }

    // -------------------------------------------------------------------------
    // Run PSI analysis
    // -------------------------------------------------------------------------
    public function handle_run_psi(): void {
        $this->verify_nonce();

        $url      = sanitize_url(wp_unslash($_POST['url'] ?? ''));
        $strategy = sanitize_text_field(wp_unslash($_POST['strategy'] ?? 'mobile'));

        if (!$url) wp_send_json_error('No URL provided');

        $psi    = new TW_Perf_PSI_API();
        $result = $psi->analyse($url, $strategy);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        // Use client-supplied asset data for handle matching (globals are empty in AJAX)
        $client_scripts = json_decode(wp_unslash($_POST['enqueued_scripts'] ?? '{}'), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately
        $client_styles  = json_decode(wp_unslash($_POST['enqueued_styles']  ?? '{}'), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately

        $result['handle_matches'] = TW_Perf_PSI_API::match_handles_to_blocking(
            $result['blocking_resources'] ?? [],
            $client_scripts,
            $client_styles
        );

        wp_send_json_success($result);
    }

    // -------------------------------------------------------------------------
    // Save LCP image for a page
    // -------------------------------------------------------------------------
    public function handle_save_lcp(): void {
        $this->verify_nonce();

        $post_id = absint(wp_unslash($_POST['post_id'] ?? 0));
        $url     = sanitize_url(wp_unslash($_POST['lcp_url'] ?? ''));

        if (!$post_id || !$url) wp_send_json_error('Missing data');

        $ok = TW_Perf_Preload_Manager::save_lcp_image($post_id, $url);
        wp_send_json_success(['saved' => $ok]);
    }

    // -------------------------------------------------------------------------
    // Get rules for a target
    // -------------------------------------------------------------------------
    public function handle_get_rules(): void {
        $this->verify_nonce();

        $rule_type = sanitize_text_field(wp_unslash($_POST['rule_type'] ?? 'page'));
        $target    = sanitize_text_field(wp_unslash($_POST['target']    ?? ''));

        $rules = TW_Perf_Rules::get_all_rules_for_target($rule_type, $target);
        wp_send_json_success(['rules' => $rules]);
    }

    // -------------------------------------------------------------------------
    // Delete a single rule by ID
    // -------------------------------------------------------------------------
    public function handle_delete_rule(): void {
        $this->verify_nonce();

        $rule_type  = sanitize_text_field(wp_unslash($_POST['rule_type']  ?? 'page'));
        $target     = sanitize_text_field(wp_unslash($_POST['target']     ?? ''));
        $asset_type = sanitize_text_field(wp_unslash($_POST['asset_type'] ?? 'script'));
        $handle     = sanitize_text_field(wp_unslash($_POST['handle']     ?? ''));

        $ok = TW_Perf_Rules::save_rule($rule_type, $target, $asset_type, $handle, 'remove');
        TW_Perf_Cache_Purger::purge();
        wp_send_json_success(['deleted' => $ok]);
    }

    // -------------------------------------------------------------------------
    // Get ALL rules across all pages (for the site-wide rules admin view)
    // -------------------------------------------------------------------------
    public function handle_get_all_rules(): void {
        $this->verify_nonce();

        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, $table from esc_sql()
        $rows = $wpdb->get_results(
            "SELECT r.*, p.post_title
             FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON (r.rule_type = 'page' AND r.target = CONCAT('post_', p.ID))
             ORDER BY r.rule_type, r.target, r.asset_type, r.handle",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Enrich with human-readable target labels
        $rows       = $rows ?: [];
        $handle_map = TW_Perf_Intelligence::get_handle_map();
        foreach ($rows as &$row) {
            $row['plugin_label'] = $handle_map[$row['handle']]['plugin'] ?? '';
            $row['target_label'] = match($row['rule_type']) {
                'global'    => 'All pages',
                'post_type' => 'Post type: ' . $row['target'],
                'page'      => $row['post_title'] ?: $row['target'],
                default     => $row['target'],
            };
        }

        wp_send_json_success([
            'rules'         => $rows,
            'total'         => count($rows),
            'cache_plugins' => TW_Perf_Cache_Purger::detect_active(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Manual cache purge
    // -------------------------------------------------------------------------
    public function handle_purge_cache(): void {
        $this->verify_nonce();
        $url    = sanitize_url(wp_unslash($_POST['url'] ?? ''));
        $purged = TW_Perf_Cache_Purger::purge($url ?: null);
        wp_send_json_success(['purged' => $purged, 'count' => count($purged)]);
    }

    // -------------------------------------------------------------------------
    // Dependency tree for a handle
    // -------------------------------------------------------------------------
    public function handle_dep_tree(): void {
        $this->verify_nonce();
        $handle     = sanitize_text_field(wp_unslash($_POST['handle']     ?? ''));
        $asset_type = sanitize_text_field(wp_unslash($_POST['asset_type'] ?? 'script'));

        if (!$handle) wp_send_json_error('No handle');

        // Use client-supplied asset data since $wp_scripts/$wp_styles are empty in AJAX
        $client_assets = json_decode(wp_unslash($_POST['assets_data'] ?? '{}'), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately

        if (!empty($client_assets)) {
            wp_send_json_success([
                'tree'       => TW_Perf_Intelligence::build_dep_tree_from_data($handle, $client_assets),
                'dependents' => TW_Perf_Intelligence::get_dependents_from_data($handle, $client_assets),
            ]);
        }

        // Fallback to globals (works if called from admin pages where assets are loaded)
        wp_send_json_success([
            'tree'       => TW_Perf_Intelligence::build_dep_tree($handle, $asset_type),
            'dependents' => TW_Perf_Intelligence::get_dependents($handle, $asset_type),
        ]);
    }

    // -------------------------------------------------------------------------
    // Export all rules as JSON (for copying to another site)
    // -------------------------------------------------------------------------
    public function handle_export_rules(): void {
        $this->verify_nonce();

        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, $table from esc_sql()
        $rows  = $wpdb->get_results("SELECT rule_type, target, asset_type, handle, action FROM {$table} ORDER BY rule_type, target, asset_type, handle", ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $export = [
            'plugin'      => 'tw-performance',
            'version'     => TWPERF_VERSION,
            'exported_at' => gmdate('c'),
            'rules'       => $rows,
            // 'site' intentionally omitted — would leak staging/internal URLs in shared export files
        ];

        wp_send_json_success($export);
    }

    // -------------------------------------------------------------------------
    // Import rules from JSON export
    // -------------------------------------------------------------------------
    public function handle_import_rules(): void {
        $this->verify_nonce();

        $json = wp_unslash($_POST['json'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately
        if (!$json) wp_send_json_error('No data provided');

        $data = json_decode($json, true);
        if (!$data || empty($data['rules'])) {
            wp_send_json_error('Invalid import format');
        }

        if (($data['plugin'] ?? '') !== 'tw-performance') {
            wp_send_json_error('Not a TW Perf Intelligence export file');
        }

        $imported = 0;
        $skipped  = 0;
        $mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? 'merge')); // 'merge' or 'replace'
        if (!in_array($mode, ['merge', 'replace'], true)) {
            $mode = 'merge';
        }

        if ($mode === 'replace') {
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}twperf_rules");
        }

        foreach ($data['rules'] as $rule) {
            $rule_type  = sanitize_text_field($rule['rule_type']  ?? '');
            $target     = sanitize_text_field($rule['target']     ?? '');
            $asset_type = sanitize_text_field($rule['asset_type'] ?? '');
            $handle     = sanitize_text_field($rule['handle']     ?? '');
            $action     = sanitize_text_field($rule['action']     ?? '');

            if (!$rule_type || !$asset_type || !$handle || !$action) {
                $skipped++;
                continue;
            }

            $ok = TW_Perf_Rules::save_rule($rule_type, $target, $asset_type, $handle, $action);
            $ok ? $imported++ : $skipped++;
        }

        TW_Perf_Cache_Purger::purge();

        wp_send_json_success([
            'imported' => $imported,
            'skipped'  => $skipped,
            'total'    => count($data['rules']),
            'source'   => 'unknown',
        ]);
    }

    // -------------------------------------------------------------------------
    // One-click fix a specific handle flagged in PSI report
    // Maps PSI blocking URL → WP handle → applies best action automatically
    // -------------------------------------------------------------------------
    public function handle_fix_from_psi(): void {
        $this->verify_nonce();

        $handle     = sanitize_text_field(wp_unslash($_POST['handle']     ?? ''));
        $asset_type = sanitize_text_field(wp_unslash($_POST['asset_type'] ?? 'script'));
        $url        = sanitize_url(wp_unslash($_POST['url']               ?? ''));
        $post_id    = absint(wp_unslash($_POST['post_id']                            ?? 0));

        if (!$handle) wp_send_json_error('No handle');

        $target    = $post_id ? 'post_' . $post_id : md5(wp_parse_url($url, PHP_URL_PATH) ?: '/');
        $map       = TW_Perf_Intelligence::get_handle_map();
        $info      = $map[$handle] ?? [];
        $category  = $info['category'] ?? 'unknown';

        // Determine best action for this handle
        $action = match(true) {
            in_array($handle, ['jquery', 'jquery-core'])  => 'keep',
            $handle === 'jquery-migrate'                   => 'unload',
            $category === 'analytics'                      => 'delay',
            $category === 'slider'                         => 'delay',
            $category === 'animation'                      => 'delay',
            $asset_type === 'style'                        => 'async_css',
            default                                        => 'defer',
        };

        if ($action === 'keep') {
            wp_send_json_error('jQuery cannot be deferred — remove its dependents first');
        }

        $ok     = TW_Perf_Rules::save_rule('page', $target, $asset_type, $handle, $action);
        $purged = TW_Perf_Cache_Purger::purge($url);

        wp_send_json_success([
            'handle'  => $handle,
            'action'  => $action,
            'saved'   => $ok,
            'purged'  => $purged,
            'reason'  => "Applied {$action} to {$handle} based on category: {$category}",
        ]);
    }

    // -------------------------------------------------------------------------
    // Conflict checker — find rules that contradict each other
    // e.g. global unload but page-level keep; or both defer and delay set
    // -------------------------------------------------------------------------
    public function handle_conflict_check(): void {
        $this->verify_nonce();

        global $wpdb;
        $table    = esc_sql($wpdb->prefix . 'twperf_rules');
        $conflicts = [];

        // Find handles that have rules in multiple scopes with different actions
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, $table from esc_sql()
        $rows = $wpdb->get_results(
            "SELECT handle, asset_type, GROUP_CONCAT(DISTINCT action ORDER BY action SEPARATOR ',') as actions,
                    GROUP_CONCAT(DISTINCT rule_type ORDER BY rule_type SEPARATOR ',') as scopes,
                    COUNT(*) as rule_count
             FROM {$table}
             GROUP BY handle, asset_type
             HAVING COUNT(DISTINCT action) > 1",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        foreach ($rows as $row) {
            $conflicts[] = [
                'handle'     => $row['handle'],
                'asset_type' => $row['asset_type'],
                'actions'    => explode(',', $row['actions']),
                'scopes'     => explode(',', $row['scopes']),
                'severity'   => $this->conflict_severity($row['actions']),
                'message'    => "Handle '{$row['handle']}' has conflicting rules: {$row['actions']} across scopes: {$row['scopes']}",
            ];
        }

        // Also check: delayed scripts that jQuery depends on (would break jQuery)
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, $table from esc_sql()
        $delayed = $wpdb->get_col(
            "SELECT handle FROM {$table} WHERE action IN ('delay','unload') AND asset_type = 'script'"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Use client-supplied asset data for dependency checking (globals are empty in AJAX)
        $client_scripts = json_decode(wp_unslash($_POST['enqueued_scripts'] ?? '{}'), true) ?: []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded by json_decode; individual fields sanitized separately

        foreach ($delayed as $handle) {
            $dependents = !empty($client_scripts)
                ? TW_Perf_Intelligence::get_dependents_from_data($handle, $client_scripts)
                : TW_Perf_Intelligence::get_dependents($handle, 'script');
            $active_deps = array_filter($dependents, fn($d) => !in_array($d, $delayed));
            if (!empty($active_deps)) {
                $conflicts[] = [
                    'handle'     => $handle,
                    'asset_type' => 'script',
                    'severity'   => 'warning',
                    'message'    => "'{$handle}' is set to delay/unload but " . count($active_deps) . " script(s) depend on it: " . implode(', ', array_slice($active_deps, 0, 5)),
                ];
            }
        }

        wp_send_json_success([
            'conflicts' => $conflicts,
            'count'     => count($conflicts),
        ]);
    }

    private function conflict_severity(string $actions): string {
        // unload vs anything else = error; defer vs delay = warning
        if (str_contains($actions, 'unload') && str_contains($actions, 'keep'))  return 'error';
        if (str_contains($actions, 'unload') && str_contains($actions, 'defer')) return 'error';
        return 'warning';
    }

    // -------------------------------------------------------------------------
    // Build summary counts for the panel header
    // -------------------------------------------------------------------------
    private function build_summary(array $results): array {
        $summary = ['unload' => 0, 'delay' => 0, 'defer' => 0, 'investigate' => 0, 'keep' => 0, 'manual' => 0];
        foreach ($results as $r) {
            $action = $r['rec']['action'] ?? 'keep';
            if (isset($summary[$action])) $summary[$action]++;
            else $summary['keep']++;
        }
        return $summary;
    }

    /**
     * Get merged rules for a given page context (works in AJAX where
     * get_queried_object_id() / get_post_type() return nothing).
     */
    private function get_rules_for_context(int $post_id, string $post_type): array {
        $rules = [
            'unload_js'  => [],
            'unload_css' => [],
            'defer'      => [],
            'delay'      => [],
            'async_css'  => [],
            'preload'    => [],
        ];

        $layers = [TW_Perf_Rules::get_global_rules()];

        if ($post_type) {
            $layers[] = $this->fetch_rules_structured('post_type', $post_type);
        }

        if ($post_id) {
            $layers[] = $this->fetch_rules_structured('page', 'post_' . $post_id);
        }

        foreach ($layers as $layer) {
            foreach ($layer as $type => $handles) {
                if (isset($rules[$type]) && is_array($handles)) {
                    $rules[$type] = array_unique(array_merge($rules[$type], $handles));
                }
            }
        }

        return $rules;
    }

    /**
     * Fetch rules for a rule_type/target and return in the structured format
     * matching TW_Perf_Rules::get_for_current_page() output.
     */
    private function fetch_rules_structured(string $rule_type, string $target): array {
        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, $table from esc_sql()
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT asset_type, handle, action FROM {$table} WHERE rule_type = %s AND target = %s",
            $rule_type, $target
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $out = [
            'unload_js' => [], 'unload_css' => [], 'defer' => [],
            'delay' => [], 'async_css' => [], 'preload' => [],
        ];

        foreach ($rows as $row) {
            $key = match($row['action']) {
                'unload'    => $row['asset_type'] === 'script' ? 'unload_js' : 'unload_css',
                'defer'     => 'defer',
                'delay'     => 'delay',
                'async_css' => 'async_css',
                'preload'   => 'preload',
                default     => null,
            };
            if ($key) $out[$key][] = $row['handle'];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Enable a one-click audit fix (font-display, LCP attrs)
    // -------------------------------------------------------------------------
    public function handle_apply_audit_fix(): void {
        $this->verify_nonce();

        $fix = sanitize_key($_POST['fix'] ?? '');
        $allowed = ['font_display', 'lcp_attrs'];

        if (!in_array($fix, $allowed, true)) {
            wp_send_json_error('Unknown fix key');
        }

        $option = 'twperf_fix_' . $fix;
        update_option($option, true);

        wp_send_json_success(['enabled' => $option]);
    }

    // -------------------------------------------------------------------------
    // Save a quick-win setting toggle from the front-end panel
    // -------------------------------------------------------------------------
    public function handle_save_quick_win(): void {
        $this->verify_nonce();

        $key     = sanitize_key(wp_unslash($_POST['key']   ?? ''));
        $value   = sanitize_text_field(wp_unslash($_POST['value'] ?? ''));

        $allowed = [
            'twperf_remove_emoji'    => 'boolean',
            'twperf_clean_head'      => 'boolean',
            'twperf_heartbeat'       => 'select',
            'twperf_fix_font_display' => 'boolean',
            'twperf_fix_lcp_attrs'   => 'boolean',
            'twperf_remove_gfonts'   => 'boolean',
        ];

        if (!isset($allowed[$key])) {
            wp_send_json_error('Unknown setting');
        }

        if ($allowed[$key] === 'boolean') {
            update_option($key, $value === '1');
        } elseif ($key === 'twperf_heartbeat') {
            $valid = ['keep', 'frontend', 'disable_all'];
            if (!in_array($value, $valid, true)) wp_send_json_error('Invalid value');
            update_option($key, $value);
        }

        wp_send_json_success(['saved' => $key, 'value' => $value]);
    }

    // -------------------------------------------------------------------------
    // Save critical CSS from the front-end panel
    // -------------------------------------------------------------------------
    public function handle_save_critical_css(): void {
        $this->verify_nonce();
        if (is_multisite() && !current_user_can('unfiltered_html')) {
            wp_send_json_error('Insufficient permissions to save custom CSS');
        }
        $css = str_replace("\0", '', wp_unslash($_POST['css'] ?? '')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Admin-supplied CSS; admins have unfiltered_html
        update_option('twperf_critical_css', $css);
        wp_send_json_success(['saved' => true]);
    }

    // -------------------------------------------------------------------------
    // Font preload CRUD
    // -------------------------------------------------------------------------
    public function handle_save_font_preload(): void {
        $this->verify_nonce();
        $url         = esc_url_raw(wp_unslash($_POST['url']         ?? ''));
        $crossorigin = '1' === sanitize_key(wp_unslash($_POST['crossorigin'] ?? ''));
        if (!$url) wp_send_json_error('Missing URL');

        // Validate extension
        $ext = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, ['woff2','woff','ttf','otf','eot'], true)) {
            wp_send_json_error('URL must point to a font file (.woff2, .woff, .ttf, .otf)');
        }

        // Verify URL is reachable
        $response = wp_remote_head($url, ['timeout' => 6, 'sslverify' => true]);
        if (is_wp_error($response)) {
            wp_send_json_error('Could not reach URL: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            wp_send_json_error('URL returned HTTP ' . $code . ' — check it is publicly accessible');
        }

        TW_Perf_Preload_Manager::save_font_preload($url, $crossorigin);
        TW_Perf_Cache_Purger::purge();
        wp_send_json_success(['fonts' => TW_Perf_Preload_Manager::get_font_preloads()]);
    }

    public function handle_delete_font_preload(): void {
        $this->verify_nonce();
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        if (!$url) wp_send_json_error('Missing URL');
        TW_Perf_Preload_Manager::delete_font_preload($url);
        TW_Perf_Cache_Purger::purge();
        wp_send_json_success(['fonts' => TW_Perf_Preload_Manager::get_font_preloads()]);
    }

    // -------------------------------------------------------------------------
    // Preconnect CRUD
    // -------------------------------------------------------------------------
    public function handle_save_preconnect(): void {
        $this->verify_nonce();
        $raw         = esc_url_raw(wp_unslash($_POST['origin'] ?? ''));
        $crossorigin = '1' === sanitize_key(wp_unslash($_POST['crossorigin'] ?? ''));
        if (!$raw) wp_send_json_error('Missing origin');

        $scheme = wp_parse_url($raw, PHP_URL_SCHEME);
        $host   = wp_parse_url($raw, PHP_URL_HOST);
        if (!$scheme || !$host) wp_send_json_error('Invalid origin — use https://domain.com format');
        if (!in_array($scheme, ['http','https'], true)) wp_send_json_error('Origin must use http or https');

        // Normalise to bare origin (strip path/query if user pasted a full URL)
        $origin = $scheme . '://' . $host;
        $port   = wp_parse_url($raw, PHP_URL_PORT);
        if ($port) $origin .= ':' . $port;

        TW_Perf_Preload_Manager::save_preconnect($origin, $crossorigin);
        TW_Perf_Cache_Purger::purge();
        wp_send_json_success(['preconnects' => TW_Perf_Preload_Manager::get_preconnects()]);
    }

    public function handle_delete_preconnect(): void {
        $this->verify_nonce();
        $origin = esc_url_raw(wp_unslash($_POST['origin'] ?? ''));
        if (!$origin) wp_send_json_error('Missing origin');
        TW_Perf_Preload_Manager::delete_preconnect($origin);
        TW_Perf_Cache_Purger::purge();
        wp_send_json_success(['preconnects' => TW_Perf_Preload_Manager::get_preconnects()]);
    }

    // -------------------------------------------------------------------------
    // Get all preload data for the panel
    // -------------------------------------------------------------------------
    public function handle_get_preload_data(): void {
        $this->verify_nonce();
        wp_send_json_success([
            'fonts'       => TW_Perf_Preload_Manager::get_font_preloads(),
            'preconnects' => TW_Perf_Preload_Manager::get_preconnects(),
        ]);
    }

    private function verify_nonce(): void {
        if (!check_ajax_referer('twperf_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce', 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorised', 403);
        }
    }
}
