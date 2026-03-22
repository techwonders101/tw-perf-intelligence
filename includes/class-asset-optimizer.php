<?php
defined('ABSPATH') || exit;

class TW_Perf_Asset_Optimizer {

    private array $rules = [];

    public function init(): void {
        // Check test mode
        if (get_option('twperf_test_mode') && !$this->is_preview_active()) {
            return;
        }

        add_action('wp_enqueue_scripts',    [$this, 'apply_unloads'],       999);
        add_action('admin_enqueue_scripts', [$this, 'apply_admin_unloads'], 999);
        add_filter('script_loader_tag',     [$this, 'apply_script_attrs'],  10, 3);
        add_filter('style_loader_tag',      [$this, 'apply_async_css'],     10, 4);
        add_action('wp_footer',             [$this, 'inject_delay_loader'], 1);
    }

    // -------------------------------------------------------------------------
    // Unload scripts + styles based on rules
    // -------------------------------------------------------------------------
    public function apply_unloads(): void {
        $this->rules = TW_Perf_Rules::get_for_current_page('frontend', $this->is_preview_active());

        foreach ((array) ($this->rules['unload_js'] ?? []) as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }

        // Never remove admin-bar dependencies when the toolbar is visible
        $admin_bar_protected = is_admin_bar_showing() ? ['dashicons', 'admin-bar'] : [];

        foreach ((array) ($this->rules['unload_css'] ?? []) as $handle) {
            if (in_array($handle, $admin_bar_protected, true)) continue;
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }

    // -------------------------------------------------------------------------
    // Unload admin scripts + styles (context = admin or both)
    // -------------------------------------------------------------------------
    public function apply_admin_unloads(): void {
        $rules = TW_Perf_Rules::get_for_current_page('admin');

        foreach ((array) ($rules['unload_js'] ?? []) as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }

        foreach ((array) ($rules['unload_css'] ?? []) as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }

    // -------------------------------------------------------------------------
    // Add defer / delay attributes to script tags
    // -------------------------------------------------------------------------
    public function apply_script_attrs(string $tag, string $handle, string $src): string {
        if (!$this->rules) {
            $this->rules = TW_Perf_Rules::get_for_current_page('frontend', $this->is_preview_active());
        }

        // Delay — strip the tag, replace with data- version loaded on interaction
        if (in_array($handle, (array) ($this->rules['delay'] ?? []))) {
            // Store src for the delay loader (data-attribute placeholder, not an enqueued script)
            // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- data-attribute placeholder; actual loading done by delay-loader.js via fetch, not wp_enqueue_script
            return sprintf(
                '<script type="text/javascript" data-twperf-delay="1" data-twperf-src="%s" data-twperf-handle="%s"></script>' . "\n",
                esc_attr($src),
                esc_attr($handle)
            );
            // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
        }

        // Defer — add defer attribute
        if (in_array($handle, (array) ($this->rules['defer'] ?? []))) {
            if (strpos($tag, 'defer') === false) {
                $tag = str_replace(' src=', ' defer src=', $tag);
            }
            return $tag;
        }

        return $tag;
    }

    // -------------------------------------------------------------------------
    // Async CSS — convert blocking stylesheet to preload + noscript fallback
    // -------------------------------------------------------------------------
    public function apply_async_css(string $tag, string $handle, string $href, string $media): string {
        if (!$this->rules) {
            $this->rules = TW_Perf_Rules::get_for_current_page('frontend', $this->is_preview_active());
        }

        if (!in_array($handle, (array) ($this->rules['async_css'] ?? []))) {
            return $tag;
        }

        return sprintf(
            '<link rel="preload" id="%s-css" href="%s" as="style" media="%s" onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n"
            . '<noscript>%s</noscript>' . "\n",
            esc_attr($handle),
            esc_attr($href),
            esc_attr($media ?: 'all'),
            $tag
        );
    }

    // -------------------------------------------------------------------------
    // Delay loader — tiny vanilla JS, injected once at footer start
    // -------------------------------------------------------------------------
    public function inject_delay_loader(): void {
        if (!$this->rules) {
            $this->rules = TW_Perf_Rules::get_for_current_page('frontend', $this->is_preview_active());
        }

        if (empty($this->rules['delay'])) return;

        $events = get_option('twperf_delay_events', ['scroll', 'touchstart', 'click']);
        if (!is_array($events) || empty($events)) {
            $events = ['scroll', 'touchstart', 'click'];
        }
        $events_json = wp_json_encode(array_values($events));

        echo '<script id="twperf-delay-loader">' . "\n"
            . '(function(){' . "\n"
            . '    var loaded = false;' . "\n"
            . '    function loadDelayed(){' . "\n"
            . '        if(loaded) return;' . "\n"
            . '        loaded = true;' . "\n"
            . '        var scripts = document.querySelectorAll(\'script[data-twperf-delay]\');' . "\n"
            . '        scripts.forEach(function(placeholder){' . "\n"
            . '            var s = document.createElement(\'script\');' . "\n"
            . '            s.src = placeholder.getAttribute(\'data-twperf-src\');' . "\n"
            . '            s.async = true;' . "\n"
            . '            placeholder.parentNode.insertBefore(s, placeholder.nextSibling);' . "\n"
            . '        });' . "\n"
            . '        events.forEach(function(e){ window.removeEventListener(e, loadDelayed, {passive:true}); });' . "\n"
            . '    }' . "\n"
            . '    var events = ' . $events_json . ';' . "\n"  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode produces safe JSON
            . '    events.forEach(function(e){ window.addEventListener(e, loadDelayed, {passive:true}); });' . "\n"
            . '    setTimeout(loadDelayed, 5000);' . "\n"
            . '})();' . "\n"
            . '</script>' . "\n";
    }

    // -------------------------------------------------------------------------
    // Test: is admin preview cookie set?
    // -------------------------------------------------------------------------
    private function is_preview_active(): bool {
        return isset($_COOKIE['twperf_preview'])
            && current_user_can('manage_options');
    }
}
