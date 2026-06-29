<?php
defined('ABSPATH') || exit;

class TW_Perf_Admin {

    public function init(): void {
        add_action('admin_menu',       [$this, 'add_menu']);
        add_action('admin_init',       [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void {
        add_menu_page(
            'TW Perf Intelligence',
            'TW Perf',
            'manage_options',
            'tw-performance',
            [$this, 'render_settings_page'],
            'dashicons-performance',
            58
        );

        add_submenu_page(
            'tw-performance',
            'TW Perf Intelligence — Settings',
            'Settings',
            'manage_options',
            'tw-performance',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'tw-performance',
            'TW Perf Intelligence — All Rules',
            'Rules',
            'manage_options',
            'tw-performance-rules',
            [$this, 'render_all_rules_page']
        );

        add_submenu_page(
            'tw-performance',
            'TW Perf Intelligence — Profiler',
            'Profiler',
            'manage_options',
            'tw-performance-profiler',
            [$this, 'render_profiler_page']
        );
    }

    public function register_settings(): void {
        register_setting('twperf_settings', 'twperf_always_load_panel', ['type' => 'boolean', 'default' => false]);
        register_setting('twperf_settings', 'twperf_test_mode',    ['type' => 'boolean', 'default' => true]);
        register_setting('twperf_settings', 'twperf_profiler_enabled', ['type' => 'boolean', 'default' => false]);
        register_setting('twperf_settings', 'twperf_profiler_period_ms', [
            'type'              => 'integer',
            'default'           => 5,
            'sanitize_callback' => fn($val) => max(1, min(1000, absint($val))),
        ]);
        register_setting('twperf_settings', 'twperf_profiler_max_depth', [
            'type'              => 'integer',
            'default'           => 80,
            'sanitize_callback' => fn($val) => max(0, min(500, absint($val))),
        ]);
        register_setting('twperf_settings', 'twperf_profiler_retention', [
            'type'              => 'integer',
            'default'           => 20,
            'sanitize_callback' => fn($val) => max(1, min(100, absint($val))),
        ]);
        register_setting('twperf_settings', 'twperf_psi_api_key',  ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field']);
        register_setting('twperf_settings', 'twperf_delay_events', [
            'type'              => 'array',
            'sanitize_callback' => function ($val) {
                $allowed = ['scroll', 'mousemove', 'touchstart', 'keydown', 'click', 'wheel'];
                return is_array($val) ? array_values(array_intersect(array_map('sanitize_key', $val), $allowed)) : [];
            },
        ]);
        register_setting('twperf_settings', 'twperf_preloads', [
            'type'              => 'array',
            'sanitize_callback' => function ($val) {
                if (!is_array($val)) return [];
                return array_values(array_filter(array_map(function ($item) {
                    if (!is_array($item) || empty($item['href'])) return null;
                    $allowed_as = ['style', 'script', 'font', 'image', 'fetch', 'document'];
                    return [
                        'href'        => esc_url_raw($item['href']),
                        'as'          => isset($item['as']) && in_array($item['as'], $allowed_as, true) ? $item['as'] : '',
                        'type'        => isset($item['type'])        ? sanitize_text_field($item['type'])  : '',
                        'crossorigin' => !empty($item['crossorigin']),
                        'media'       => isset($item['media'])       ? sanitize_text_field($item['media']) : '',
                    ];
                }, $val)));
            },
        ]);
        register_setting('twperf_settings', 'twperf_remove_emoji',     ['type' => 'boolean', 'default' => true]);
        register_setting('twperf_settings', 'twperf_clean_head',        ['type' => 'boolean', 'default' => true]);
        register_setting('twperf_settings', 'twperf_heartbeat',         ['type' => 'string',  'sanitize_callback' => 'sanitize_key', 'default' => 'frontend']);
        register_setting('twperf_settings', 'twperf_fix_font_display',  ['type' => 'boolean', 'default' => false]);
        register_setting('twperf_settings', 'twperf_fix_lcp_attrs',     ['type' => 'boolean', 'default' => false]);
        register_setting('twperf_settings', 'twperf_remove_gfonts',     ['type' => 'boolean', 'default' => false]);
        register_setting('twperf_settings', 'twperf_local_gfonts_enabled', ['type' => 'boolean', 'default' => false]);
        register_setting('twperf_settings', 'twperf_critical_css',      [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => function ($val) {
                // Admins have unfiltered_html capability; strip only null bytes
                return str_replace("\0", '', (string) $val);
            },
        ]);
    }

    public function enqueue_assets(string $hook): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing for asset loading
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if (!in_array($page, ['tw-performance', 'tw-performance-rules', 'tw-performance-profiler'], true)) return;

        $admin_css_version = file_exists(TWPERF_DIR . 'assets/css/admin.css')
            ? (string) filemtime(TWPERF_DIR . 'assets/css/admin.css')
            : TWPERF_VERSION;
        $admin_js_version = file_exists(TWPERF_DIR . 'assets/js/admin.js')
            ? (string) filemtime(TWPERF_DIR . 'assets/js/admin.js')
            : TWPERF_VERSION;

        wp_enqueue_style('twperf-admin', TWPERF_URL . 'assets/css/admin.css', [], $admin_css_version);
        wp_enqueue_script('twperf-admin', TWPERF_URL . 'assets/js/admin.js', ['jquery'], $admin_js_version, true);
        wp_localize_script('twperf-admin', 'twperfAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('twperf_nonce'),
        ]);
    }

    public function render_settings_page(): void {
        include TWPERF_DIR . 'admin/views/settings.php';
    }

    public function render_all_rules_page(): void {
        include TWPERF_DIR . 'admin/views/all-rules.php';
    }

    public function render_profiler_page(): void {
        include TWPERF_DIR . 'admin/views/profiler.php';
    }
}
