<?php
defined('ABSPATH') || exit;

class TW_Perf_Asset_Optimizer {

    private array $rules = [];

    public function init(): void {
        // Optimisation is frontend-only, including administrator previews.
        if (is_admin()) return;
        // Check test mode
        if (get_option('twperf_test_mode') && !$this->is_preview_active()) {
            return;
        }

        add_action('wp_enqueue_scripts',    [$this, 'apply_unloads'],       999);
        add_filter('script_loader_tag',     [$this, 'apply_script_attrs'],  10, 3);
        add_filter('style_loader_tag',      [$this, 'apply_async_css'],     10, 4);
        add_action('wp_footer',             [$this, 'inject_delay_loader'], 1);
    }

    // -------------------------------------------------------------------------
    // Unload scripts + styles based on rules
    // -------------------------------------------------------------------------
    public function apply_unloads(): void {
        if (is_admin()) return;
        $this->rules = TW_Perf_Rules::get_for_current_page('frontend', $this->is_preview_active());

        foreach ((array) ($this->rules['unload_js'] ?? []) as $handle) {
            if ($handle === 'twperf-delay-loader') continue;
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
    // Add defer / delay attributes to script tags
    // -------------------------------------------------------------------------
    public function apply_script_attrs(string $tag, string $handle, string $src): string {
        if (is_admin() || $handle === 'twperf-delay-loader') return $tag;
        if (!$this->rules) {
            $this->rules = TW_Perf_Rules::get_for_current_page('frontend', $this->is_preview_active());
        }

        // Keep the original tag intact, including type, nonce, SRI and data attributes.
        // Inline initializers and immediate consumers make postponing unsafe.
        if (in_array($handle, (array) ($this->rules['delay'] ?? []), true)) {
            if (!$this->can_postpone($handle, 'delay') || preg_match_all('/<script\b/i', $tag) !== 1) return $tag;
            return sprintf(
                '<template data-twperf-delay="1" data-twperf-src="%s" data-twperf-handle="%s" data-twperf-tag="%s"></template>' . "\n",
                esc_attr($src), esc_attr($handle), esc_attr(base64_encode($tag))
            );
        }

        // Defer — add defer attribute
        if (in_array($handle, (array) ($this->rules['defer'] ?? []))) {
            if (!$this->can_postpone($handle, 'defer')) return $tag;
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
        if (is_admin()) return $tag;
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
    // Delay loader — enqueue before WordPress prints footer scripts
    // -------------------------------------------------------------------------
    public function inject_delay_loader(): void {
        if (is_admin()) return;
        if (!$this->rules) {
            $this->rules = TW_Perf_Rules::get_for_current_page('frontend', $this->is_preview_active());
        }

        if (empty($this->rules['delay'])) return;

        $events = get_option('twperf_delay_events', ['scroll', 'touchstart', 'click']);
        if (!is_array($events) || empty($events)) {
            $events = ['scroll', 'touchstart', 'click'];
        }
        wp_enqueue_script('twperf-delay-loader', TWPERF_URL . 'assets/js/delay-loader.js', [], TWPERF_VERSION, true);
        wp_localize_script('twperf-delay-loader', 'twperfDelayConfig', ['events' => array_values($events)]);
    }

    private function can_postpone(string $handle, string $action, array $seen = []): bool {
        global $wp_scripts;
        if (isset($seen[$handle]) || !isset($wp_scripts->registered[$handle])) return false;
        $seen[$handle] = true;
        $script = $wp_scripts->registered[$handle];
        foreach (['before', 'after', 'data', 'conditional'] as $key) {
            if (!empty($script->extra[$key])) return false;
        }
        $allowed = (array) ($this->rules[$action] ?? []);
        if ($action === 'defer') $allowed = array_merge($allowed, (array) ($this->rules['delay'] ?? []));
        // Include queued dependency trees, without treating unrelated registrations as consumers.
        $pending = array_merge((array) $wp_scripts->queue, (array) $wp_scripts->to_do, (array) $wp_scripts->done);
        $active = [];
        while ($pending) {
            $name = array_pop($pending);
            if (isset($active[$name]) || !isset($wp_scripts->registered[$name])) continue;
            $active[$name] = true;
            $pending = array_merge($pending, (array) $wp_scripts->registered[$name]->deps);
        }
        foreach (array_keys($active) as $name) {
            if (!in_array($handle, (array) $wp_scripts->registered[$name]->deps, true)) continue;
            if (!in_array($name, $allowed, true) || !$this->can_postpone($name, $action, $seen)) return false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Test: is admin preview cookie set?
    // -------------------------------------------------------------------------
    private function is_preview_active(): bool {
        return isset($_COOKIE['twperf_preview'])
            && current_user_can('manage_options');
    }
}
