<?php
defined('ABSPATH') || exit;

class TW_Perf_Preload_Manager {

    public function init(): void {
        add_action('wp_head', [$this, 'output_preloads'],    1); // Priority 1 = very top of <head>
        add_action('wp_head', [$this, 'output_preconnects'], 1);
        add_filter('style_loader_tag', [$this, 'add_font_display_swap'], 10, 2);
    }

    // -------------------------------------------------------------------------
    // Output all <link rel="preload"> tags at the very top of <head>
    // -------------------------------------------------------------------------
    public function output_preloads(): void {
        $preloads = $this->collect_preloads();

        foreach ($preloads as $preload) {
            $attrs = '';
            foreach ($preload as $attr => $value) {
                if ($value === true) {
                    $attrs .= ' ' . esc_attr($attr);
                } elseif ($value !== false && $value !== null) {
                    $attrs .= sprintf(' %s="%s"', esc_attr($attr), esc_attr($value));
                }
            }
            echo '<link' . $attrs . '>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is built entirely with esc_attr() above
        }
    }

    // -------------------------------------------------------------------------
    // Collect all preload items
    // -------------------------------------------------------------------------
    private function collect_preloads(): array {
        $preloads = [];

        // 1. Manually configured preloads from settings
        $configured = get_option('twperf_preloads', []);
        if (!is_array($configured)) $configured = [];
        foreach ($configured as $item) {
            $preloads[] = $this->build_preload($item);
        }

        // 2. LCP image for current page (stored as post meta)
        $post_id = get_queried_object_id();
        if ($post_id) {
            $lcp_image = get_post_meta($post_id, '_twperf_lcp_image', true);
            if ($lcp_image) {
                $preloads[] = $this->build_image_preload($lcp_image);
            }
        }

        // 3. Auto-detect Google Fonts in enqueued styles and convert to preload
        $font_urls = $this->detect_google_fonts();
        foreach ($font_urls as $url) {
            $preloads[] = [
                'rel'         => 'preload',
                'href'        => $url,
                'as'          => 'font',
                'type'        => 'font/woff2',
                'crossorigin' => true,
            ];
        }

        // 4. Rules-based preloads
        $rules = TW_Perf_Rules::get_for_current_page();
        global $wp_styles, $wp_scripts;

        foreach ((array) ($rules['preload'] ?? []) as $handle) {
            // Check styles first
            if (isset($wp_styles->registered[$handle])) {
                $src = $wp_styles->registered[$handle]->src;
                $preloads[] = ['rel' => 'preload', 'href' => $src, 'as' => 'style'];
            } elseif (isset($wp_scripts->registered[$handle])) {
                $src = $wp_scripts->registered[$handle]->src;
                $preloads[] = ['rel' => 'preload', 'href' => $src, 'as' => 'script'];
            }
        }

        return array_filter($preloads);
    }

    // -------------------------------------------------------------------------
    // Build a preload tag array from a config item
    // -------------------------------------------------------------------------
    private function build_preload(array $item): ?array {
        if (empty($item['href'])) return null;

        $tag = ['rel' => 'preload', 'href' => $item['href']];

        if (!empty($item['as'])) $tag['as'] = $item['as'];
        if (!empty($item['type'])) $tag['type'] = $item['type'];
        if (!empty($item['crossorigin'])) $tag['crossorigin'] = true;
        if (!empty($item['media'])) $tag['media'] = $item['media'];

        return $tag;
    }

    private function build_image_preload(string $url): array {
        return [
            'rel'          => 'preload',
            'href'         => $url,
            'as'           => 'image',
            'fetchpriority'=> 'high',
        ];
    }

    // -------------------------------------------------------------------------
    // Detect Google Fonts in enqueued stylesheets
    // -------------------------------------------------------------------------
    private function detect_google_fonts(): array {
        global $wp_styles;
        $fonts = [];

        foreach ($wp_styles->registered as $handle => $style) {
            if (empty($style->src)) continue;
            if (strpos($style->src, 'fonts.googleapis.com') !== false) {
                $fonts[] = $style->src;
            }
        }

        return $fonts;
    }

    // -------------------------------------------------------------------------
    // Inject font-display: swap into Google Font stylesheet URLs
    // -------------------------------------------------------------------------
    public function add_font_display_swap(string $tag, string $handle): string {
        global $wp_styles;

        if (empty($wp_styles->registered[$handle]->src)) return $tag;

        $src = $wp_styles->registered[$handle]->src;

        if (strpos($src, 'fonts.googleapis.com') === false) return $tag;

        // Add display=swap to Google Fonts URL if not already present
        if (strpos($src, 'display=') === false) {
            $new_src = add_query_arg('display', 'swap', $src);
            $tag = str_replace($src, $new_src, $tag);
        }

        return $tag;
    }

    // -------------------------------------------------------------------------
    // Output <link rel="preconnect"> tags
    // -------------------------------------------------------------------------
    public function output_preconnects(): void {
        $items = get_option('twperf_preconnects', []);
        if (!is_array($items)) return;

        foreach ($items as $item) {
            if (empty($item['href'])) continue;
            $href        = esc_url($item['href']);
            $crossorigin = !empty($item['crossorigin']) ? ' crossorigin' : '';
            echo '<link rel="preconnect" href="' . $href . '"' . $crossorigin . '>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $href escaped with esc_url(), $crossorigin is a literal string
        }
    }

    // -------------------------------------------------------------------------
    // Save LCP image for a page (called from AJAX)
    // -------------------------------------------------------------------------
    public static function save_lcp_image(int $post_id, string $url): bool {
        return (bool) update_post_meta($post_id, '_twperf_lcp_image', esc_url_raw($url));
    }

    // -------------------------------------------------------------------------
    // Font preloads (stored inside twperf_preloads with as=font)
    // -------------------------------------------------------------------------
    public static function get_font_preloads(): array {
        $all = get_option('twperf_preloads', []);
        if (!is_array($all)) return [];
        return array_values(array_filter($all, fn($i) => isset($i['as']) && $i['as'] === 'font'));
    }

    public static function save_font_preload(string $url, bool $crossorigin): bool {
        $all = get_option('twperf_preloads', []);
        if (!is_array($all)) $all = [];

        // Avoid duplicates
        foreach ($all as $item) {
            if (isset($item['href']) && $item['href'] === $url) return true;
        }

        $all[] = [
            'href'        => esc_url_raw($url),
            'as'          => 'font',
            'type'        => 'font/woff2',
            'crossorigin' => $crossorigin,
        ];
        return (bool) update_option('twperf_preloads', $all);
    }

    public static function delete_font_preload(string $url): bool {
        $all = get_option('twperf_preloads', []);
        if (!is_array($all)) return false;
        $all = array_values(array_filter($all, fn($i) => ($i['href'] ?? '') !== $url || ($i['as'] ?? '') !== 'font'));
        return (bool) update_option('twperf_preloads', $all);
    }

    // -------------------------------------------------------------------------
    // Preconnect origins (twperf_preconnects option)
    // -------------------------------------------------------------------------
    public static function get_preconnects(): array {
        $items = get_option('twperf_preconnects', []);
        return is_array($items) ? array_values($items) : [];
    }

    public static function save_preconnect(string $origin, bool $crossorigin): bool {
        $items = self::get_preconnects();

        foreach ($items as $item) {
            if (($item['href'] ?? '') === $origin) return true; // already exists
        }

        $items[] = ['href' => esc_url_raw($origin), 'crossorigin' => $crossorigin];
        return (bool) update_option('twperf_preconnects', $items);
    }

    public static function delete_preconnect(string $origin): bool {
        $items = self::get_preconnects();
        $items = array_values(array_filter($items, fn($i) => ($i['href'] ?? '') !== $origin));
        return (bool) update_option('twperf_preconnects', $items);
    }
}
