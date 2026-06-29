<?php
defined('ABSPATH') || exit;

class TW_Perf_Profiler {

    private static ?self $instance = null;

    /** @var object|null ExcimerProfiler when the extension is loaded */
    private $profiler = null;

    private bool $active = false;
    private string $report_id = '';
    private float $started_at = 0.0;
    private string $start_error = '';

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action('admin_notices', [$this, 'render_notices']);
        add_action('admin_post_twperf_download_profile', [$this, 'handle_download']);
        add_action('admin_post_twperf_delete_profile', [$this, 'handle_delete']);

        if (current_user_can('manage_options') && !get_option('twperf_profiler_storage_migrated', false)) {
            if ($this->migrate_legacy_upload_reports()) {
                update_option('twperf_profiler_storage_migrated', true, false);
            }
        }

        if ($this->should_profile_request()) {
            $this->start();
        }
    }

    public static function is_available(): bool {
        return class_exists('ExcimerProfiler', false);
    }

    public static function is_enabled(): bool {
        return (bool) get_option('twperf_profiler_enabled', false);
    }

    public static function is_current_request_profiled(): bool {
        return self::instance()->active;
    }

    public static function get_period_ms(): int {
        $period = absint(get_option('twperf_profiler_period_ms', 5));
        return max(1, min(1000, $period));
    }

    public static function get_max_depth(): int {
        $depth = absint(get_option('twperf_profiler_max_depth', 80));
        return max(0, min(500, $depth));
    }

    public static function get_retention(): int {
        $retention = absint(get_option('twperf_profiler_retention', 20));
        return max(1, min(100, $retention));
    }

    public static function get_profile_url(string $url = ''): string {
        if (!$url) {
            $url = self::current_relative_url();
        }

        $url = remove_query_arg(['twperf_profile', '_wpnonce'], $url);
        $url = add_query_arg('twperf_profile', '1', $url);

        return add_query_arg('_wpnonce', wp_create_nonce('twperf_profile_admin_request'), $url);
    }

    public static function get_admin_page_url(array $args = []): string {
        return add_query_arg(
            array_merge(['page' => 'tw-performance-profiler'], $args),
            admin_url('admin.php')
        );
    }

    public static function get_reports(): array {
        $reports = get_option('twperf_profiler_reports', []);
        return is_array($reports) ? array_values($reports) : [];
    }

    public static function get_report(string $id): ?array {
        foreach (self::get_reports() as $report) {
            if (($report['id'] ?? '') === $id) {
                return $report;
            }
        }
        return null;
    }

    public static function get_download_url(array $report, string $format): string {
        $id = (string) ($report['id'] ?? '');

        return add_query_arg(
            [
                'action'   => 'twperf_download_profile',
                'id'       => $id,
                'format'   => $format,
                '_wpnonce' => wp_create_nonce('twperf_profile_download_' . $id),
            ],
            admin_url('admin-post.php')
        );
    }

    public static function get_delete_url(array $report): string {
        $id = (string) ($report['id'] ?? '');

        return add_query_arg(
            [
                'action'   => 'twperf_delete_profile',
                'id'       => $id,
                '_wpnonce' => wp_create_nonce('twperf_profile_delete_' . $id),
            ],
            admin_url('admin-post.php')
        );
    }

    public static function get_report_summary_data(array $report): array {
        return self::instance()->read_report_summary($report);
    }

    public function render_notices(): void {
        if (!current_user_can('manage_options')) return;

        if ($this->start_error) {
            echo '<div class="notice notice-warning"><p>' . esc_html($this->start_error) . '</p></div>';
        }

        if ($this->active) {
            echo '<div class="notice notice-info"><p>' .
                esc_html__('TW Perf profiler is recording this PHP request. The report will appear under TW Perf -> Profiler after the page finishes loading.', 'tw-performance') .
                '</p></div>';
            return;
        }

        $last_id = (string) get_user_meta(get_current_user_id(), 'twperf_last_profile_id', true);
        if (!$last_id) return;

        $report = self::get_report($last_id);
        delete_user_meta(get_current_user_id(), 'twperf_last_profile_id');

        if (!$report) return;

        $url = self::get_admin_page_url(['twperf_profile_id' => $last_id]);
        echo '<div class="notice notice-success is-dismissible"><p>' .
            esc_html__('TW Perf PHP profile saved.', 'tw-performance') . ' ' .
            '<a href="' . esc_url($url) . '">' . esc_html__('View report', 'tw-performance') . '</a>' .
            '</p></div>';
    }

    public function handle_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorised', 'tw-performance'), 403);
        }

        $id     = sanitize_key(wp_unslash($_GET['id'] ?? ''));
        $format = sanitize_key(wp_unslash($_GET['format'] ?? 'summary'));

        if (!$id || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'twperf_profile_download_' . $id)) {
            wp_die(esc_html__('Invalid profiler download request.', 'tw-performance'), 403);
        }

        $report = self::get_report($id);
        if (!$report) {
            wp_die(esc_html__('Profile report not found.', 'tw-performance'), 404);
        }

        $path = $this->get_report_file_path($report, $format);
        if (!$path || !is_readable($path)) {
            wp_die(esc_html__('Profile file not found.', 'tw-performance'), 404);
        }

        $content_type = 'text/plain; charset=utf-8';
        $extension    = 'txt';
        if (in_array($format, ['speedscope', 'summary'], true)) {
            $content_type = 'application/json; charset=utf-8';
            $extension    = 'json';
        }

        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="twperf-profile-' . $id . '-' . $format . '.' . $extension . '"');
        header('Content-Length: ' . (string) filesize($path));

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Admin-only download of plugin-owned report file.
        readfile($path);
        exit;
    }

    public function handle_delete(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorised', 'tw-performance'), 403);
        }

        $id = sanitize_key(wp_unslash($_GET['id'] ?? ''));
        if (!$id || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'twperf_profile_delete_' . $id)) {
            wp_die(esc_html__('Invalid profiler delete request.', 'tw-performance'), 403);
        }

        $this->delete_report($id);

        wp_safe_redirect(self::get_admin_page_url(['twperf_deleted' => '1']));
        exit;
    }

    private function should_profile_request(): bool {
        if (wp_doing_ajax() || wp_doing_cron()) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return false;
        if (defined('WP_CLI') && WP_CLI) return false;

        $requested = sanitize_key(wp_unslash($_GET['twperf_profile'] ?? ''));
        if ('1' !== $requested) return false;

        if (!current_user_can('manage_options')) {
            $this->start_error = __('TW Perf profiler requires manage_options capability.', 'tw-performance');
            return false;
        }

        if (!self::is_enabled()) {
            $this->start_error = __('TW Perf profiler is not enabled. Enable it under TW Perf -> Settings first.', 'tw-performance');
            return false;
        }

        if (!self::is_available()) {
            $this->start_error = __('Excimer is not available to PHP, so TW Perf cannot profile this request.', 'tw-performance');
            return false;
        }

        $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if (!$nonce || !wp_verify_nonce($nonce, 'twperf_profile_admin_request')) {
            $this->start_error = __('Invalid TW Perf profiler nonce.', 'tw-performance');
            return false;
        }

        return true;
    }

    private function start(): void {
        try {
            $this->report_id  = $this->make_report_id();
            $this->started_at = microtime(true);
            $this->profiler   = new ExcimerProfiler();

            $this->profiler->setPeriod(self::get_period_ms() / 1000);
            if (defined('EXCIMER_REAL')) {
                $this->profiler->setEventType(EXCIMER_REAL);
            }

            $max_depth = self::get_max_depth();
            if ($max_depth > 0) {
                $this->profiler->setMaxDepth($max_depth);
            }

            $this->profiler->start();
            $this->active = true;

            add_action('shutdown', [$this, 'finish'], PHP_INT_MAX);
        } catch (\Throwable $e) {
            $this->active      = false;
            $this->start_error = sprintf(
                /* translators: %s: Excimer error message */
                __('TW Perf profiler could not start: %s', 'tw-performance'),
                $e->getMessage()
            );
        }
    }

    public function finish(): void {
        if (!$this->active || !$this->profiler || !$this->report_id) return;

        $this->active = false;

        try {
            $this->profiler->stop();
            $log = $this->profiler->getLog();
        } catch (\Throwable $e) {
            return;
        }

        $dir = $this->ensure_storage_dir();
        if (!$dir) return;

        $event_count  = method_exists($log, 'getEventCount') ? (int) $log->getEventCount() : 0;
        $sample_count = method_exists($log, 'count') ? (int) $log->count() : $event_count;
        $duration_ms  = max(0, (microtime(true) - $this->started_at) * 1000);

        $meta = [
            'id'             => $this->report_id,
            'created_at'     => gmdate('Y-m-d H:i:s'),
            'url'            => remove_query_arg(['twperf_profile', '_wpnonce'], self::current_full_url()),
            'admin_label'    => $this->get_request_label(),
            'duration_ms'    => round($duration_ms, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            'sample_count'   => $sample_count,
            'event_count'    => $event_count,
            'period_ms'      => self::get_period_ms(),
            'max_depth'      => self::get_max_depth(),
            'php_version'    => PHP_VERSION,
            'wp_version'     => get_bloginfo('version'),
            'plugin_version' => TWPERF_VERSION,
            'files'          => [],
        ];

        $top_functions = $this->get_top_functions($log);
        $files         = [];

        if (method_exists($log, 'getSpeedscopeData')) {
            try {
                $speedscope = $log->getSpeedscopeData();
                if (is_array($speedscope)) {
                    $file = $this->report_id . '.speedscope.json';
                    if ($this->write_json_file(trailingslashit($dir) . $file, $speedscope)) {
                        $files['speedscope'] = $file;
                    }
                }
            } catch (\Throwable $e) {
                unset($e);
            }
        }

        if (method_exists($log, 'formatCollapsed')) {
            try {
                $file = $this->report_id . '.collapsed.txt';
                if ($this->write_text_file(trailingslashit($dir) . $file, (string) $log->formatCollapsed())) {
                    $files['collapsed'] = $file;
                }
            } catch (\Throwable $e) {
                unset($e);
            }
        }

        $files['summary'] = $this->report_id . '.summary.json';
        $meta['files']    = $files;

        $summary = [
            'meta'          => $meta,
            'top_functions' => $top_functions,
        ];
        if (!$this->write_json_file(trailingslashit($dir) . $files['summary'], $summary)) {
            return;
        }

        $this->store_report($meta);
        update_user_meta(get_current_user_id(), 'twperf_last_profile_id', $this->report_id);
    }

    private function get_top_functions(object $log): array {
        if (!method_exists($log, 'aggregateByFunction')) return [];

        try {
            $aggregate = $log->aggregateByFunction();
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($aggregate)) return [];

        uasort($aggregate, static function ($a, $b): int {
            return (int) ($b['inclusive'] ?? 0) <=> (int) ($a['inclusive'] ?? 0);
        });

        $top = [];
        foreach (array_slice($aggregate, 0, 50, true) as $name => $row) {
            if (!is_array($row)) continue;
            $top[] = [
                'name'      => (string) $name,
                'self'      => (int) ($row['self'] ?? 0),
                'inclusive' => (int) ($row['inclusive'] ?? 0),
                'file'      => isset($row['file']) ? (string) $row['file'] : '',
                'line'      => isset($row['line']) ? (int) $row['line'] : 0,
                'class'     => isset($row['class']) ? (string) $row['class'] : '',
                'function'  => isset($row['function']) ? (string) $row['function'] : '',
            ];
        }

        return $top;
    }

    private function store_report(array $meta): void {
        $reports = array_values(array_filter(
            self::get_reports(),
            static fn($report) => is_array($report) && ($report['id'] ?? '') !== ($meta['id'] ?? '')
        ));

        array_unshift($reports, $meta);

        $retention = self::get_retention();
        $kept      = array_slice($reports, 0, $retention);
        $removed   = array_slice($reports, $retention);

        foreach ($removed as $report) {
            if (is_array($report)) {
                $this->delete_report_files($report);
            }
        }

        update_option('twperf_profiler_reports', $kept, false);
    }

    private function delete_report(string $id): void {
        $kept = [];
        foreach (self::get_reports() as $report) {
            if (!is_array($report)) continue;
            if (($report['id'] ?? '') === $id) {
                $this->delete_report_files($report);
                continue;
            }
            $kept[] = $report;
        }

        update_option('twperf_profiler_reports', $kept, false);
    }

    private function delete_report_files(array $report): void {
        foreach ((array) ($report['files'] ?? []) as $file) {
            $path = $this->get_storage_file_path((string) $file);
            if ($path && is_file($path)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned report cleanup.
                @unlink($path);
            }

            $legacy_path = $this->get_legacy_upload_file_path((string) $file);
            if ($legacy_path && is_file($legacy_path)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Legacy plugin-owned report cleanup.
                @unlink($legacy_path);
            }
        }
    }

    private function get_report_file_path(array $report, string $format): string {
        $allowed = ['summary', 'speedscope', 'collapsed'];
        if (!in_array($format, $allowed, true)) return '';

        $file = (string) (($report['files'] ?? [])[$format] ?? '');
        return $this->get_storage_file_path($file);
    }

    private function get_storage_file_path(string $file): string {
        if (!$file || basename($file) !== $file) return '';

        $dir = $this->get_storage_dir();
        if (!$dir) return '';

        return trailingslashit($dir) . $file;
    }

    private function read_report_summary(array $report): array {
        $path = $this->get_report_file_path($report, 'summary');
        if (!$path || !is_readable($path)) return [];

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Admin-only read of plugin-owned report file.
        $json = file_get_contents($path);
        $data = json_decode((string) $json, true);

        return is_array($data) ? $data : [];
    }

    private function migrate_legacy_upload_reports(): bool {
        $reports = self::get_reports();
        if (!$reports) {
            $this->cleanup_legacy_upload_dir();
            return true;
        }

        $dir = $this->ensure_storage_dir();
        if (!$dir) {
            return false;
        }

        foreach ($reports as $report) {
            if (!is_array($report)) continue;

            foreach ((array) ($report['files'] ?? []) as $file) {
                $file = (string) $file;
                if (!$file || basename($file) !== $file) continue;

                $legacy_path = $this->get_legacy_upload_file_path($file);
                $private_path = trailingslashit($dir) . $file;
                if (!$legacy_path || !is_file($legacy_path)) continue;

                if (is_file($private_path)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing plugin-owned legacy public report file after private copy exists.
                    @unlink($legacy_path);
                    continue;
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Moving plugin-owned legacy report file to private storage.
                if (!@rename($legacy_path, $private_path)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Moving plugin-owned legacy report file to private storage.
                    if (@copy($legacy_path, $private_path)) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing plugin-owned legacy public report file after copy.
                        @unlink($legacy_path);
                    }
                }
            }
        }

        $this->cleanup_legacy_upload_dir();
        return true;
    }

    private function get_legacy_upload_file_path(string $file): string {
        if (!$file || basename($file) !== $file) return '';

        $dir = $this->get_legacy_upload_dir();
        if (!$dir) return '';

        return trailingslashit($dir) . $file;
    }

    private function cleanup_legacy_upload_dir(): void {
        $dir = $this->get_legacy_upload_dir();
        if (!$dir || !is_dir($dir)) return;

        foreach (['*.summary.json', '*.speedscope.json', '*.collapsed.txt'] as $pattern) {
            $files = glob(trailingslashit($dir) . $pattern);
            if (!is_array($files)) continue;

            foreach ($files as $file) {
                if (is_file($file)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing plugin-owned legacy public report files.
                    @unlink($file);
                }
            }
        }
    }

    private function get_legacy_upload_dir(): string {
        $upload = wp_upload_dir();
        if (!empty($upload['error']) || empty($upload['basedir'])) return '';

        return trailingslashit($upload['basedir']) . 'tw-performance/profiles';
    }

    private function ensure_storage_dir(): string {
        $dir = $this->get_storage_dir();
        if (!$dir) return '';

        $this->write_text_file(trailingslashit($dir) . 'index.html', '');
        $this->write_text_file(trailingslashit($dir) . '.htaccess', "Require all denied\nDeny from all\n");
        $this->write_text_file(trailingslashit($dir) . 'web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n");

        return $dir;
    }

    private function get_storage_dir(): string {
        $default = $this->get_default_storage_dir();
        $dir     = defined('TWPERF_PROFILER_DIR') ? (string) TWPERF_PROFILER_DIR : $default;

        /**
         * Filters the private directory used for profiler report files.
         *
         * This should resolve outside the public web root. Report files are only
         * served through nonce-protected admin-post.php download handlers.
         *
         * @param string $dir Private report storage directory.
         */
        $dir = (string) apply_filters('twperf_profiler_storage_dir', $dir);
        $dir = untrailingslashit($dir);

        if (!$dir || $this->is_public_web_path($dir)) return '';
        if (!wp_mkdir_p($dir)) return '';

        return $dir;
    }

    private function get_default_storage_dir(): string {
        $document_root = isset($_SERVER['DOCUMENT_ROOT'])
            ? untrailingslashit(wp_normalize_path(sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT']))))
            : '';
        $abspath = untrailingslashit(wp_normalize_path(ABSPATH));

        if ($document_root && str_starts_with(trailingslashit($abspath), trailingslashit($document_root))) {
            return trailingslashit(dirname($document_root)) . 'tw-performance-profiles';
        }

        return trailingslashit(dirname(untrailingslashit(ABSPATH))) . 'tw-performance-profiles';
    }

    private function is_public_web_path(string $dir): bool {
        $real_dir = realpath($dir);
        if (!$real_dir) {
            $real_dir = $dir;
        }

        $real_dir = wp_normalize_path($real_dir);

        $public_roots = array_filter([
            defined('ABSPATH') ? ABSPATH : '',
            defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '',
            isset($_SERVER['DOCUMENT_ROOT']) ? sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])) : '',
        ]);

        foreach ($public_roots as $root) {
            $real_root = realpath($root);
            if (!$real_root) {
                $real_root = $root;
            }

            $real_root = trailingslashit(wp_normalize_path($real_root));
            if (str_starts_with(trailingslashit($real_dir), $real_root)) {
                return true;
            }
        }

        return false;
    }

    private function write_json_file(string $path, array $data): bool {
        $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $json) return false;
        return $this->write_text_file($path, $json . "\n");
    }

    private function write_text_file(string $path, string $content): bool {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Plugin-owned private profile report files.
        return false !== @file_put_contents($path, $content);
    }

    private function make_report_id(): string {
        $random = strtolower(wp_generate_password(8, false, false));
        return sanitize_file_name(gmdate('Ymd-His') . '-' . $random);
    }

    private function get_request_label(): string {
        $pagenow = isset($GLOBALS['pagenow']) ? sanitize_file_name((string) $GLOBALS['pagenow']) : '';
        $hook    = isset($GLOBALS['hook_suffix']) ? sanitize_text_field((string) $GLOBALS['hook_suffix']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page slug used only in the saved profiler label.
        $page    = sanitize_key(wp_unslash($_GET['page'] ?? ''));

        if (is_admin()) {
            if ($pagenow && $page) return $pagenow . '?page=' . $page;
            if ($hook) return $hook;
            if ($pagenow) return $pagenow;

            return __('Admin PHP request', 'tw-performance');
        }

        $path = wp_parse_url(self::current_relative_url(), PHP_URL_PATH);
        $path = $path ? sanitize_text_field((string) $path) : '/';

        return sprintf(
            /* translators: %s: Frontend request path. */
            __('Frontend PHP: %s', 'tw-performance'),
            $path
        );
    }

    private static function current_relative_url(): string {
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        return $uri ?: admin_url();
    }

    private static function current_full_url(): string {
        $uri  = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
        if (!$host) return esc_url_raw($uri);

        return esc_url_raw((is_ssl() ? 'https://' : 'http://') . $host . $uri);
    }
}
