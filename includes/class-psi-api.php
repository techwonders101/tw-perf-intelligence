<?php
defined('ABSPATH') || exit;

class TW_Perf_PSI_API {

    private string $api_key;
    private int    $cache_ttl = 3600; // 1 hour

    public function __construct() {
        $this->api_key = get_option('twperf_psi_api_key', '');
    }

    // -------------------------------------------------------------------------
    // Run PSI analysis for a URL
    // -------------------------------------------------------------------------
    public function analyse(string $url, string $strategy = 'mobile'): array|WP_Error {
        $cache_key = 'twperf_psi_' . md5($url . $strategy);
        $cached    = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
        $params   = [
            'url'      => $url,
            'strategy' => $strategy,
        ];

        if ($this->api_key) {
            $params['key'] = $this->api_key;
        }

        $response = wp_remote_get(
            add_query_arg($params, $endpoint),
            ['timeout' => 60, 'sslverify' => true]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return new WP_Error(
                'psi_error',
                $body['error']['message'] ?? 'PSI API error',
                ['status' => $code]
            );
        }

        $parsed = $this->parse_response($body);
        set_transient($cache_key, $parsed, $this->cache_ttl);

        return $parsed;
    }

    // -------------------------------------------------------------------------
    // Parse PSI response into useful structure
    // -------------------------------------------------------------------------
    private function parse_response(array $data): array {
        $lhr     = $data['lighthouseResult'] ?? [];
        $audits  = $lhr['audits'] ?? [];
        $cats    = $lhr['categories'] ?? [];

        return [
            'score'              => (int) round(($cats['performance']['score'] ?? 0) * 100),
            'metrics'            => $this->extract_metrics($audits),
            'field_data'         => $this->extract_field_data($data),
            'blocking_resources' => $this->extract_blocking($audits),
            'unused_js'          => $this->extract_unused_js($audits),
            'unused_css'         => $this->extract_unused_css($audits),
            'render_blocking'    => $this->extract_render_blocking($audits),
            'opportunities'      => $this->extract_opportunities($audits),
            'lcp_element'        => $this->extract_lcp_element($audits),
            'fetched_at'         => time(),
        ];
    }

    private function extract_metrics(array $audits): array {
        $keys = [
            'first-contentful-paint'    => 'fcp',
            'largest-contentful-paint'  => 'lcp',
            'total-blocking-time'       => 'tbt',
            'cumulative-layout-shift'   => 'cls',
            'interaction-to-next-paint' => 'inp',
            'speed-index'               => 'si',
            'interactive'               => 'tti',
        ];

        $metrics = [];
        foreach ($keys as $audit_key => $short) {
            $audit = $audits[$audit_key] ?? null;
            if ($audit) {
                $metrics[$short] = [
                    'value'       => $audit['numericValue'] ?? 0,
                    'display'     => $audit['displayValue'] ?? '',
                    'score'       => $audit['score'] ?? null,
                ];
            }
        }
        return $metrics;
    }

    /**
     * Extract CrUX field data (real-user Core Web Vitals) from loadingExperience.
     * Falls back to originLoadingExperience if page-level data is unavailable.
     */
    private function extract_field_data(array $data): ?array {
        $exp = $data['loadingExperience'] ?? null;
        $origin = $data['originLoadingExperience'] ?? null;

        // Prefer page-level; fall back to origin if page has insufficient traffic
        $source = ($exp && isset($exp['metrics'])) ? $exp : (isset($origin['metrics']) ? $origin : null);
        if (!$source) return null;

        $key_map = [
            'LARGEST_CONTENTFUL_PAINT_MS'  => ['label' => 'LCP',  'unit' => 'ms'],
            'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['label' => 'CLS',  'unit' => ''],
            'INTERACTION_TO_NEXT_PAINT'     => ['label' => 'INP',  'unit' => 'ms'],
            'FIRST_CONTENTFUL_PAINT_MS'     => ['label' => 'FCP',  'unit' => 'ms'],
            'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => ['label' => 'TTFB', 'unit' => 'ms'],
        ];

        $metrics = [];
        foreach ($key_map as $crux_key => $meta) {
            $m = $source['metrics'][$crux_key] ?? null;
            if (!$m) continue;
            $category = strtolower($m['category'] ?? 'average');
            $metrics[] = [
                'key'      => $crux_key,
                'label'    => $meta['label'],
                'unit'     => $meta['unit'],
                'value'    => $m['percentile'] ?? null,
                'category' => $category, // 'fast', 'average', 'slow'
            ];
        }

        return [
            'overall'   => strtolower($source['overall_category'] ?? ''),
            'is_origin' => $source === $origin,
            'metrics'   => $metrics,
        ];
    }

    private function extract_blocking(array $audits): array {
        $items = $audits['render-blocking-resources']['details']['items'] ?? [];
        return array_map(fn($i) => [
            'url'      => $i['url'] ?? '',
            'size'     => $i['totalBytes'] ?? 0,
            'duration' => $i['wastedMs'] ?? 0,
        ], $items);
    }

    private function extract_unused_js(array $audits): array {
        $items = $audits['unused-javascript']['details']['items'] ?? [];
        return array_map(fn($i) => [
            'url'     => $i['url'] ?? '',
            'wasted'  => $i['wastedBytes'] ?? 0,
        ], $items);
    }

    private function extract_unused_css(array $audits): array {
        $items = $audits['unused-css-rules']['details']['items'] ?? [];
        return array_map(fn($i) => [
            'url'    => $i['url'] ?? '',
            'wasted' => $i['wastedBytes'] ?? 0,
        ], $items);
    }

    private function extract_render_blocking(array $audits): array {
        return [
            'savings_ms' => $audits['render-blocking-resources']['numericValue'] ?? 0,
            'items'      => $audits['render-blocking-resources']['details']['items'] ?? [],
        ];
    }

    private function extract_opportunities(array $audits): array {
        $opportunity_keys = [
            'render-blocking-resources',
            'unused-javascript',
            'unused-css-rules',
            'uses-optimized-images',
            'uses-webp-images',
            'uses-text-compression',
            'uses-long-cache-ttl',
            'efficient-animated-content',
        ];

        $out = [];
        foreach ($opportunity_keys as $key) {
            $audit = $audits[$key] ?? null;
            if (!$audit || ($audit['score'] ?? 1) >= 0.9) continue;
            $out[] = [
                'id'          => $key,
                'title'       => $audit['title'] ?? $key,
                'description' => $audit['description'] ?? '',
                'savings_ms'  => $audit['numericValue'] ?? 0,
                'savings_bytes'=> $audit['details']['overallSavingsBytes'] ?? 0,
                'score'       => $audit['score'] ?? null,
            ];
        }
        return $out;
    }

    private function extract_lcp_element(array $audits): ?string {
        $items = $audits['largest-contentful-paint-element']['details']['items'] ?? [];
        return $items[0]['node']['snippet'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Cross-reference PSI blocking URLs with WP registered handles
    // -------------------------------------------------------------------------
    public static function match_handles_to_blocking(
        array $blocking_urls,
        array $client_scripts = [],
        array $client_styles  = []
    ): array {
        $matches = [];

        foreach ($blocking_urls as $item) {
            $url = $item['url'] ?? '';
            if (!$url) continue;

            // Check scripts (from client-supplied data)
            foreach ($client_scripts as $handle => $script) {
                $src = $script['src'] ?? '';
                if ($src && self::urls_match($url, $src)) {
                    $matches[$handle] = array_merge($item, ['asset_type' => 'script', 'handle' => $handle]);
                }
            }

            // Check styles (from client-supplied data)
            foreach ($client_styles as $handle => $style) {
                $src = $style['src'] ?? '';
                if ($src && self::urls_match($url, $src)) {
                    $matches[$handle] = array_merge($item, ['asset_type' => 'style', 'handle' => $handle]);
                }
            }
        }

        // Fallback: try globals if no client data provided and globals exist
        if (empty($matches) && empty($client_scripts) && empty($client_styles)) {
            global $wp_scripts, $wp_styles;
            if ($wp_scripts && $wp_styles) {
                foreach ($blocking_urls as $item) {
                    $url = $item['url'] ?? '';
                    if (!$url) continue;
                    foreach ($wp_scripts->registered as $handle => $script) {
                        if ($script->src && self::urls_match($url, $script->src)) {
                            $matches[$handle] = array_merge($item, ['asset_type' => 'script', 'handle' => $handle]);
                        }
                    }
                    foreach ($wp_styles->registered as $handle => $style) {
                        if ($style->src && self::urls_match($url, $style->src)) {
                            $matches[$handle] = array_merge($item, ['asset_type' => 'style', 'handle' => $handle]);
                        }
                    }
                }
            }
        }

        return $matches;
    }

    private static function urls_match(string $a, string $b): bool {
        // Strip query strings and protocol for fuzzy match
        $normalise = fn($u) => preg_replace('/^https?:\/\//', '//', strtok($u, '?'));
        return $normalise($a) === $normalise($b)
            || str_contains($normalise($b), basename(strtok($a, '?')));
    }
}
