<?php
defined('ABSPATH') || exit;

/**
 * TW_Perf_Cache_Purger
 *
 * After rules are saved, purge whatever caching plugin is active
 * so the admin sees the effect immediately without a manual purge.
 */
class TW_Perf_Cache_Purger {

    /**
     * Purge a specific URL from all known cache plugins.
     * Pass null to purge everything.
     */
    public static function purge(?string $url = null): array {
        $purged = [];

        // W3 Total Cache
        if (function_exists('w3tc_flush_url') && $url) {
            w3tc_flush_url($url);
            $purged[] = 'W3TC (URL)';
        } elseif (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $purged[] = 'W3TC (all)';
        }

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $purged[] = 'WP Super Cache';
        }
        if (function_exists('prune_super_cache') && $url) {
            $dir = get_supercache_dir() ?? '';
            if ($dir) {
                prune_super_cache($dir, true);
                $purged[] = 'WP Super Cache (URL)';
            }
        }

        // WP Rocket
        if (function_exists('rocket_clean_post') && $url) {
            $post_id = url_to_postid($url);
            if ($post_id) {
                rocket_clean_post($post_id);
                $purged[] = 'WP Rocket (post)';
            }
        } elseif (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $purged[] = 'WP Rocket (domain)';
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
            $purged[] = 'LiteSpeed';
        }
        if (class_exists('\LiteSpeed\Purge')) {
            do_action('litespeed_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking LiteSpeed Cache plugin hook
            $purged[] = 'LiteSpeed (action)';
        }

        // Nginx Helper (Redis/FastCGI cache)
        if (class_exists('Nginx_Helper') || class_exists('NginxHelper')) {
            do_action('rt_nginx_helper_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking Nginx Helper plugin hook
            $purged[] = 'Nginx Helper';
        }

        // Breeze (Cloudways)
        if (class_exists('Breeze_Configuration')) {
            do_action('breeze_clear_all_cache'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking Breeze plugin hook
            $purged[] = 'Breeze';
        }

        // Autoptimize
        if (class_exists('autoptimizeCache')) {
            \autoptimizeCache::clearall();
            $purged[] = 'Autoptimize';
        }

        // FlyingPress
        if (class_exists('FlyingPress\Purge')) {
            \FlyingPress\Purge::purge_everything();
            $purged[] = 'FlyingPress';
        }

        // Hummingbird
        if (class_exists('\Hummingbird\Core\Utils')) {
            do_action('wphb_clear_page_cache'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoking Hummingbird plugin hook
            $purged[] = 'Hummingbird';
        }

        // SG Optimizer (SiteGround)
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $purged[] = 'SG Optimizer';
        }

        // Plesk/nginx — delete static cache files if using a file-based cache
        self::purge_static_file_cache($url);

        return $purged;
    }

    /**
     * Detect which cache plugins are active — used in admin UI to show status.
     */
    public static function detect_active(): array {
        $active = [];

        if (function_exists('w3tc_flush_all'))          $active[] = 'W3 Total Cache';
        if (function_exists('wp_cache_clear_cache'))     $active[] = 'WP Super Cache';
        if (function_exists('rocket_clean_domain'))      $active[] = 'WP Rocket';
        if (class_exists('LiteSpeed_Cache_API'))         $active[] = 'LiteSpeed Cache';
        if (class_exists('Nginx_Helper'))                $active[] = 'Nginx Helper';
        if (class_exists('Breeze_Configuration'))        $active[] = 'Breeze';
        if (class_exists('autoptimizeCache'))            $active[] = 'Autoptimize';
        if (class_exists('FlyingPress\Purge'))           $active[] = 'FlyingPress';
        if (function_exists('sg_cachepress_purge_cache'))$active[] = 'SG Optimizer';

        return $active;
    }

    /**
     * Delete static HTML cache files — for setups using WP Super Cache
     * in file mode or custom nginx page caching to a /cache/ directory.
     */
    private static function purge_static_file_cache(?string $url): void {
        // WP Super Cache file-based
        $supercache_dir = WP_CONTENT_DIR . '/cache/supercache/';
        if ($url && is_dir($supercache_dir)) {
            $host = wp_parse_url($url, PHP_URL_HOST);
            $path = wp_parse_url($url, PHP_URL_PATH) ?: '/';
            $dir  = $supercache_dir . $host . $path;
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '*.html') ?: []);
            }
        }
    }
}
