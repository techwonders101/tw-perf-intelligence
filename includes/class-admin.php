<?php
defined('ABSPATH') || exit;

class TW_Perf_Admin {

    public function init(): void {
        add_action('admin_menu',       [$this, 'add_menu']);
        add_action('admin_init',       [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void {
        add_options_page(
            'TW Perf Intelligence',
            'TW Perf Intelligence',
            'manage_options',
            'tw-performance',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'options-general.php',
            'TW Perf Intelligence — All Rules',
            'TW Perf: Rules',
            'manage_options',
            'tw-performance-rules',
            [$this, 'render_all_rules_page']
        );
    }

    public function register_settings(): void {
        register_setting('twperf_settings', 'twperf_always_load_panel', ['type' => 'boolean', 'default' => false]);
        register_setting('twperf_settings', 'twperf_test_mode',    ['type' => 'boolean', 'default' => true]);
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
        if (!in_array($hook, ['settings_page_tw-performance', 'settings_page_tw-performance-rules'], true)) return;

        wp_enqueue_style('twperf-admin', TWPERF_URL . 'assets/css/admin.css', [], TWPERF_VERSION);
        wp_enqueue_script('twperf-admin', TWPERF_URL . 'assets/js/admin.js', ['jquery'], TWPERF_VERSION, true);
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
}
