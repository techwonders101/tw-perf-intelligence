<?php
defined('ABSPATH') || exit;

$twperf_local_gfonts_status  = class_exists('TW_Perf_Google_Fonts_Local') ? TW_Perf_Google_Fonts_Local::get_status() : [
    'stylesheets' => 0,
    'fonts'       => 0,
    'font_names'  => [],
    'updated_at'  => 0,
    'cache_dir'   => '',
];
$twperf_local_gfonts_updated = !empty($twperf_local_gfonts_status['updated_at'])
    ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $twperf_local_gfonts_status['updated_at'])
    : __('Never', 'tw-performance');
?>
<div class="twperf-admin-page">

    <!-- Header -->
    <div class="twperf-admin-header">
        <img src="<?php echo esc_url(TWPERF_URL . 'assets/images/logo.jpg'); ?>" alt="TW Perf" class="twperf-admin-logo">
        <div class="twperf-admin-header__text">
            <h1>TW Perf Intelligence</h1>
            <p>Intelligent asset optimisation for WordPress</p>
        </div>
        <div class="twperf-admin-header__actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=tw-performance-profiler')); ?>" class="button">
                Profiler
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=tw-performance-rules')); ?>" class="button">
                All Rules
            </a>
        </div>
    </div>

    <form method="post" action="options.php">
        <?php settings_fields('twperf_settings'); ?>

        <!-- Workflow -->
        <div class="twperf-admin-card">
            <div class="twperf-admin-card__head">
                <span class="twperf-admin-card__head-icon">⚙️</span> Workflow
            </div>
            <div class="twperf-admin-card__body">

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Panel Loading
                        <small>Asset pre-load toggle</small>
                    </div>
                    <div class="twperf-setting-control">
                        <label class="twperf-toggle">
                            <input type="checkbox" name="twperf_always_load_panel" value="1"
                                <?php checked(get_option('twperf_always_load_panel', false)); ?>>
                            <span class="twperf-toggle__track"><span class="twperf-toggle__thumb"></span></span>
                            <span class="twperf-toggle__label">Always pre-load panel assets</span>
                        </label>
                        <span class="twperf-hint">Turn on while actively optimising, turn off when done. Panel assets only ever load for you — guests are never affected.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Test Mode
                        <small>Safe sandbox for changes</small>
                    </div>
                    <div class="twperf-setting-control">
                        <label class="twperf-toggle">
                            <input type="checkbox" name="twperf_test_mode" value="1"
                                <?php checked(get_option('twperf_test_mode', true)); ?>>
                            <span class="twperf-toggle__track"><span class="twperf-toggle__thumb"></span></span>
                            <span class="twperf-toggle__label">Only apply rules for admins with Preview Mode active</span>
                        </label>
                        <span class="twperf-hint">Recommended during setup. Disable once you're happy with results and ready to go live for all visitors.</span>
                    </div>
                </div>

            </div>
        </div>

        <!-- PHP Profiler -->
        <div class="twperf-admin-card">
            <div class="twperf-admin-card__head">
                <span class="twperf-admin-card__head-icon">⏱</span> PHP Profiler
                <span class="twperf-card-status <?php echo TW_Perf_Profiler::is_available() ? 'twperf-card-status--ok' : 'twperf-card-status--bad'; ?>">
                    <?php echo TW_Perf_Profiler::is_available() ? esc_html__('Excimer available', 'tw-performance') : esc_html__('Excimer unavailable', 'tw-performance'); ?>
                </span>
            </div>
            <div class="twperf-admin-card__body">

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Profiling
                        <small>Admin users only</small>
                    </div>
                    <div class="twperf-setting-control">
                        <label class="twperf-toggle">
                            <input type="checkbox" name="twperf_profiler_enabled" value="1"
                                <?php checked(get_option('twperf_profiler_enabled', false)); ?>>
                            <span class="twperf-toggle__track"><span class="twperf-toggle__thumb"></span></span>
                            <span class="twperf-toggle__label">Enable PHP request profiling when Excimer is loaded</span>
                        </label>
                        <span class="twperf-hint">When enabled, use the admin bar profiler item on wp-admin screens or frontend pages while logged in as an admin. Normal requests are not sampled.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Sample Period
                        <small>Lower is more detailed</small>
                    </div>
                    <div class="twperf-setting-control">
                        <input type="number" min="1" max="1000" step="1" name="twperf_profiler_period_ms"
                            value="<?php echo esc_attr(TW_Perf_Profiler::get_period_ms()); ?>"
                            class="twperf-input twperf-input--small">
                        <span class="twperf-hint">Milliseconds between samples. 5 ms is a useful default; 1–2 ms gives more detail but adds more profiling overhead.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Stack Depth
                        <small>Call stack limit</small>
                    </div>
                    <div class="twperf-setting-control">
                        <input type="number" min="0" max="500" step="1" name="twperf_profiler_max_depth"
                            value="<?php echo esc_attr(TW_Perf_Profiler::get_max_depth()); ?>"
                            class="twperf-input twperf-input--small">
                        <span class="twperf-hint">Maximum stack frames captured per sample. Use 0 for Excimer's unlimited depth.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Retention
                        <small>Stored reports</small>
                    </div>
                    <div class="twperf-setting-control">
                        <input type="number" min="1" max="100" step="1" name="twperf_profiler_retention"
                            value="<?php echo esc_attr(TW_Perf_Profiler::get_retention()); ?>"
                            class="twperf-input twperf-input--small">
                        <span class="twperf-hint">Older profile files are deleted automatically once this limit is exceeded.</span>
                        <div>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=tw-performance-profiler')); ?>" class="button">
                                View Profiler Reports
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Quick Wins -->
        <div class="twperf-admin-card">
            <div class="twperf-admin-card__head">
                <span class="twperf-admin-card__head-icon">⚡</span> Quick Wins
                <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#94a3b8;font-size:12px;margin-left:4px;">— safe to enable on any site</span>
            </div>
            <div class="twperf-admin-card__body">

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">Remove wp-emoji</div>
                    <div class="twperf-setting-control">
                        <label class="twperf-toggle">
                            <input type="checkbox" name="twperf_remove_emoji" value="1"
                                <?php checked(get_option('twperf_remove_emoji', true)); ?>>
                            <span class="twperf-toggle__track"><span class="twperf-toggle__thumb"></span></span>
                            <span class="twperf-toggle__label">Strip emoji detection script + stylesheet</span>
                        </label>
                        <span class="twperf-hint">Saves ~15 KiB. Modern browsers render emoji natively — WordPress's polyfill is not needed.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">Clean &lt;head&gt; Bloat</div>
                    <div class="twperf-setting-control">
                        <label class="twperf-toggle">
                            <input type="checkbox" name="twperf_clean_head" value="1"
                                <?php checked(get_option('twperf_clean_head', true)); ?>>
                            <span class="twperf-toggle__track"><span class="twperf-toggle__thumb"></span></span>
                            <span class="twperf-toggle__label">Remove generator tag, RSD, wlwmanifest, shortlink, REST &amp; oEmbed discovery</span>
                        </label>
                        <span class="twperf-hint">None of these are needed by visitors. Removes ~6 lines from every page's &lt;head&gt; and hides the WP version from bots.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">Heartbeat API</div>
                    <div class="twperf-setting-control">
                        <select name="twperf_heartbeat" class="twperf-select">
                            <option value="keep"        <?php selected(get_option('twperf_heartbeat', 'frontend'), 'keep'); ?>>Keep (default WP behaviour)</option>
                            <option value="frontend"    <?php selected(get_option('twperf_heartbeat', 'frontend'), 'frontend'); ?>>Disable on frontend only</option>
                            <option value="disable_all" <?php selected(get_option('twperf_heartbeat', 'frontend'), 'disable_all'); ?>>Disable everywhere</option>
                        </select>
                        <span class="twperf-hint">WP Heartbeat polls every 15–60s on public pages for no reason. Disabling on frontend saves continuous AJAX calls. Keep it in admin for autosave.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Delay Trigger Events
                        <small>For scripts set to Delay</small>
                    </div>
                    <div class="twperf-setting-control">
                        <?php
                        $events     = get_option('twperf_delay_events', ['scroll','touchstart','click']); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        if (!is_array($events)) $events = ['scroll','touchstart','click']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        $all_events = ['scroll','mousemove','touchstart','keydown','click','wheel']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        ?>
                        <div class="twperf-check-group">
                            <?php foreach ($all_events as $event) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
                            <label class="twperf-check-pill">
                                <input type="checkbox" name="twperf_delay_events[]"
                                    value="<?php echo esc_attr($event); ?>"
                                    <?php checked(in_array($event, $events)); ?>>
                                <?php echo esc_html($event); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <span class="twperf-hint">
                            Scripts set to <strong>Delay</strong> are held back entirely on page load — they only load when one of these events fires.
                            This is more aggressive than <em>Defer</em> (which still runs on load) and keeps analytics, chat widgets, and below-fold scripts
                            completely out of the way until the visitor actually engages.<br>
                            <strong>scroll + touchstart + click</strong> is the recommended default — covers almost every real visit without being too eager.
                            Avoid <em>mousemove</em> as it fires immediately on desktop, defeating the purpose.
                            There is also a 5-second automatic fallback so delayed scripts always load even if the visitor never interacts.
                        </span>
                    </div>
                </div>

            </div>
        </div>

        <!-- Performance Fixes -->
        <div class="twperf-admin-card">
            <div class="twperf-admin-card__head">
                <span class="twperf-admin-card__head-icon">🚀</span> Performance Fixes
            </div>
            <div class="twperf-admin-card__body">

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Fix font-display
                        <small>Output buffer patch</small>
                    </div>
                    <div class="twperf-setting-control">
                        <label class="twperf-toggle">
                            <input type="checkbox" name="twperf_fix_font_display" value="1"
                                <?php checked(get_option('twperf_fix_font_display', false)); ?>>
                            <span class="twperf-toggle__track"><span class="twperf-toggle__thumb"></span></span>
                            <span class="twperf-toggle__label">Enforce <code>font-display:swap</code> on all self-hosted fonts</span>
                        </label>
                        <span class="twperf-hint">Prevents invisible text during font load. Applies to inline &lt;style&gt; blocks and local stylesheet @font-face rules. Safe to enable.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Fix LCP Image Attrs
                        <small>Requires LCP URL saved first</small>
                    </div>
                    <div class="twperf-setting-control">
                        <label class="twperf-toggle">
                            <input type="checkbox" name="twperf_fix_lcp_attrs" value="1"
                                <?php checked(get_option('twperf_fix_lcp_attrs', false)); ?>>
                            <span class="twperf-toggle__track"><span class="twperf-toggle__thumb"></span></span>
                            <span class="twperf-toggle__label">Add <code>fetchpriority="high"</code>, remove <code>loading="lazy"</code> from LCP image</span>
                        </label>
                        <span class="twperf-hint">Save the LCP image URL via the front-end panel Preloads tab first, then enable this. Improves LCP score significantly.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        Serve Google Fonts Locally
                        <small>Template scanner</small>
                    </div>
                    <div class="twperf-setting-control">
                        <label class="twperf-toggle">
                            <input type="checkbox" name="twperf_local_gfonts_enabled" value="1"
                                <?php checked(get_option('twperf_local_gfonts_enabled', false)); ?>>
                            <span class="twperf-toggle__track"><span class="twperf-toggle__thumb"></span></span>
                            <span class="twperf-toggle__label">Rewrite cached Google Fonts stylesheets to local files</span>
                        </label>

                        <div class="twperf-local-gfonts-actions">
                            <button type="button" class="button" id="twperf-scan-local-gfonts">
                                Scan &amp; Cache Google Fonts
                            </button>
                            <button type="button" class="button button-link-delete" id="twperf-purge-local-gfonts">
                                Clear Cache
                            </button>
                            <span id="twperf-local-gfonts-result" class="twperf-inline-status"></span>
                        </div>

                        <div class="twperf-local-gfonts-status" id="twperf-local-gfonts-status">
                            <span><strong data-twperf-local-gfonts="stylesheets"><?php echo esc_html((string) $twperf_local_gfonts_status['stylesheets']); ?></strong> stylesheets</span>
                            <span><strong data-twperf-local-gfonts="fonts"><?php echo esc_html((string) $twperf_local_gfonts_status['fonts']); ?></strong> font files</span>
                            <span>Last scan: <strong data-twperf-local-gfonts="updated"><?php echo esc_html($twperf_local_gfonts_updated); ?></strong></span>
                        </div>

                        <div class="twperf-local-gfonts-names">
                            Fonts cached:
                            <strong data-twperf-local-gfonts="names">
                                <?php echo esc_html(!empty($twperf_local_gfonts_status['font_names']) ? implode(', ', (array) $twperf_local_gfonts_status['font_names']) : __('None yet', 'tw-performance')); ?>
                            </strong>
                        </div>

                        <?php if (!empty($twperf_local_gfonts_status['cache_dir'])) : ?>
                            <span class="twperf-hint">Cache directory: <code><?php echo esc_html($twperf_local_gfonts_status['cache_dir']); ?></code></span>
                        <?php endif; ?>

                        <span class="twperf-hint">
                            The scanner checks the homepage, a post archive/category, latest post, latest page, and WooCommerce product archive/product when available.
                            It downloads Google CSS and font files into uploads, then frontend pages replace matching <code>fonts.googleapis.com</code> URLs with the local cached stylesheet.
                            If the Remove Google Fonts quick win is also enabled, cached stylesheets are rewritten first and any uncached Google Fonts links are stripped.
                            Save this setting after enabling it; scan whenever theme or font settings change.
                        </span>
                    </div>
                </div>

            </div>
        </div>

        <!-- PSI -->
        <div class="twperf-admin-card">
            <div class="twperf-admin-card__head">
                <span class="twperf-admin-card__head-icon">📊</span> PageSpeed Insights
            </div>
            <div class="twperf-admin-card__body">

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">
                        API Key
                        <small>Optional but recommended</small>
                    </div>
                    <div class="twperf-setting-control">
                        <input type="text" name="twperf_psi_api_key"
                            value="<?php echo esc_attr(get_option('twperf_psi_api_key')); ?>"
                            class="twperf-input" placeholder="AIza…">
                        <span class="twperf-hint">Without a key, PSI requests are rate-limited. Get a free key from <a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank">Google Cloud Console</a>.</span>
                    </div>
                </div>

                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">Test Key</div>
                    <div class="twperf-setting-control">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <button type="button" class="button" id="twperf-test-psi-key">Test API Key</button>
                            <span id="twperf-psi-key-result"></span>
                        </div>
                        <span class="twperf-hint">Verifies the key works and checks your daily quota remaining.</span>
                    </div>
                </div>

            </div>
        </div>

        <!-- Export / Import -->
        <div class="twperf-admin-card">
            <div class="twperf-admin-card__head">
                <span class="twperf-admin-card__head-icon">📋</span> Rules
            </div>
            <div class="twperf-admin-card__body">
                <div class="twperf-setting-row">
                    <div class="twperf-setting-label">Manage Rules</div>
                    <div class="twperf-setting-control">
                        <div>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=tw-performance-rules')); ?>" class="button">
                                View All Rules
                            </a>
                        </div>
                        <span class="twperf-hint">Use the <strong>Tools tab</strong> in the front-end panel to export/import rules between sites. Open any page → TW Perf → Tools.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="twperf-admin-save">
            <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
        </div>

    </form>

    <!-- Guide -->
    <div class="twperf-guide">
        <div class="twperf-guide__head">Getting Started Guide</div>
        <div class="twperf-guide__body">

            <div class="twperf-guide-steps">

                <div class="twperf-guide-step">
                    <div class="twperf-guide-step__num twperf-guide-step__num--green">1</div>
                    <div class="twperf-guide-step__text">
                        <strong>Enable Quick Wins first</strong> — they're safe on every site with no risk of breakage. Turn on Remove wp-emoji, Clean &lt;head&gt;, and set Heartbeat to <em>Disable on frontend only</em>. These give instant gains in page weight and TTFB.
                    </div>
                </div>

                <div class="twperf-guide-step">
                    <div class="twperf-guide-step__num twperf-guide-step__num--green">2</div>
                    <div class="twperf-guide-step__text">
                        <strong>Preload your LCP image</strong> — visit your homepage (or the page you're optimising), open the panel via the admin bar, go to the <strong>This Page</strong> tab, and click <strong>Save Preload</strong> next to the detected LCP element. Then enable <em>Fix LCP Image Attrs</em> in settings.
                    </div>
                </div>

                <div class="twperf-guide-step">
                    <div class="twperf-guide-step__num twperf-guide-step__num--green">3</div>
                    <div class="twperf-guide-step__text">
                        <strong>Preload local fonts</strong> — if you self-host fonts (woff2 files in your theme), add them in the <strong>Preloads tab</strong> → Font Preloads. This eliminates render-blocking font requests and prevents invisible text (FOIT). Also enable <em>Fix font-display</em> in settings.
                    </div>
                </div>

                <div class="twperf-guide-step">
                    <div class="twperf-guide-step__num">4</div>
                    <div class="twperf-guide-step__text">
                        <strong>Analyse the page</strong> — click <strong>TW Perf → Analyse This Page</strong> in the admin bar. The panel scans all assets and gives a recommendation for each.
                    </div>
                </div>

                <div class="twperf-guide-step">
                    <div class="twperf-guide-step__num">5</div>
                    <div class="twperf-guide-step__text">
                        <strong>Use the recorder for tricky scripts</strong> — click <strong>Record</strong>, then interact with the page thoroughly: <em>scroll all the way to the bottom</em>, open menus, hover elements, click buttons, trigger any popups or sliders. The recorder watches which scripts respond to interactions, helping identify what's actually used vs. loaded for nothing. Stop recording to see results.
                    </div>
                </div>

                <div class="twperf-guide-step">
                    <div class="twperf-guide-step__num">6</div>
                    <div class="twperf-guide-step__text">
                        <strong>Apply recommendations</strong> — use <strong>Apply All Safe Recommendations</strong> or set each script individually. Save as <em>Preview Only</em> first to test, then click <strong>Go Live</strong> when happy. Use the PSI Score button before and after to measure impact.
                    </div>
                </div>

            </div>

            <h3 style="font-size:13px;font-weight:700;color:#334155;margin:0 0 12px;padding-top:16px;border-top:1px solid #f1f5f9;">
                Which action should I use?
            </h3>

            <table class="twperf-action-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>What it does</th>
                        <th>When to use</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="twperf-action-badge" style="background:#fef2f2;color:#ef4444;">Unload</span></td>
                        <td>Completely removes the asset on this page</td>
                        <td>Plugin clearly not used on this page (e.g. WooCommerce checkout scripts on homepage)</td>
                    </tr>
                    <tr>
                        <td><span class="twperf-action-badge" style="background:#fffbeb;color:#f59e0b;">Delay</span></td>
                        <td>Loads only after first user interaction (scroll, click, etc.)</td>
                        <td>Analytics, chat widgets, below-fold sliders — anything that doesn't need to run on page load</td>
                    </tr>
                    <tr>
                        <td><span class="twperf-action-badge" style="background:#eff6ff;color:#3b82f6;">Defer</span></td>
                        <td>Adds <code>defer</code> — loads after HTML is parsed, before DOMContentLoaded</td>
                        <td><strong>Default safe choice for JS.</strong> If you're unsure whether to unload or delay, defer is the safest bet — it still runs but doesn't block rendering.</td>
                    </tr>
                    <tr>
                        <td><span class="twperf-action-badge" style="background:#f5f3ff;color:#a78bfa;">Async CSS</span></td>
                        <td>Converts blocking stylesheet to non-render-blocking preload</td>
                        <td><strong>Default safe choice for CSS.</strong> If you're unsure whether to unload a stylesheet, async CSS is the safe middle ground — it still loads but doesn't block the page render.</td>
                    </tr>
                    <tr>
                        <td><span class="twperf-action-badge" style="background:#f0fdf4;color:#22c55e;">Preload</span></td>
                        <td>Adds <code>&lt;link rel="preload"&gt;</code> — fetches the resource early</td>
                        <td>Critical above-fold CSS, web fonts, LCP image</td>
                    </tr>
                    <tr>
                        <td><span class="twperf-action-badge" style="background:#f8fafc;color:#64748b;">Keep</span></td>
                        <td>No change — loads as normal</td>
                        <td>Required above-fold assets (jQuery if used above fold, critical CSS)</td>
                    </tr>
                </tbody>
            </table>

        </div>
    </div>

</div>
