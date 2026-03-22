<?php
defined('ABSPATH') || exit;

/**
 * TW_Perf_Updater
 *
 * Checks GitHub releases for new versions and plugs into WordPress's
 * native update system. Users see the update in Dashboard → Updates
 * and can install it with one click, same as any wp.org plugin.
 */
class TW_Perf_Updater {

    private const GITHUB_REPO = 'techwonders101/tw-perf-intelligence';
    private const CACHE_KEY   = 'twperf_update_check';
    private const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

    public function init(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api',                           [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection',             [$this, 'fix_source_dir'], 10, 4);
    }

    // -------------------------------------------------------------------------
    // Inject update data into WordPress's update transient
    // -------------------------------------------------------------------------
    public function check_for_update(object $transient): object {
        if (empty($transient->checked)) return $transient;

        $release = $this->get_latest_release();
        if (!$release) return $transient;

        $latest  = ltrim($release->tag_name, 'v');
        $current = TWPERF_VERSION;

        if (version_compare($latest, $current, '>')) {
            $zip_url = $this->get_zip_url($release);
            if ($zip_url) {
                $transient->response[TWPERF_BASENAME] = (object) [
                    'id'            => TWPERF_BASENAME,
                    'slug'          => 'tw-performance',
                    'plugin'        => TWPERF_BASENAME,
                    'new_version'   => $latest,
                    'url'           => 'https://github.com/' . self::GITHUB_REPO,
                    'package'       => $zip_url,
                    'icons'         => [],
                    'banners'       => [],
                    'requires'      => '6.0',
                    'tested'        => '6.8',
                    'requires_php'  => '8.1',
                ];
            }
        } else {
            // Tell WP this plugin is up to date so it doesn't show stale notices
            $transient->no_update[TWPERF_BASENAME] = (object) [
                'id'          => TWPERF_BASENAME,
                'slug'        => 'tw-performance',
                'plugin'      => TWPERF_BASENAME,
                'new_version' => $current,
                'url'         => 'https://github.com/' . self::GITHUB_REPO,
            ];
        }

        return $transient;
    }

    // -------------------------------------------------------------------------
    // Populate the "View details" plugin info popup
    // -------------------------------------------------------------------------
    public function plugin_info(mixed $result, string $action, object $args): mixed {
        if ($action !== 'plugin_information') return $result;
        if (($args->slug ?? '') !== 'tw-performance') return $result;

        $release = $this->get_latest_release();
        if (!$release) return $result;

        $latest  = ltrim($release->tag_name, 'v');
        $zip_url = $this->get_zip_url($release);

        return (object) [
            'name'          => 'TW Perf Intelligence',
            'slug'          => 'tw-performance',
            'version'       => $latest,
            'author'        => '<a href="https://techwonders.co.uk">TechWonders</a>',
            'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
            'requires'      => '6.0',
            'tested'        => '6.8',
            'requires_php'  => '8.1',
            'download_link' => $zip_url,
            'sections'      => [
                'description' => 'Intelligent asset optimisation — defer, delay, unload JS/CSS per page with DOM-aware recommendations.',
                'changelog'   => $this->format_changelog($release->body ?? ''),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Rename the extracted folder to tw-performance so WP replaces correctly
    // GitHub zips extract as tw-performance-x.y.z/ but the release zip we build
    // already has the right name — this is a safety net for the edge case.
    // -------------------------------------------------------------------------
    public function fix_source_dir(string $source, string $remote_source, object $upgrader, array $hook_extra): string {
        $plugin = $hook_extra['plugin'] ?? '';
        if ($plugin !== TWPERF_BASENAME) return $source;

        $correct = trailingslashit(dirname($source)) . 'tw-performance/';
        if ($source !== $correct && is_dir($source)) {
            rename($source, $correct); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            return $correct;
        }

        return $source;
    }

    // -------------------------------------------------------------------------
    // Fetch latest release from GitHub API (cached for 12 hours)
    // -------------------------------------------------------------------------
    private function get_latest_release(): ?object {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) return $cached ?: null;

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
            [
                'timeout'    => 10,
                'user-agent' => 'TW-Perf-Updater/' . TWPERF_VERSION . '; ' . home_url(),
                'headers'    => ['Accept' => 'application/vnd.github+json'],
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE_KEY, '', HOUR_IN_SECONDS); // back off on failure
            return null;
        }

        $release = json_decode(wp_remote_retrieve_body($response));
        if (empty($release->tag_name)) {
            set_transient(self::CACHE_KEY, '', HOUR_IN_SECONDS);
            return null;
        }

        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    // -------------------------------------------------------------------------
    // Find the tw-performance.zip asset in the release
    // -------------------------------------------------------------------------
    private function get_zip_url(object $release): ?string {
        foreach ($release->assets ?? [] as $asset) {
            if ($asset->name === 'tw-performance.zip') {
                return $asset->browser_download_url;
            }
        }
        // Fallback to GitHub's auto-generated source zip (folder name will be fixed by fix_source_dir)
        return $release->zipball_url ?? null;
    }

    // -------------------------------------------------------------------------
    // Convert markdown release notes to basic HTML for the WP popup
    // -------------------------------------------------------------------------
    private function format_changelog(string $markdown): string {
        if (!$markdown) return '<p>See <a href="https://github.com/' . self::GITHUB_REPO . '/releases">GitHub releases</a> for changelog.</p>';

        $html = esc_html($markdown);
        $html = preg_replace('/^#{1,3}\s+(.+)$/m', '<strong>$1</strong>', $html);
        $html = preg_replace('/^[-*]\s+(.+)$/m', '<li>$1</li>', $html);
        $html = '<ul>' . $html . '</ul>';
        return $html;
    }
}
