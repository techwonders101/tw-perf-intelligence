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

        $global    = self::get_global_rules($context, $is_preview);
        $post_type = self::get_post_type_rules($context, $is_preview);
        $page      = self::get_page_rules($context, $is_preview);

        // Merge: global → post_type → page (additive across layers)
        foreach ([$global, $post_type, $page] as $layer) {
            if (!is_array($layer)) continue;
            foreach ($layer as $type => $handles) {
                if (isset($rules[$type]) && is_array($handles)) {
                    $rules[$type] = array_unique(array_merge($rules[$type], $handles));
                }
            }
        }

        // Higher-priority 'keep' rules suppress inherited actions from lower-priority scopes.
        // page keep > post_type keep (both override global); post_type keep > global.
        $pt_target = self::get_current_post_type_target();
        $post_id   = get_queried_object_id();
        $page_target = $post_id ? 'post_' . $post_id : md5(home_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/'));

        $pt_keep   = $pt_target ? self::fetch_keep_handles('post_type', $pt_target, $context, $is_preview) : [];
        $page_keep = self::fetch_keep_handles('page', $page_target, $context, $is_preview);
        $all_keep  = array_unique(array_merge($pt_keep, $page_keep));

        if ($all_keep) {
            foreach ($rules as $type => $handles) {
                $rules[$type] = array_values(array_diff($handles, $all_keep));
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

        $post_id      = get_queried_object_id();
        $page_target  = $post_id ? 'post_' . $post_id : md5(home_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/'));
        $pt_target    = self::get_current_post_type_target();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin table; $where_parts values are each individually built with $wpdb->prepare()
        $where_parts = ["(rule_type = 'global' AND target = '')"];
        if ($pt_target) {
            $where_parts[] = $wpdb->prepare('(rule_type = %s AND target = %s)', 'post_type', $pt_target);
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
        bool   $preview_only = false,
        string $plugin_slug = '',
        bool   $exclusive = false
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

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table
        if ($exclusive) {
            // Remove any rules for this handle at a different scope so only one rule exists per handle
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE asset_type = %s AND handle = %s AND NOT (rule_type = %s AND target = %s)",
                $asset_type, $handle, $rule_type, $target
            ));
        }

        $result = $wpdb->replace($table, [
            'rule_type'    => $rule_type,
            'target'       => $target,
            'asset_type'   => $asset_type,
            'handle'       => $handle,
            'plugin_slug'  => sanitize_text_field($plugin_slug),
            'action'       => $action,
            'context'      => $context,
            'preview_only' => $preview_only ? 1 : 0,
        ]);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
                $rule['context'] ?? 'frontend',
                false,
                '',
                true // exclusive: replace any existing rule at a different scope
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
        $target = self::get_current_post_type_target();
        if (!$target) return [];
        return self::fetch_rules('post_type', $target, $context, $is_preview);
    }

    /**
     * Compute the post_type scope target for the current page.
     * More granular than raw post_type: distinguishes singular vs archive,
     * and groups WooCommerce archives under one target.
     */
    public static function get_current_post_type_target(): string {
        // WooCommerce archives
        if (function_exists('is_shop')             && is_shop())             return 'wc_archive';
        if (function_exists('is_product_category') && is_product_category()) return 'wc_archive';
        if (function_exists('is_product_tag')      && is_product_tag())      return 'wc_archive';

        // Standard post archives (category, tag, blog/posts-page)
        if (is_category() || is_tag() || is_home()) return 'post_archive';

        // Post-type archives and custom taxonomy archives
        if (is_post_type_archive() || is_archive() || is_tax()) {
            $post_type = get_query_var('post_type') ?: '';
            if (!$post_type) {
                $queried = get_queried_object();
                if ($queried instanceof \WP_Term) {
                    $tax_obj   = get_taxonomy($queried->taxonomy);
                    $post_type = ($tax_obj && count($tax_obj->object_type) === 1)
                        ? $tax_obj->object_type[0]
                        : '';
                }
            }
            return $post_type ? $post_type . '_archive' : '';
        }

        // Singular pages / WooCommerce utility pages — use raw post_type
        $post_type = get_post_type() ?: '';
        if (!$post_type && function_exists('is_woocommerce') && is_woocommerce()) {
            $post_type = 'product';
        }
        // Cart/checkout/account — post_type='page' but "All pages" would be misleading
        if (function_exists('is_cart')         && is_cart())         return '';
        if (function_exists('is_checkout')     && is_checkout())     return '';
        if (function_exists('is_account_page') && is_account_page()) return '';

        return $post_type;
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

    private static function fetch_keep_handles(string $rule_type, string $target, string $context = 'frontend', bool $is_preview = false): array {
        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT handle FROM `{$table}` WHERE rule_type = %s AND target = %s AND action = 'keep' AND (context = %s OR context = 'both') AND (preview_only = 0 OR %d = 1)",
            $rule_type, $target, $context, $is_preview ? 1 : 0
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_column($rows ?: [], 'handle');
    }

    /**
     * Returns a flat handle → action map for a given scope (raw, no merging).
     * Used by the panel to show per-scope rule indicators on each asset.
     */
    public static function get_scope_map(string $rule_type, string $target, string $context = 'frontend', bool $is_preview = false): array {
        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT handle, action FROM `{$table}` WHERE rule_type = %s AND target = %s AND (context = %s OR context = 'both') AND (preview_only = 0 OR %d = 1)",
            $rule_type, $target, $context, $is_preview ? 1 : 0
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[ $row['handle'] ] = $row['action'];
        }
        return $map;
    }

    // -------------------------------------------------------------------------
    // Target key helper (used by JS too via localize)
    // -------------------------------------------------------------------------
    public static function get_page_target_key(): string {
        $post_id = get_queried_object_id();
        return $post_id ? 'post_' . $post_id : md5(home_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/'));
    }

    public static function post_type_label( string $target ): string {
        $known = [
            'post'         => 'All single posts',
            'post_archive' => 'All post archives',
            'page'         => 'All pages',
            'product'      => 'All single products',
            'wc_archive'   => 'All WooCommerce archives',
            'attachment'   => 'All attachments',
        ];
        if ( isset( $known[ $target ] ) ) return $known[ $target ];
        // Custom post type archive: e.g. "event_archive"
        if ( str_ends_with( $target, '_archive' ) ) {
            $pt  = substr( $target, 0, -8 );
            $obj = get_post_type_object( $pt );
            return 'All ' . ( $obj ? $obj->labels->name : ucwords( str_replace( [ '-', '_' ], ' ', $pt ) ) ) . ' archives';
        }
        // Singular custom post type
        $obj = get_post_type_object( $target );
        if ( $obj ) return 'All ' . $obj->labels->singular_name . ' pages';
        return 'All ' . ucwords( str_replace( [ '-', '_' ], ' ', $target ) ) . ' pages';
    }
}
