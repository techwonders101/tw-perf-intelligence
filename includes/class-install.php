<?php
defined('ABSPATH') || exit;

class TW_Perf_Install {

    public static function activate(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        // Per-URL rules table
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}twperf_rules (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_type    VARCHAR(20)  NOT NULL DEFAULT 'page',  -- 'global','post_type','page'
            target       VARCHAR(255) NOT NULL DEFAULT '',       -- post_type slug or md5(url)
            asset_type   VARCHAR(10)  NOT NULL,                  -- 'script' or 'style'
            handle       VARCHAR(255) NOT NULL,
            action       VARCHAR(20)  NOT NULL,                  -- 'unload','defer','delay','async_css','preload','keep'
            context      VARCHAR(10)  NOT NULL DEFAULT 'frontend', -- 'frontend','admin','both'
            preview_only TINYINT(1)   NOT NULL DEFAULT 0,        -- 1 = only applied when preview cookie is active
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY rule_unique (rule_type, target, asset_type, handle, context),
            KEY action_idx (action)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Default options
        add_option('twperf_test_mode',         true);
        add_option('twperf_psi_api_key',       '');
        add_option('twperf_delay_events',      ['scroll', 'touchstart', 'click']);
        add_option('twperf_remove_emoji',      true);
        add_option('twperf_clean_head',        true);
        add_option('twperf_heartbeat',         'frontend');
        add_option('twperf_fix_font_display',  false);
        add_option('twperf_fix_lcp_attrs',     false);
        add_option('twperf_remove_gfonts',     false);
        add_option('twperf_critical_css',      '');
        add_option('twperf_version',           TWPERF_VERSION);

        flush_rewrite_rules();
    }

    /** Increment when adding new DB migrations. */
    private const DB_VERSION = '1.0.2';

    public static function maybe_upgrade(): void {
        // Fast path — skip the expensive DESC query if schema is already current.
        // Runs on every request (plugins_loaded) so must be cheap on the hot path.
        if (get_option('twperf_db_version') === self::DB_VERSION) return;

        global $wpdb;
        $table = esc_sql($wpdb->prefix . 'twperf_rules');

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statements cannot use prepare(); $table is esc_sql() escaped plugin-owned table
        $columns = $wpdb->get_col("DESC `{$table}`", 0);
        if (is_array($columns) && count($columns)) {
            if (!in_array('context', $columns, true)) {
                $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN context VARCHAR(10) NOT NULL DEFAULT 'frontend' AFTER action");
                $wpdb->query("ALTER TABLE `{$table}` DROP INDEX rule_unique, ADD UNIQUE KEY rule_unique (rule_type, target, asset_type, handle, context)");
            }
            if (!in_array('preview_only', $columns, true)) {
                $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN preview_only TINYINT(1) NOT NULL DEFAULT 0 AFTER context");
            }
            update_option('twperf_db_version', self::DB_VERSION);
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
