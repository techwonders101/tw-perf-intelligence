<?php
defined('ABSPATH') || exit;

class TW_Perf_Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        // Core optimisation — runs on every frontend request
        if (!is_admin() || wp_doing_ajax()) {
            $optimizer = new TW_Perf_Asset_Optimizer();
            $optimizer->init();

            $preload = new TW_Perf_Preload_Manager();
            $preload->init();

            $html_optimizer = new TW_Perf_HTML_Optimizer();
            $html_optimizer->init();

        }

        // Admin panel
        if (is_admin() || current_user_can('manage_options')) {
            $admin = new TW_Perf_Admin();
            $admin->init();
        }

        // AJAX handlers (admin + frontend)
        $ajax = new TW_Perf_Ajax();
        $ajax->init();

        // Admin bar on frontend
        add_action('admin_bar_menu', [$this, 'admin_bar_node'], 100);

        // Panel assets + HTML — only when panel should be active this request
        if ($this->should_load_panel()) {
            add_action('wp_footer',          [$this, 'maybe_inject_panel']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_panel_assets'], PHP_INT_MAX);
            add_action('wp_footer',          [$this, 'inject_late_asset_patch'], PHP_INT_MAX);
            // Listener patch must run before all scripts to capture page-load addEventListener calls
            add_action('wp_head', [$this, 'inject_listener_patch'], -999);
        }
    }

    /**
     * Decide whether to load the panel on this request.
     * True when: always-load setting is on, OR the ?twperf=1 query param is present.
     * Non-admins always get false.
     */
    private function should_load_panel(): bool {
        if (!current_user_can('manage_options')) return false;
        if (is_admin()) return false;
        if (get_option('twperf_always_load_panel', false)) return true;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag, no state change
        if (isset($_GET['twperf'])) return true;
        return false;
    }

    public function admin_bar_node(\WP_Admin_Bar $bar): void {
        if (!current_user_can('manage_options')) return;

        $test_mode = get_option('twperf_test_mode', false);
        $preview   = isset($_COOKIE['twperf_preview']);

        $bar->add_node([
            'id'    => 'twperf',
            'title' => 'TW Perf' . ($test_mode ? ' <span style="color:#f0a500">[TEST]</span>' : ''),
            'href'  => '#',
            'meta'  => ['class' => 'twperf-adminbar'],
        ]);

        if ( ! is_admin() ) {
            // If panel assets are already loaded, JS handles the click in-page.
            // Otherwise send to ?twperf=1 which triggers a full load + auto-analysis.
            $analyse_href = $this->should_load_panel()
                ? '#twperf-analyse'
                : esc_url(add_query_arg('twperf', '1', get_permalink() ?: home_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/')));
            $bar->add_node([
                'id'     => 'twperf-analyse',
                'parent' => 'twperf',
                'title'  => 'Analyse This Page',
                'href'   => $analyse_href,
            ]);

            $bar->add_node([
                'id'     => 'twperf-toggle-preview',
                'parent' => 'twperf',
                'title'  => $preview ? 'Exit Preview Mode' : 'Enter Preview Mode',
                'href'   => '#twperf-toggle-preview',
            ]);
        }

        $bar->add_node([
            'id'     => 'twperf-settings',
            'parent' => 'twperf',
            'title'  => 'Settings',
            'href'   => admin_url('options-general.php?page=tw-performance'),
        ]);

        $bar->add_node([
            'id'     => 'twperf-all-rules',
            'parent' => 'twperf',
            'title'  => 'All Rules',
            'href'   => admin_url('options-general.php?page=tw-performance-rules'),
        ]);
    }

    public function enqueue_panel_assets(): void {
        if (!current_user_can('manage_options')) return;

        wp_enqueue_style(
            'twperf-panel',
            TWPERF_URL . 'assets/css/panel.css',
            [],
            TWPERF_VERSION
        );

        wp_enqueue_script(
            'twperf-panel',
            TWPERF_URL . 'assets/js/panel.js',
            [],
            TWPERF_VERSION,
            true
        );

        // Pass data to JS
        global $wp_scripts, $wp_styles;

        $enqueued_scripts = [];
        foreach ($wp_scripts->queue as $handle) {
            $s = $wp_scripts->registered[$handle] ?? null;
            if (!$s) continue;
            $enqueued_scripts[$handle] = [
                'handle' => $handle,
                'src'    => $s->src,
                'deps'   => $s->deps,
                'ver'    => $s->ver,
                // extra intentionally omitted — may contain other plugins' API keys / nonces
            ];
        }

        $enqueued_styles = [];
        foreach ($wp_styles->queue as $handle) {
            $s = $wp_styles->registered[$handle] ?? null;
            if (!$s) continue;
            $enqueued_styles[$handle] = [
                'handle' => $handle,
                'src'    => $s->src,
                'deps'   => $s->deps,
                'ver'    => $s->ver,
            ];
        }

        // Detect page context properly (archives, WooCommerce, taxonomies, etc.)
        $post_type = get_post_type() ?: '';
        if (!$post_type) {
            $post_type = get_query_var('post_type') ?: '';
        }
        // WooCommerce product archives
        if (!$post_type && function_exists('is_woocommerce') && is_woocommerce()) {
            $post_type = 'product';
        }

        $page_context = 'page';
        if (is_front_page())                                                    $page_context = 'front_page';
        elseif (is_home())                                                      $page_context = 'blog';
        elseif (function_exists('is_shop') && is_shop())                       $page_context = 'shop';
        elseif (function_exists('is_product_category') && is_product_category()) $page_context = 'product_cat';
        elseif (function_exists('is_product_tag') && is_product_tag())         $page_context = 'product_tag';
        elseif (function_exists('is_product') && is_product())                 $page_context = 'product';
        elseif (function_exists('is_cart') && is_cart())                        $page_context = 'cart';
        elseif (function_exists('is_checkout') && is_checkout())               $page_context = 'checkout';
        elseif (function_exists('is_account_page') && is_account_page())       $page_context = 'account';
        elseif (is_category())                                                  $page_context = 'category';
        elseif (is_tag())                                                       $page_context = 'tag';
        elseif (is_tax())                                                       $page_context = 'taxonomy';
        elseif (is_post_type_archive())                                         $page_context = 'archive';
        elseif (is_archive())                                                   $page_context = 'archive';
        elseif (is_search())                                                    $page_context = 'search';
        elseif (is_404())                                                       $page_context = '404';
        elseif (is_singular())                                                  $page_context = 'singular';

        wp_localize_script('twperf-panel', 'twperf', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('twperf_nonce'),
            'current_url'     => get_permalink() ?: home_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/'),
            'post_id'         => get_queried_object_id(),
            'post_type'       => $post_type,
            'page_context'    => $page_context,
            'test_mode'       => (bool) get_option('twperf_test_mode'),
            'preview_active'  => isset($_COOKIE['twperf_preview']),
            'enqueued_scripts'=> $enqueued_scripts,
            'enqueued_styles' => $enqueued_styles,
            'signatures'      => TW_Perf_Intelligence::get_signatures(),
            'handle_map'      => TW_Perf_Intelligence::get_handle_map(),
            'handle_patterns' => array_map(
                fn($data) => ['sigs' => $data['sigs'], 'info' => $data['info']],
                TW_Perf_Intelligence::get_handle_patterns()
            ),
            'current_rules'      => TW_Perf_Rules::get_for_current_page('frontend', isset($_COOKIE['twperf_preview'])),
            'preview_only_rules' => TW_Perf_Rules::get_preview_only_for_panel(),
            'enabled_fixes'   => array_keys(array_filter([
                'font_display' => (bool) get_option('twperf_fix_font_display', false),
                'lcp_attrs'    => (bool) get_option('twperf_fix_lcp_attrs',    false),
            ])),
            'quick_wins'      => [
                'remove_emoji' => (bool) get_option('twperf_remove_emoji', true),
                'clean_head'   => (bool) get_option('twperf_clean_head',   true),
                'heartbeat'    => get_option('twperf_heartbeat', 'frontend'),
                'font_display'  => (bool) get_option('twperf_fix_font_display', false),
                'lcp_attrs'     => (bool) get_option('twperf_fix_lcp_attrs',    false),
                'remove_gfonts' => (bool) get_option('twperf_remove_gfonts',    false),
            ],
            'font_preloads'   => TW_Perf_Preload_Manager::get_font_preloads(),
            'preconnects'     => TW_Perf_Preload_Manager::get_preconnects(),
            'critical_css'    => get_option('twperf_critical_css', ''),
        ]);
    }

    /**
     * Inject a tiny addEventListener patch before all plugin scripts.
     * Runs at wp_head priority -999 so it executes before every other script.
     * Records which script src paths register which event types into
     * window.__twperfListeners — used by the interaction recorder in the panel.
     */
    public function inject_listener_patch(): void {
        if (!current_user_can('manage_options')) return;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS, no user data
        echo '<script id="twperf-listener-patch">(function(){' .
            'var R=window.__twperfListeners={};' .
            'var o=EventTarget.prototype.addEventListener;' .
            'if(!o)return;' .
            'EventTarget.prototype.addEventListener=function(t,fn,opts){' .
                'try{' .
                    'var s=(new Error).stack||"";' .
                    'var ms=[...s.matchAll(/(?:https?:\/\/[^/]+)(\/wp-content\/[^\s:)?\n]+)/g)];' .
                    'ms.forEach(function(m){var p=m[1].split("?")[0];if(!R[p])R[p]={};R[p][t]=1;});' .
                '}catch(e){}' .
                'return o.call(this,t,fn,opts);' .
            '};' .
        '})();</script>' . "\n";
    }

    public function inject_late_asset_patch(): void {
        if (!current_user_can('manage_options')) return;

        global $wp_scripts, $wp_styles;

        // Collect every handle WordPress has touched — queued, printed, or registered-and-done
        $all_script_handles = array_unique(array_merge(
            $wp_scripts->queue ?? [],
            array_keys($wp_scripts->done ?? [])
        ));
        $all_style_handles = array_unique(array_merge(
            $wp_styles->queue ?? [],
            array_keys($wp_styles->done ?? [])
        ));

        $extra_scripts = [];
        foreach ($all_script_handles as $handle) {
            $s = $wp_scripts->registered[$handle] ?? null;
            if (!$s || !$s->src) continue;
            $extra_scripts[$handle] = [
                'handle' => $handle,
                'src'    => $s->src,
                'deps'   => $s->deps,
                'ver'    => $s->ver,
            ];
        }

        $extra_styles = [];
        foreach ($all_style_handles as $handle) {
            $s = $wp_styles->registered[$handle] ?? null;
            if (!$s || !$s->src) continue;
            $extra_styles[$handle] = [
                'handle' => $handle,
                'src'    => $s->src,
                'deps'   => $s->deps,
                'ver'    => $s->ver,
            ];
        }

        // Merge into the already-output twperf config so the panel JS sees everything
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- encoded data, no user input
        echo '<script>if(window.twperf){' .
            'Object.assign(window.twperf.enqueued_scripts,' . wp_json_encode($extra_scripts) . ');' .
            'Object.assign(window.twperf.enqueued_styles,'  . wp_json_encode($extra_styles)  . ');' .
        '}</script>' . "\n";
    }

    public function maybe_inject_panel(): void {
        if (!current_user_can('manage_options')) return;
        include TWPERF_DIR . 'admin/views/panel.php';
    }
}
