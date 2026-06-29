<?php
defined('ABSPATH') || exit;

/**
 * Downloads Google Fonts stylesheets and font files into a public uploads cache,
 * then rewrites matching frontend HTML references to the local copies.
 */
class TW_Perf_Google_Fonts_Local {

    private const OPTION_ENABLED = 'twperf_local_gfonts_enabled';
    private const OPTION_CACHE   = 'twperf_local_gfonts_cache';
    private const CACHE_VERSION  = 1;
    private const MAX_CSS_BYTES  = 1048576;
    private const MAX_FONT_BYTES = 5242880;
    private const USER_AGENT     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    private const SCAN_TRANSIENT_PREFIX = 'twperf_gfonts_scan_';

    public static function is_enabled(): bool {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    public static function get_status(): array {
        $cache       = self::get_cache();
        $stylesheets = self::get_cached_stylesheets($cache);
        $fonts       = 0;
        $font_names  = self::collect_font_names($stylesheets);

        foreach ($stylesheets as $entry) {
            $fonts += isset($entry['fonts']) && is_array($entry['fonts']) ? count($entry['fonts']) : 0;
        }

        $location = self::get_location(false);

        return [
            'enabled'      => self::is_enabled(),
            'stylesheets'  => count($stylesheets),
            'fonts'        => $fonts,
            'font_names'   => $font_names,
            'updated_at'   => (int) ($cache['updated_at'] ?? 0),
            'cache_dir'    => is_wp_error($location) ? '' : $location['dir'],
            'cache_url'    => is_wp_error($location) ? '' : $location['url'],
            'last_errors'  => isset($cache['errors']) && is_array($cache['errors']) ? $cache['errors'] : [],
        ];
    }

    public static function is_scan_request(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only scan token used to bypass this plugin's font rewrites.
        $token = sanitize_text_field(wp_unslash($_GET['twperf_gfonts_scan'] ?? ''));
        if (!$token || strlen($token) < 16) return false;

        return (bool) get_transient(self::SCAN_TRANSIENT_PREFIX . $token);
    }

    public static function scan_site(): array {
        $location = self::get_location(true);
        if (is_wp_error($location)) {
            return [
                'ok'     => false,
                'fatal'  => true,
                'errors' => [$location->get_error_message()],
            ];
        }

        $targets = self::get_scan_targets();
        $found   = [];
        $pages   = [];
        $errors  = [];
        $token   = wp_generate_password(24, false, false);
        set_transient(self::SCAN_TRANSIENT_PREFIX . $token, 1, 5 * MINUTE_IN_SECONDS);

        foreach ($targets as $target) {
            $scan_url = add_query_arg('twperf_gfonts_scan', $token, $target['url']);
            $response = wp_remote_get($scan_url, [
                'timeout'     => 15,
                'redirection' => 3,
                'sslverify'   => true,
                'headers'     => [
                    'User-Agent'    => self::USER_AGENT,
                    'Accept'        => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Cache-Control' => 'no-cache',
                ],
            ]);

            if (is_wp_error($response)) {
                $message  = $target['label'] . ': ' . $response->get_error_message();
                $errors[] = $message;
                $pages[]  = [
                    'label'       => $target['label'],
                    'url'         => $target['url'],
                    'stylesheets' => 0,
                    'error'       => $response->get_error_message(),
                ];
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 400) {
                $message  = $target['label'] . ': HTTP ' . $code;
                $errors[] = $message;
                $pages[]  = [
                    'label'       => $target['label'],
                    'url'         => $target['url'],
                    'stylesheets' => 0,
                    'error'       => 'HTTP ' . $code,
                ];
                continue;
            }

            $html      = (string) wp_remote_retrieve_body($response);
            $css_urls  = self::extract_stylesheet_urls($html);
            $page_urls = [];

            foreach ($css_urls as $css_url) {
                $found[$css_url] = true;
                $page_urls[]     = $css_url;
            }

            $pages[] = [
                'label'       => $target['label'],
                'url'         => $target['url'],
                'stylesheets' => count($page_urls),
                'error'       => '',
            ];
        }

        $existing        = self::get_cache();
        $existing_styles = self::get_cached_stylesheets($existing);
        $stylesheets     = [];
        $font_count      = 0;
        $font_names      = [];

        foreach (array_keys($found) as $css_url) {
            $cached = self::cache_stylesheet($css_url, $location);
            if (is_wp_error($cached)) {
                $errors[] = $css_url . ': ' . $cached->get_error_message();
                if (isset($existing_styles[$css_url])) {
                    $stylesheets[$css_url] = $existing_styles[$css_url];
                    $font_count += isset($existing_styles[$css_url]['fonts']) && is_array($existing_styles[$css_url]['fonts'])
                        ? count($existing_styles[$css_url]['fonts'])
                        : 0;
                    $font_names = array_merge($font_names, self::get_entry_font_names($existing_styles[$css_url]));
                }
                continue;
            }

            $stylesheets[$css_url] = $cached;
            $font_count += isset($cached['fonts']) && is_array($cached['fonts']) ? count($cached['fonts']) : 0;
            $font_names = array_merge($font_names, self::get_entry_font_names($cached));
        }

        $font_names = self::normalize_font_names($font_names);

        $cache = [
            'version'     => self::CACHE_VERSION,
            'updated_at'  => time(),
            'stylesheets' => $stylesheets,
            'scan'        => [
                'pages'       => $pages,
                'source_urls' => array_keys($found),
            ],
            'errors'      => array_slice($errors, 0, 12),
        ];

        update_option(self::OPTION_CACHE, $cache, false);

        if (class_exists('TW_Perf_Cache_Purger')) {
            TW_Perf_Cache_Purger::purge();
        }

        $result = [
            'ok'                   => true,
            'scanned_pages'        => count($pages),
            'found_stylesheets'    => count($found),
            'cached_stylesheets'   => count($stylesheets),
            'cached_fonts'         => $font_count,
            'font_names'           => $font_names,
            'errors'               => array_slice($errors, 0, 12),
            'pages'                => $pages,
            'cache_url'            => $location['url'],
            'updated_at'           => $cache['updated_at'],
            'updated_at_label'     => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $cache['updated_at']),
        ];

        delete_transient(self::SCAN_TRANSIENT_PREFIX . $token);

        return $result;
    }

    public static function purge_cache(): array {
        $location = self::get_location(false);
        if (is_wp_error($location)) {
            return [
                'ok'     => false,
                'errors' => [$location->get_error_message()],
            ];
        }

        $removed = 0;
        if (is_dir($location['dir']) && is_readable($location['dir'])) {
            $handle = opendir($location['dir']);
            if ($handle) {
                while (false !== ($file = readdir($handle))) {
                    if (!preg_match('/^twperf-gf-(?:css|font)-[a-f0-9]+\.(?:css|woff2?|ttf|otf|eot)$/', $file)) {
                        continue;
                    }
                    $path = trailingslashit($location['dir']) . $file;
                    if (is_file($path) && wp_delete_file($path)) {
                        $removed++;
                    }
                }
                closedir($handle);
            }
        }

        update_option(self::OPTION_CACHE, [
            'version'     => self::CACHE_VERSION,
            'updated_at'  => 0,
            'stylesheets' => [],
            'scan'        => [],
            'errors'      => [],
        ], false);

        if (class_exists('TW_Perf_Cache_Purger')) {
            TW_Perf_Cache_Purger::purge();
        }

        return [
            'ok'                 => true,
            'removed'            => $removed,
            'cached_stylesheets' => 0,
            'cached_fonts'       => 0,
            'font_names'         => [],
            'updated_at_label'   => 'Never',
        ];
    }

    public static function rewrite_html(string $html): string {
        $cache       = self::get_cache();
        $stylesheets = self::get_cached_stylesheets($cache);
        if (!$stylesheets) return $html;

        $map = [];
        foreach ($stylesheets as $original => $entry) {
            $source = self::normalize_google_css_url((string) ($entry['original_url'] ?? $original));
            if (!$source || empty($entry['local_url'])) continue;
            $map[$source] = esc_url_raw((string) $entry['local_url']);
        }
        if (!$map) return $html;

        $html = preg_replace_callback('/<link\b[^>]*>/i', function (array $match) use ($map): string {
            $tag = $match[0];
            if (stripos($tag, 'fonts.googleapis.com') === false) return $tag;
            if (!preg_match('/\bhref\s*=\s*([\'"])(.*?)\1/i', $tag, $href_match)) return $tag;

            $source = self::normalize_google_css_url($href_match[2]);
            $local  = $source ? self::find_local_stylesheet($source, $map) : null;
            if (!$local) return $tag;

            return preg_replace(
                '/\bhref\s*=\s*([\'"])(.*?)\1/i',
                'href="' . esc_url($local) . '"',
                $tag,
                1
            ) ?: $tag;
        }, $html) ?? $html;

        $html = self::rewrite_imports($html, $map);

        $html = preg_replace_callback('/<link\b[^>]*>/i', function (array $match): string {
            $tag = $match[0];
            if (stripos($tag, 'fonts.googleapis.com') === false && stripos($tag, 'fonts.gstatic.com') === false) {
                return $tag;
            }
            if (!preg_match('/\brel\s*=\s*([\'"])(.*?)\1/i', $tag, $rel_match)) {
                return $tag;
            }
            $rels = preg_split('/\s+/', strtolower($rel_match[2]));
            if (in_array('preconnect', $rels, true) || in_array('dns-prefetch', $rels, true)) {
                return '';
            }
            return $tag;
        }, $html) ?? $html;

        return $html;
    }

    private static function get_cache(): array {
        $cache = get_option(self::OPTION_CACHE, []);
        return is_array($cache) ? $cache : [];
    }

    private static function get_cached_stylesheets(array $cache): array {
        return isset($cache['stylesheets']) && is_array($cache['stylesheets']) ? $cache['stylesheets'] : [];
    }

    private static function get_location(bool $create): array|\WP_Error {
        $uploads = wp_upload_dir(null, $create, false);
        if (!empty($uploads['error'])) {
            return new \WP_Error('twperf_upload_dir_error', (string) $uploads['error']);
        }

        $dir = trailingslashit($uploads['basedir']) . 'tw-performance/google-fonts';
        $url = trailingslashit($uploads['baseurl']) . 'tw-performance/google-fonts';

        if (!$create) {
            return ['dir' => $dir, 'url' => $url];
        }

        if (!wp_mkdir_p($dir)) {
            return new \WP_Error('twperf_cache_dir_error', 'Could not create local Google Fonts cache directory.');
        }
        if (!self::can_write_to_dir($dir)) {
            return new \WP_Error('twperf_cache_dir_unwritable', 'Local Google Fonts cache directory is not writable.');
        }

        $index = trailingslashit($dir) . 'index.html';
        if (!file_exists($index)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents($index, '');
        }

        return ['dir' => $dir, 'url' => $url];
    }

    private static function can_write_to_dir(string $dir): bool {
        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!WP_Filesystem()) {
            return false;
        }

        if (!$wp_filesystem) {
            return false;
        }

        $test_file = trailingslashit($dir) . '.twperf-write-test';
        $chmod     = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : false;
        if (!$wp_filesystem->put_contents($test_file, '', $chmod)) {
            return false;
        }

        $wp_filesystem->delete($test_file);
        return true;
    }

    private static function get_scan_targets(): array {
        $targets = [];

        self::add_scan_target($targets, 'Home page', home_url('/'));

        $page_for_posts = absint(get_option('page_for_posts'));
        if ($page_for_posts) {
            self::add_scan_target($targets, 'Post archive', get_permalink($page_for_posts));
        } else {
            $categories = get_categories([
                'hide_empty' => true,
                'number'     => 1,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ]);
            if (!empty($categories[0])) {
                self::add_scan_target($targets, 'Post archive', get_category_link($categories[0]));
            }
        }

        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);
        if (!empty($posts[0])) {
            self::add_scan_target($targets, 'Single post', get_permalink($posts[0]));
        }

        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);
        if (!empty($pages[0])) {
            self::add_scan_target($targets, 'Sample page', get_permalink($pages[0]));
        }

        if (post_type_exists('product')) {
            $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : get_post_type_archive_link('product');
            if ($shop) {
                self::add_scan_target($targets, 'Product archive', $shop);
            }

            $products = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
            ]);
            if (!empty($products[0])) {
                self::add_scan_target($targets, 'Single product', get_permalink($products[0]));
            }
        }

        return array_values($targets);
    }

    private static function add_scan_target(array &$targets, string $label, $url): void {
        if (!$url || is_wp_error($url)) return;

        $url = esc_url_raw((string) $url);
        if (!$url || !self::is_same_site_url($url)) return;

        $key = untrailingslashit($url);
        if (isset($targets[$key])) return;

        $targets[$key] = [
            'label' => $label,
            'url'   => $url,
        ];
    }

    private static function is_same_site_url(string $url): bool {
        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $url_host  = wp_parse_url($url, PHP_URL_HOST);

        return $site_host && $url_host && strtolower($site_host) === strtolower($url_host);
    }

    private static function extract_stylesheet_urls(string $html): array {
        $urls = [];
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match_all('/<link\b[^>]*\bhref\s*=\s*([\'"])(.*?)\1[^>]*>/i', $html, $matches)) {
            foreach ($matches[2] as $href) {
                $normalized = self::normalize_google_css_url($href);
                if ($normalized) $urls[$normalized] = true;
            }
        }

        $import_patterns = [
            '/@import\s+url\(\s*([\'"])([^\'"]*fonts\.googleapis\.com[^\'"]*)\1\s*\)/i',
            '/@import\s+([\'"])([^\'"]*fonts\.googleapis\.com[^\'"]*)\1/i',
            '/@import\s+url\(\s*([^\'"\)]*fonts\.googleapis\.com[^)]*)\s*\)/i',
        ];

        foreach ($import_patterns as $pattern) {
            if (!preg_match_all($pattern, $html, $matches)) continue;
            $group = isset($matches[2]) && $matches[2] ? $matches[2] : $matches[1];
            foreach ($group as $href) {
                $normalized = self::normalize_google_css_url($href);
                if ($normalized) $urls[$normalized] = true;
            }
        }

        return array_keys($urls);
    }

    private static function cache_stylesheet(string $css_url, array $location): array|\WP_Error {
        $response = wp_remote_get($css_url, [
            'timeout'             => 15,
            'redirection'         => 3,
            'sslverify'           => true,
            'limit_response_size' => self::MAX_CSS_BYTES,
            'headers'             => [
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'text/css,*/*;q=0.1',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return new \WP_Error('twperf_google_css_http_error', 'Google Fonts CSS returned HTTP ' . $code . '.');
        }

        $css = (string) wp_remote_retrieve_body($response);
        if ($css === '') {
            return new \WP_Error('twperf_google_css_empty', 'Google Fonts CSS response was empty.');
        }

        $font_names = self::extract_font_names($css);
        $font_urls = self::extract_font_urls($css);
        $rewritten = $css;
        $fonts     = [];

        foreach ($font_urls as $font_url) {
            $font = self::download_font($font_url, $location);
            if (is_wp_error($font)) {
                return $font;
            }

            $fonts[]   = $font;
            $rewritten = str_replace($font_url, $font['local_url'], $rewritten);
        }

        $rewritten = self::ensure_font_display_swap($rewritten);

        $file = 'twperf-gf-css-' . substr(sha1($css_url), 0, 24) . '.css';
        $path = trailingslashit($location['dir']) . $file;
        $updated_at = time();
        $url        = add_query_arg('ver', (string) $updated_at, trailingslashit($location['url']) . $file);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = file_put_contents($path, $rewritten, LOCK_EX);
        if (false === $written) {
            return new \WP_Error('twperf_google_css_write_error', 'Could not write local Google Fonts CSS.');
        }

        return [
            'original_url' => $css_url,
            'local_url'    => $url,
            'local_file'   => $path,
            'fonts'        => $fonts,
            'font_names'   => $font_names,
            'updated_at'   => $updated_at,
            'bytes'        => (int) $written,
        ];
    }

    private static function extract_font_urls(string $css): array {
        $urls = [];
        if (!preg_match_all('/url\(\s*([\'"]?)(https?:\/\/fonts\.gstatic\.com\/[^\'"\)]+)\1\s*\)/i', $css, $matches)) {
            return [];
        }

        foreach ($matches[2] as $url) {
            $normalized = self::normalize_google_font_url($url);
            if ($normalized) $urls[$normalized] = true;
        }

        return array_keys($urls);
    }

    private static function extract_font_names(string $css): array {
        $names = [];
        if (!preg_match_all('/font-family\s*:\s*([^;}{]+)\s*;/i', $css, $matches)) {
            return [];
        }

        foreach ($matches[1] as $name) {
            $name = trim((string) $name);
            $parts = explode(',', $name, 2);
            $name = trim($parts[0]);
            $name = trim($name, "\"' \t\n\r\0\x0B");
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return self::normalize_font_names($names);
    }

    private static function collect_font_names(array $stylesheets): array {
        $names = [];
        foreach ($stylesheets as $entry) {
            if (!is_array($entry)) continue;
            $names = array_merge($names, self::get_entry_font_names($entry));
        }

        return self::normalize_font_names($names);
    }

    private static function get_entry_font_names(array $entry): array {
        if (!empty($entry['font_names']) && is_array($entry['font_names'])) {
            return self::normalize_font_names($entry['font_names']);
        }

        return self::extract_font_names_from_url((string) ($entry['original_url'] ?? ''));
    }

    private static function extract_font_names_from_url(string $url): array {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        if ($query === '') return [];

        $names = [];
        foreach (explode('&', $query) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            if (urldecode($key) !== 'family') continue;

            foreach (explode('|', urldecode($value)) as $family) {
                $family = trim((string) $family);
                if ($family === '') continue;
                $parts   = explode(':', $family, 2);
                $names[] = trim($parts[0]);
            }
        }

        return self::normalize_font_names($names);
    }

    private static function normalize_font_names(array $names): array {
        $normalized = [];
        foreach ($names as $name) {
            $name = sanitize_text_field((string) $name);
            $name = trim(preg_replace('/\s+/', ' ', $name) ?: $name);
            if ($name === '') continue;
            $normalized[strtolower($name)] = $name;
        }

        natcasesort($normalized);
        return array_values($normalized);
    }

    private static function download_font(string $font_url, array $location): array|\WP_Error {
        $font_url = self::normalize_google_font_url($font_url);
        if (!$font_url) {
            return new \WP_Error('twperf_google_font_invalid', 'Invalid Google font file URL.');
        }

        $ext = self::font_extension_from_url($font_url);
        if (!$ext) {
            return new \WP_Error('twperf_google_font_extension', 'Unsupported Google font file extension.');
        }

        $response = wp_remote_get($font_url, [
            'timeout'             => 20,
            'redirection'         => 3,
            'sslverify'           => true,
            'limit_response_size' => self::MAX_FONT_BYTES,
            'headers'             => [
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'font/woff2,font/woff,*/*;q=0.8',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return new \WP_Error('twperf_google_font_http_error', 'Google font file returned HTTP ' . $code . '.');
        }

        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return new \WP_Error('twperf_google_font_empty', 'Google font file response was empty.');
        }

        $file = 'twperf-gf-font-' . substr(sha1($font_url), 0, 24) . '.' . $ext;
        $path = trailingslashit($location['dir']) . $file;
        $updated_at = time();
        $url        = add_query_arg('ver', (string) $updated_at, trailingslashit($location['url']) . $file);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = file_put_contents($path, $body, LOCK_EX);
        if (false === $written) {
            return new \WP_Error('twperf_google_font_write_error', 'Could not write local Google font file.');
        }

        return [
            'original_url' => $font_url,
            'local_url'    => $url,
            'local_file'   => $path,
            'updated_at'   => $updated_at,
            'bytes'        => (int) $written,
        ];
    }

    private static function font_extension_from_url(string $url): string {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['woff2', 'woff', 'ttf', 'otf', 'eot'], true) ? $ext : '';
    }

    private static function normalize_google_css_url(string $url): ?string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = trim($url, "\"' \t\n\r\0\x0B");
        if ($url === '') return null;
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        $url = preg_replace('/^http:/i', 'https:', $url) ?: $url;

        $host = wp_parse_url($url, PHP_URL_HOST);
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (strtolower((string) $host) !== 'fonts.googleapis.com') return null;
        if (!$path || strpos($path, '/css') !== 0) return null;

        return esc_url_raw($url);
    }

    private static function normalize_google_font_url(string $url): ?string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = trim($url, "\"' \t\n\r\0\x0B");
        if ($url === '') return null;
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        $url = preg_replace('/^http:/i', 'https:', $url) ?: $url;

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (strtolower((string) $host) !== 'fonts.gstatic.com') return null;

        return esc_url_raw($url);
    }

    private static function ensure_font_display_swap(string $css): string {
        $css = preg_replace(
            '/font-display\s*:\s*(?:block|fallback|auto|optional)\s*;/i',
            'font-display:swap;',
            $css
        ) ?? $css;

        return preg_replace_callback('/(@font-face\s*\{)([^}]+)(\})/i', function (array $match): string {
            if (stripos($match[2], 'font-display') !== false) return $match[0];
            return $match[1] . $match[2] . 'font-display:swap;' . $match[3];
        }, $css) ?? $css;
    }

    private static function rewrite_imports(string $html, array $map): string {
        $patterns = [
            '/@import\s+url\(\s*([\'"])([^\'"]*fonts\.googleapis\.com[^\'"]*)\1\s*\)\s*;?/i',
            '/@import\s+([\'"])([^\'"]*fonts\.googleapis\.com[^\'"]*)\1\s*;?/i',
            '/@import\s+url\(\s*([^\'"\)]*fonts\.googleapis\.com[^)]*)\s*\)\s*;?/i',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace_callback($pattern, function (array $match) use ($map): string {
                $raw    = isset($match[2]) ? $match[2] : $match[1];
                $source = self::normalize_google_css_url($raw);
                $local  = $source ? self::find_local_stylesheet($source, $map) : null;

                return $local ? '@import url("' . esc_url($local) . '");' : $match[0];
            }, $html) ?? $html;
        }

        return $html;
    }

    private static function find_local_stylesheet(string $source, array $map): ?string {
        if (isset($map[$source])) return $map[$source];

        $decoded_source = rawurldecode($source);
        foreach ($map as $remote => $local) {
            if (rawurldecode($remote) === $decoded_source) {
                return $local;
            }
        }

        return null;
    }
}
