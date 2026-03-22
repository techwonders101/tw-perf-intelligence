<?php
defined('ABSPATH') || exit;

/**
 * TW_Perf_HTML_Optimizer
 *
 * Applies page-level quick wins:
 *  - wp-emoji removal
 *  - <head> bloat cleanup (generator, RSD, wlwmanifest, shortlink, REST discovery)
 *  - Heartbeat API control (disable on frontend / disable everywhere)
 *  - font-display:swap enforcement via output buffer (inline <style> blocks)
 *  - LCP image attribute fix (fetchpriority="high", remove loading="lazy") via output buffer
 */
class TW_Perf_HTML_Optimizer {

    public function init(): void {
        if (is_admin() && !wp_doing_ajax()) return;

        if (get_option('twperf_remove_emoji', true)) {
            $this->remove_emoji();
        }

        if (get_option('twperf_clean_head', true)) {
            $this->clean_head();
        }

        $heartbeat = get_option('twperf_heartbeat', 'frontend');
        if ($heartbeat !== 'keep') {
            add_action('init', [$this, 'control_heartbeat']);
        }

        $critical_css = get_option('twperf_critical_css', '');
        if ($critical_css) {
            add_action('wp_head', function () use ($critical_css): void {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin-supplied CSS; admins have unfiltered_html
                echo '<style id="twperf-critical-css">' . $critical_css . '</style>' . "\n";
            }, 1);
        }

        $fix_fonts   = (bool) get_option('twperf_fix_font_display', false);
        $fix_lcp     = (bool) get_option('twperf_fix_lcp_attrs',    false);
        $remove_gf   = (bool) get_option('twperf_remove_gfonts',    false);

        if ($fix_fonts || $fix_lcp || $remove_gf) {
            add_action('template_redirect', function () use ($fix_fonts, $fix_lcp, $remove_gf) {
                ob_start(function (string $buffer) use ($fix_fonts, $fix_lcp, $remove_gf): string {
                    if ($remove_gf) $buffer = $this->remove_google_fonts($buffer);
                    if ($fix_fonts) $buffer = $this->apply_font_display($buffer);
                    if ($fix_lcp)   $buffer = $this->apply_lcp_fixes($buffer);
                    return $buffer;
                });
            }, 1);
        }
    }

    // -------------------------------------------------------------------------
    // Remove wp-emoji detection script + print styles
    // -------------------------------------------------------------------------
    private function remove_emoji(): void {
        remove_action('wp_head',             'print_emoji_detection_script', 7);
        remove_action('wp_print_styles',     'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles',  'print_emoji_styles');
        remove_filter('the_content_feed',    'wp_staticize_emoji');
        remove_filter('comment_text_rss',    'wp_staticize_emoji');
        remove_filter('wp_mail',             'wp_staticize_emoji_for_email');

        add_filter('tiny_mce_plugins', fn ($p) => array_diff((array) $p, ['wpemoji']));

        // Remove s.w.org DNS prefetch
        add_filter('wp_resource_hints', function (array $hints, string $type): array {
            if ($type !== 'dns-prefetch') return $hints;
            return array_filter($hints, function ($h) {
                $href = is_array($h) ? ($h['href'] ?? '') : $h;
                return strpos($href, 'svn.wp.org') === false && strpos($href, 's.w.org') === false;
            });
        }, 10, 2);
    }

    // -------------------------------------------------------------------------
    // Remove common <head> noise
    // -------------------------------------------------------------------------
    private function clean_head(): void {
        remove_action('wp_head', 'wp_generator');                        // <meta name="generator">
        remove_action('wp_head', 'wlwmanifest_link');                    // Windows Live Writer
        remove_action('wp_head', 'rsd_link');                            // Really Simple Discovery
        remove_action('wp_head', 'wp_shortlink_wp_head', 10);            // <link rel="shortlink">
        remove_action('wp_head', 'rest_output_link_wp_head', 10);        // REST API discovery
        remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);   // oEmbed discovery
        remove_action('template_redirect', 'rest_output_link_header', 11); // REST Link: header
    }

    // -------------------------------------------------------------------------
    // Heartbeat API control
    // -------------------------------------------------------------------------
    public function control_heartbeat(): void {
        $setting = get_option('twperf_heartbeat', 'frontend');

        if ($setting === 'disable_all') {
            wp_deregister_script('heartbeat');
            return;
        }

        // 'frontend' — keep heartbeat in wp-admin / block editor, remove on public pages
        if (!is_admin()) {
            wp_deregister_script('heartbeat');
        }
    }

    // -------------------------------------------------------------------------
    // Remove Google Fonts <link> and @import tags from HTML output
    // -------------------------------------------------------------------------
    private function remove_google_fonts(string $buffer): string {
        // Remove <link rel="stylesheet" href="https://fonts.googleapis.com/...">
        $buffer = preg_replace('/<link[^>]+href=["\'][^"\']*fonts\.googleapis\.com[^"\']*["\'][^>]*>/i', '', $buffer);
        // Remove @import url(...fonts.googleapis.com...) inside <style> blocks
        $buffer = preg_replace_callback(
            '/(<style[^>]*>)(.*?)(<\/style>)/is',
            function (array $m): string {
                $css = preg_replace('/@import\s+url\(["\']?[^"\')]*fonts\.googleapis\.com[^"\')]*["\']?\)\s*;?/i', '', $m[2]);
                return $m[1] . $css . $m[3];
            },
            $buffer
        );
        return $buffer;
    }

    // -------------------------------------------------------------------------
    // font-display:swap — inline <style> blocks + local external stylesheets
    // -------------------------------------------------------------------------
    private function apply_font_display(string $buffer): string {
        // Pass 1: inline <style> blocks
        $buffer = preg_replace_callback(
            '/(<style[^>]*>)(.*?)(<\/style>)/is',
            function (array $m): string {
                return $m[1] . $this->fix_font_display_css($m[2]) . $m[3];
            },
            $buffer
        );

        // Pass 2: extract corrected @font-face rules from local <link> stylesheets
        // and inject them at the end of <head> so they override the originals.
        $overrides = [];
        preg_match_all('/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i', $buffer, $links);
        foreach ($links[0] as $tag) {
            if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $hm)) continue;
            $href = strtok($hm[1], '?');
            $file = $this->css_url_to_path($href);
            if (!$file) continue;

            $cache_key = 'twperf_ffd_' . md5($file . '@' . (string) filemtime($file));
            $cached    = get_transient($cache_key);

            if ($cached === false) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $css = file_get_contents($file);
                if (!$css || stripos($css, '@font-face') === false) {
                    set_transient($cache_key, '', WEEK_IN_SECONDS);
                    continue;
                }
                $faces = [];
                preg_match_all('/@font-face\s*\{[^}]+\}/i', $css, $fm);
                foreach ($fm[0] as $face) {
                    if (preg_match('/font-display\s*:\s*swap\s*;/i', $face)) continue;
                    $faces[] = $this->fix_font_display_css($face);
                }
                $cached = implode("\n", $faces);
                set_transient($cache_key, $cached, WEEK_IN_SECONDS);
            }

            if (!empty($cached)) {
                $overrides[] = $cached;
            }
        }

        if ($overrides) {
            $style  = '<style id="twperf-font-display-fix">' . implode("\n", $overrides) . '</style>';
            $buffer = str_ireplace('</head>', $style . '</head>', $buffer);
        }

        return $buffer;
    }

    // -------------------------------------------------------------------------
    // Apply font-display:swap to a CSS string
    // -------------------------------------------------------------------------
    private function fix_font_display_css(string $css): string {
        // Replace existing non-swap values
        $css = preg_replace(
            '/font-display\s*:\s*(block|fallback|auto|optional)\s*;/i',
            'font-display:swap;',
            $css
        );
        // Inject into @font-face blocks that have no font-display at all
        $css = preg_replace_callback(
            '/(@font-face\s*\{)([^}]+)(\})/i',
            function (array $fm): string {
                if (stripos($fm[2], 'font-display') !== false) return $fm[0];
                return $fm[1] . $fm[2] . 'font-display:swap;' . $fm[3];
            },
            $css
        );
        return $css;
    }

    // -------------------------------------------------------------------------
    // Convert a stylesheet URL to an absolute server file path
    // -------------------------------------------------------------------------
    private function css_url_to_path(string $href): ?string {
        $site_url = site_url();
        $abspath  = untrailingslashit(ABSPATH);

        if (strpos($href, $site_url) === 0) {
            $rel = substr($href, strlen($site_url));
        } elseif (strpos($href, '/') === 0) {
            $rel = $href;
        } else {
            return null; // external URL
        }

        // Strip sub-path prefix for sites installed in a subdirectory
        $site_path = (string) (wp_parse_url($site_url, PHP_URL_PATH) ?? '');
        if ($site_path && $site_path !== '/' && strpos($rel, $site_path) === 0) {
            $rel = substr($rel, strlen($site_path));
        }

        $file = $abspath . '/' . ltrim($rel, '/');
        if (!is_file($file) || pathinfo($file, PATHINFO_EXTENSION) !== 'css') return null;
        return $file;
    }

    // -------------------------------------------------------------------------
    // LCP image fixes — output buffer regex on saved LCP image URL
    // Adds fetchpriority="high", removes loading="lazy", adds decoding="async"
    // -------------------------------------------------------------------------
    private function apply_lcp_fixes(string $buffer): string {
        $post_id = get_queried_object_id();
        if (!$post_id) return $buffer;

        $lcp_url = get_post_meta($post_id, '_twperf_lcp_image', true);
        if (!$lcp_url) return $buffer;

        // Build a match token: use the last two path segments (year/month/file.ext)
        // so we survive CDN rewrites but don't false-positive on same-named files
        // e.g. /wp-content/uploads/2024/03/hero.jpg → "2024/03/hero.jpg"
        $lcp_path  = wp_parse_url($lcp_url, PHP_URL_PATH) ?: '';
        $parts     = array_filter(explode('/', $lcp_path));
        $parts     = array_values($parts);
        $match_key = count($parts) >= 2
            ? implode('/', array_slice($parts, -2))   // "2024/03/hero.jpg"
            : basename($lcp_path);
        if (!$match_key) return $buffer;

        $buffer = preg_replace_callback(
            '/<img\s[^>]+>/is',
            function (array $m) use ($match_key): string {
                $tag = $m[0];
                if (strpos($tag, $match_key) === false) return $tag;

                // Remove loading="lazy"
                $tag = preg_replace('/\s*loading=["\']lazy["\']/i', '', $tag);

                // Add fetchpriority="high" if missing
                if (stripos($tag, 'fetchpriority') === false) {
                    $tag = preg_replace('/<img\s/i', '<img fetchpriority="high" ', $tag, 1);
                }

                // Add decoding="async" if missing (minor additional win)
                if (stripos($tag, 'decoding') === false) {
                    $tag = preg_replace('/<img\s/i', '<img decoding="async" ', $tag, 1);
                }

                return $tag;
            },
            $buffer
        );

        return $buffer;
    }
}
