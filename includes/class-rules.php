<?php
defined('ABSPATH') || exit;

class TW_Perf_Rules {

    // -------------------------------------------------------------------------
    // Fetch rules for the current page (merged: global → post_type → page)
    // -------------------------------------------------------------------------
    public static function get_for_current_page(string $context = 'frontend', bool $is_preview = false): array {
        $rules = [
            'unload_js'  => [],
            'unload_css' => [],
            'defer'      => [],
            'delay'      => [],
            'async_css'  => [],
            'preload'    => [],
        ];

        $global     = self::get_global_rules($context, $is_preview);
        $post_type  = self::get_post_type_rules($context, $is_preview);
        $page       = self::get_page_rules($context, $is_preview);

        // Merge: page overrides post_type overrides global
        foreach ([$global, $post_type, $page] as $layer) {
            if (!is_array($layer)) continue;
            foreach ($layer as $type => $handles) {
                if (isset($rules[$type]) && is_array($handles)) {
                    $rules[$type] = array_unique(array_merge($rules[$type], $handles));
                }
            }
        }

        return $rules;
    }

    /**
     * Returns preview-only rules for the current page as a flat map:
     * [ handle => ['action' => ..., 'asset_type' => ...], … ]
     * Used by the panel to show "✓ Preview Only" state and offer a "Go Live" button.
     */
    public static function get_preview_only_for_panel(): array {
        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');

        $post_id     = get_queried_object_id();
        $page_target = $post_id ? 'post_' . $post_id : md5(home_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/'));
        $post_type   = get_post_type() ?: '';

        // Build WHERE: global + (post_type if known) + page
        $placeholders = ['global'];
        $values = ['global', ''];

        if ($post_type) {
            $placeholders[] = 'post_type';
            $values[] = 'post_type';
            $values[] = $post_type;
        }

        $placeholders[] = 'page';
        $values[] = 'page';
        $values[] = $page_target;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; $where_parts values are each individually built with $wpdb->prepare()
        $where_parts = ["(rule_type = 'global' AND target = '')"];
        if ($post_type) {
            $where_parts[] = $wpdb->prepare('(rule_type = %s AND target = %s)', 'post_type', $post_type);
        }
        $where_parts[] = $wpdb->prepare('(rule_type = %s AND target = %s)', 'page', $page_target);

        $rows = $wpdb->get_results(
            "SELECT rule_type, target, asset_type, handle, action FROM `{$table}` WHERE preview_only = 1 AND (" . implode(' OR ', $where_parts) . ')',
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $out = [];
        foreach ((array) $rows as $row) {
            $out[ $row['handle'] ] = [
                'action'     => $row['action'],
                'asset_type' => $row['asset_type'],
                'rule_type'  => $row['rule_type'],
                'target'     => $row['target'],
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Savers
    // -------------------------------------------------------------------------
    public static function save_rule(
        string $rule_type,
        string $target,
        string $asset_type,
        string $handle,
        string $action,
        string $context = 'frontend',
        bool   $preview_only = false
    ): bool {
        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');

        if ($action === 'remove') {
            // Delete all context variants for this rule — context is not relevant for removal
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE rule_type = %s AND target = %s AND asset_type = %s AND handle = %s",
                $rule_type, $target, $asset_type, $handle
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return $result !== false;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; replace() is a write operation, caching not applicable
        $result = $wpdb->replace($table, [
            'rule_type'    => $rule_type,
            'target'       => $target,
            'asset_type'   => $asset_type,
            'handle'       => $handle,
            'action'       => $action,
            'context'      => $context,
            'preview_only' => $preview_only ? 1 : 0,
        ]);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        return $result !== false;
    }

    /**
     * Clear preview_only flag for a rule — promotes it to always-active.
     */
    public static function go_live(
        string $rule_type,
        string $target,
        string $asset_type,
        string $handle
    ): bool {
        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');

        // Update all context variants — context is not relevant for go-live
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET preview_only = 0 WHERE rule_type = %s AND target = %s AND asset_type = %s AND handle = %s",
            $rule_type, $target, $asset_type, $handle
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $result !== false;
    }

    public static function save_bulk(array $rules, string $rule_type, string $target): bool {
        $ok = true;
        foreach ($rules as $rule) {
            $ok = $ok && self::save_rule(
                $rule_type,
                $target,
                $rule['asset_type'],
                $rule['handle'],
                $rule['action'],
                $rule['context'] ?? 'frontend'
            );
        }
        return $ok;
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------
    public static function get_global_rules(string $context = 'frontend', bool $is_preview = false): array {
        return self::fetch_rules('global', '', $context, $is_preview);
    }

    public static function get_post_type_rules(string $context = 'frontend', bool $is_preview = false): array {
        $post_type = get_post_type() ?: '';
        if (!$post_type) return [];
        return self::fetch_rules('post_type', $post_type, $context, $is_preview);
    }

    public static function get_page_rules(string $context = 'frontend', bool $is_preview = false): array {
        $post_id = get_queried_object_id();
        $url_key = $post_id ? 'post_' . $post_id : md5(home_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/'));
        return self::fetch_rules('page', $url_key, $context, $is_preview);
    }

    public static function get_all_rules_for_target(string $rule_type, string $target): array {
        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, $table from esc_sql()
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE rule_type = %s AND target = %s ORDER BY asset_type, handle",
            $rule_type, $target
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $rows ?: [];
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------
    private static function fetch_rules(string $rule_type, string $target, string $context = 'frontend', bool $is_preview = false): array {
        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');

        // preview_only = 0 always applies; preview_only = 1 only applies when preview is active
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table, $table from esc_sql()
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT asset_type, handle, action, context FROM `{$table}` WHERE rule_type = %s AND target = %s AND (context = %s OR context = 'both') AND (preview_only = 0 OR %d = 1)",
            $rule_type, $target, $context, $is_preview ? 1 : 0
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $out = [
            'unload_js'  => [],
            'unload_css' => [],
            'defer'      => [],
            'delay'      => [],
            'async_css'  => [],
            'preload'    => [],
        ];

        foreach ($rows as $row) {
            $handle = $row['handle'];
            $action = $row['action'];
            $type   = $row['asset_type']; // 'script' or 'style'

            switch ($action) {
                case 'unload':
                    $key = $type === 'script' ? 'unload_js' : 'unload_css';
                    $out[$key][] = $handle;
                    break;
                case 'defer':
                    $out['defer'][] = $handle;
                    break;
                case 'delay':
                    $out['delay'][] = $handle;
                    break;
                case 'async_css':
                    $out['async_css'][] = $handle;
                    break;
                case 'preload':
                    $out['preload'][] = $handle;
                    break;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Target key helper (used by JS too via localize)
    // -------------------------------------------------------------------------
    public static function get_page_target_key(): string {
        $post_id = get_queried_object_id();
        return $post_id ? 'post_' . $post_id : md5(home_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/'));
    }
}
