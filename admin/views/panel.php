<?php defined('ABSPATH') || exit; ?>
<div id="twperf-panel" class="twperf-panel" style="display:none;">
    <div class="twperf-panel__backdrop"></div>
    <div class="twperf-panel__drawer">

        <div class="twperf-panel__head">
            <div class="twperf-panel__logo">
                <img src="<?php echo esc_url(TWPERF_URL . 'assets/images/logo.jpg'); ?>" alt="TW Perf Intelligence" class="twperf-panel__logo-img" style="height:56px!important;width:auto!important;max-width:240px!important;">
                <div class="twperf-panel__logo-meta">
                    <span class="twperf-panel__url"><?php echo esc_html(wp_parse_url(get_permalink() ?: home_url(isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/'), PHP_URL_PATH) ?: '/'); ?></span>
                    <span class="twperf-panel__context" id="twperf-page-context"></span>
                </div>
            </div>
            <div class="twperf-panel__head-actions">
                <button class="twperf-btn twperf-btn--ghost" id="twperf-purge-cache" title="Purge page cache">Purge Cache</button>
                <?php if ( ! is_admin() ) : ?>
                <span class="twperf-psi-wrap">
                    <button class="twperf-btn twperf-btn--ghost" id="twperf-run-psi" title="Run PageSpeed Insights">PSI Score</button><button class="twperf-psi-strategy" id="twperf-psi-strategy" title="Toggle mobile/desktop strategy">📱</button>
                </span>
                <button class="twperf-btn twperf-btn--record" id="twperf-record-btn" title="Record page interactions to detect which scripts are actually active">Record</button>
                <button class="twperf-btn twperf-btn--primary" id="twperf-scan-btn">Analyse Page</button>
                <?php else : ?>
                <span class="twperf-admin-context-notice">Frontend analysis not available in wp-admin</span>
                <?php endif; ?>
                <button class="twperf-panel__close" id="twperf-close">✕</button>
            </div>
        </div>

        <div class="twperf-panel__toolbar">
            <div class="twperf-panel__tabs">
                <button class="twperf-tab twperf-tab--active" data-tab="assets">Assets</button>
                <button class="twperf-tab" data-tab="rules">This Page</button>
                <button class="twperf-tab" data-tab="all-rules">All Rules</button>
                <button class="twperf-tab" data-tab="preloads">Preloads</button>
                <button class="twperf-tab" data-tab="tools">Tools</button>
                <button class="twperf-tab" data-tab="psi">PSI Report</button>
            </div>
            <div class="twperf-panel__scope">
                <label>Scope:</label>
                <select id="twperf-scope">
                    <option value="page">This page only</option>
                    <option value="post_type">All pages of this type</option>
                    <option value="global">Global (all pages)</option>
                </select>
            </div>
            <div class="twperf-panel__preview-toggle">
                <label class="twperf-switch">
                    <input type="checkbox" id="twperf-preview-toggle" <?php checked(isset($_COOKIE['twperf_preview'])); ?>>
                    <span class="twperf-switch__slider"></span>
                </label>
                <span>Preview mode</span>
            </div>
        </div>

        <div class="twperf-panel__summary" id="twperf-summary" style="display:none;">
            <div class="twperf-summary-pill twperf-summary-pill--red"   id="s-unload">0 unload</div>
            <div class="twperf-summary-pill twperf-summary-pill--amber" id="s-delay">0 delay</div>
            <div class="twperf-summary-pill twperf-summary-pill--blue"  id="s-defer">0 defer</div>
            <div class="twperf-summary-pill twperf-summary-pill--orange" id="s-investigate">0 review</div>
            <div class="twperf-summary-pill twperf-summary-pill--green" id="s-keep">0 keep</div>
            <div class="twperf-panel__bulk-actions">
                <button class="twperf-btn twperf-btn--sm twperf-btn--primary" id="twperf-apply-all">
                    ✓ Apply All Safe Recommendations
                </button>
            </div>
        </div>

        <!-- Filter bar — always visible, shown only for Assets tab (hidden in wp-admin) -->
        <div class="twperf-panel__filters" id="twperf-filter-bar"<?php if ( is_admin() ) echo ' style="display:none;"'; ?>>
            <div class="twperf-filter-pills">
                <button class="twperf-filter twperf-filter--active" data-filter="all">All</button>
                <button class="twperf-filter" data-filter="unload">Unload</button>
                <button class="twperf-filter" data-filter="delay">Delay</button>
                <button class="twperf-filter" data-filter="defer">Defer</button>
                <button class="twperf-filter" data-filter="investigate">Review</button>
                <button class="twperf-filter" data-filter="manual">Manual</button>

                <button class="twperf-filter" data-filter="script">JS only</button>
                <button class="twperf-filter" data-filter="style">CSS only</button>
                <button class="twperf-filter" data-filter="third_party" title="Show only scripts/styles loaded from external origins">3rd party</button>
                <button class="twperf-filter twperf-filter--toggle" id="twperf-hide-wpcore" title="Hide WP core scripts (wp-includes, React, i18n…)">Hide WP core</button>
            </div>
            <div class="twperf-filter-search">
                <input type="text" id="twperf-search" placeholder="Search handles…">
            </div>
        </div>

        <div class="twperf-panel__body">

            <!-- ASSETS TAB -->
            <div class="twperf-tab-pane twperf-tab-pane--active" data-pane="assets">
                <div id="twperf-page-audit" style="display:none;"></div>
                <div id="twperf-asset-list" class="twperf-asset-list">
                    <div class="twperf-empty">
                        Click <strong>Analyse Page</strong> to scan assets
                    </div>
                </div>
            </div>

            <!-- THIS PAGE RULES TAB -->
            <div class="twperf-tab-pane" data-pane="rules">
                <div id="twperf-rules-list" class="twperf-rules-list">
                    <div class="twperf-empty">Loading saved rules…</div>
                </div>
            </div>

            <!-- ALL RULES TAB -->
            <div class="twperf-tab-pane" data-pane="all-rules">
                <div class="twperf-all-rules-toolbar">
                    <span id="twperf-all-rules-count" style="font-size:12px;color:var(--twp-muted);padding:12px 20px;display:block;"></span>
                </div>
                <div id="twperf-all-rules-list" class="twperf-rules-list">
                    <div class="twperf-empty">Loading all rules…</div>
                </div>
            </div>

            <!-- PRELOADS TAB -->
            <div class="twperf-tab-pane" data-pane="preloads">
                <div style="padding:20px;display:flex;flex-direction:column;gap:24px;">

                    <!-- Third-party suggestions (populated by JS after analysis) -->
                    <div id="twperf-3p-suggestions" style="display:none;"></div>

                    <!-- LCP Image -->
                    <div class="twperf-tool-card">
                        <div class="twperf-tool-card__head">
                            <span class="twperf-tool-card__icon">🖼</span>
                            <div>
                                <div class="twperf-tool-card__title">LCP Image Preload</div>
                                <div class="twperf-tool-card__desc">Biggest single LCP win — preloads the hero image at highest priority</div>
                            </div>
                        </div>
                        <div id="twperf-lcp-detected" style="display:none;padding:4px 0;"></div>
                        <div class="twperf-field-group" style="margin-top:8px;">
                            <label>LCP Image URL <small style="font-weight:400;color:var(--twp-muted)">(auto-detected on Analyse)</small></label>
                            <div class="twperf-input-row">
                                <input type="url" id="twperf-lcp-url" placeholder="https://…/hero-image.webp">
                                <button class="twperf-btn twperf-btn--primary" id="twperf-save-lcp">Save</button>
                            </div>
                        </div>
                    </div>

                    <!-- Font Preloads -->
                    <div class="twperf-tool-card">
                        <div class="twperf-tool-card__head">
                            <span class="twperf-tool-card__icon">🔤</span>
                            <div>
                                <div class="twperf-tool-card__title">Font Preloads</div>
                                <div class="twperf-tool-card__desc">Preload self-hosted woff2 fonts to eliminate render-blocking font fetches</div>
                            </div>
                        </div>
                        <div class="twperf-field-group" style="margin-top:8px;">
                            <label>Font URL (.woff2)</label>
                            <div class="twperf-input-row">
                                <input type="url" id="twperf-font-url" placeholder="https://…/font.woff2">
                                <label class="twperf-switch twperf-switch--sm" title="Required for fonts — even same-origin fonts need crossorigin">
                                    <input type="checkbox" id="twperf-font-crossorigin" checked>
                                    <span class="twperf-switch__slider"></span>
                                </label>
                                <span style="font-size:11px;color:var(--twp-muted);white-space:nowrap;">crossorigin</span>
                                <button class="twperf-btn twperf-btn--primary" id="twperf-save-font">Add</button>
                            </div>
                            <small>Always enable crossorigin for fonts — browsers require it for CORS font requests.</small>
                        </div>
                        <div id="twperf-font-preload-list" class="twperf-preload-list"></div>
                    </div>

                    <!-- Preconnect -->
                    <div class="twperf-tool-card">
                        <div class="twperf-tool-card__head">
                            <span class="twperf-tool-card__icon">🔗</span>
                            <div>
                                <div class="twperf-tool-card__title">Preconnect Origins</div>
                                <div class="twperf-tool-card__desc">For <strong>external</strong> domains only — saves DNS + TCP + TLS time before the first request</div>
                            </div>
                        </div>
                        <div class="twperf-field-group" style="margin-top:8px;">
                            <label>Origin URL</label>
                            <div class="twperf-input-row">
                                <input type="url" id="twperf-preconnect-url" placeholder="https://fonts.gstatic.com">
                                <label class="twperf-switch twperf-switch--sm" title="Enable crossorigin for font CDNs and APIs">
                                    <input type="checkbox" id="twperf-preconnect-crossorigin">
                                    <span class="twperf-switch__slider"></span>
                                </label>
                                <span style="font-size:11px;color:var(--twp-muted);white-space:nowrap;">crossorigin</span>
                                <button class="twperf-btn twperf-btn--primary" id="twperf-save-preconnect">Add</button>
                            </div>
                            <small>Only for external origins (Google Fonts, CDNs, analytics). Not useful for same-domain assets.</small>
                        </div>
                        <div id="twperf-preconnect-list" class="twperf-preload-list"></div>
                    </div>

                </div>
            </div>

            <!-- TOOLS TAB -->
            <div class="twperf-tab-pane" data-pane="tools">
                <div style="padding:20px;display:flex;flex-direction:column;gap:20px;">

                    <!-- Quick Wins / WP Bloat -->
                    <div class="twperf-tool-card">
                        <div class="twperf-tool-card__head">
                            <span class="twperf-tool-card__icon">&#9889;</span>
                            <div>
                                <div class="twperf-tool-card__title">WP Quick Wins</div>
                                <div class="twperf-tool-card__desc">Zero-config server-side fixes — no page rules needed</div>
                            </div>
                        </div>
                        <div class="twperf-quick-wins" id="twperf-quick-wins">
                            <label class="twperf-qw-row">
                                <div class="twperf-qw-info">
                                    <strong>Remove wp-emoji</strong>
                                    <span>Strips emoji detection script + DNS prefetch (~15 KiB)</span>
                                </div>
                                <label class="twperf-switch twperf-switch--sm">
                                    <input type="checkbox" class="twperf-qw-toggle" data-key="twperf_remove_emoji" data-type="boolean">
                                    <span class="twperf-switch__slider"></span>
                                </label>
                            </label>
                            <label class="twperf-qw-row">
                                <div class="twperf-qw-info">
                                    <strong>Clean &lt;head&gt; bloat</strong>
                                    <span>Removes generator, RSD, wlwmanifest, REST discovery links</span>
                                </div>
                                <label class="twperf-switch twperf-switch--sm">
                                    <input type="checkbox" class="twperf-qw-toggle" data-key="twperf_clean_head" data-type="boolean">
                                    <span class="twperf-switch__slider"></span>
                                </label>
                            </label>
                            <label class="twperf-qw-row">
                                <div class="twperf-qw-info">
                                    <strong>Fix font-display: swap</strong>
                                    <span>Enforces swap on all @font-face blocks via output buffer</span>
                                </div>
                                <label class="twperf-switch twperf-switch--sm">
                                    <input type="checkbox" class="twperf-qw-toggle" data-key="twperf_fix_font_display" data-type="boolean">
                                    <span class="twperf-switch__slider"></span>
                                </label>
                            </label>
                            <label class="twperf-qw-row">
                                <div class="twperf-qw-info">
                                    <strong>Fix LCP image attributes</strong>
                                    <span>Adds fetchpriority="high", removes loading="lazy" on saved LCP image</span>
                                </div>
                                <label class="twperf-switch twperf-switch--sm">
                                    <input type="checkbox" class="twperf-qw-toggle" data-key="twperf_fix_lcp_attrs" data-type="boolean">
                                    <span class="twperf-switch__slider"></span>
                                </label>
                            </label>
                            <label class="twperf-qw-row">
                                <div class="twperf-qw-info">
                                    <strong>Remove Google Fonts</strong>
                                    <span>Strips all fonts.googleapis.com &lt;link&gt; tags — use when fonts aren't needed or are self-hosted</span>
                                </div>
                                <label class="twperf-switch twperf-switch--sm">
                                    <input type="checkbox" class="twperf-qw-toggle" data-key="twperf_remove_gfonts" data-type="boolean">
                                    <span class="twperf-switch__slider"></span>
                                </label>
                            </label>
                            <div class="twperf-qw-row twperf-qw-row--select">
                                <div class="twperf-qw-info">
                                    <strong>Heartbeat API</strong>
                                    <span>WP polls every 15–60 s on frontend pages unnecessarily</span>
                                </div>
                                <select class="twperf-qw-select" data-key="twperf_heartbeat">
                                    <option value="keep">Keep (WP default)</option>
                                    <option value="frontend">Disable on frontend</option>
                                    <option value="disable_all">Disable everywhere</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Critical CSS -->
                    <div class="twperf-tool-card">
                        <div class="twperf-tool-card__head">
                            <span class="twperf-tool-card__icon">&#128196;</span>
                            <div>
                                <div class="twperf-tool-card__title">Critical CSS</div>
                                <div class="twperf-tool-card__desc">Injected inline at the top of &lt;head&gt; — paste above-fold styles here to eliminate render-blocking CSS</div>
                            </div>
                            <button class="twperf-btn twperf-btn--primary twperf-btn--sm" id="twperf-save-critical-css">Save</button>
                        </div>
                        <textarea id="twperf-critical-css-input" class="twperf-tool-textarea" placeholder="/* paste critical above-fold CSS here */
.hero { font-size: 58px; }
@font-face { font-family: Merriweather; font-display: swap; … }"></textarea>
                    </div>

                    <!-- Conflict checker -->
                    <div class="twperf-tool-card">
                        <div class="twperf-tool-card__head">
                            <span class="twperf-tool-card__icon">&#9881;</span>
                            <div>
                                <div class="twperf-tool-card__title">Conflict Checker</div>
                                <div class="twperf-tool-card__desc">Detect rules that contradict each other or would break dependencies</div>
                            </div>
                            <button class="twperf-btn twperf-btn--ghost twperf-btn--sm" id="twperf-run-conflicts">Check</button>
                        </div>
                        <div id="twperf-conflicts-result"></div>
                    </div>

                    <!-- Export -->
                    <div class="twperf-tool-card">
                        <div class="twperf-tool-card__head">
                            <span class="twperf-tool-card__icon">📤</span>
                            <div>
                                <div class="twperf-tool-card__title">Export Rules</div>
                                <div class="twperf-tool-card__desc">Download all rules as JSON — copy to another site</div>
                            </div>
                            <button class="twperf-btn twperf-btn--ghost twperf-btn--sm" id="twperf-export-btn">Export JSON</button>
                        </div>
                        <textarea id="twperf-export-output" class="twperf-tool-textarea" placeholder="Export will appear here…" readonly></textarea>
                    </div>

                    <!-- Import -->
                    <div class="twperf-tool-card">
                        <div class="twperf-tool-card__head">
                            <span class="twperf-tool-card__icon">📥</span>
                            <div>
                                <div class="twperf-tool-card__title">Import Rules</div>
                                <div class="twperf-tool-card__desc">Paste a JSON export from another site to copy its rules here</div>
                            </div>
                        </div>
                        <textarea id="twperf-import-input" class="twperf-tool-textarea" placeholder="Paste exported JSON here…"></textarea>
                        <div style="display:flex;gap:8px;margin-top:8px;align-items:center;">
                            <select id="twperf-import-mode" class="twperf-action-select" style="width:auto;">
                                <option value="merge">Merge (keep existing rules)</option>
                                <option value="replace">Replace all rules</option>
                            </select>
                            <button class="twperf-btn twperf-btn--primary twperf-btn--sm" id="twperf-import-btn">Import</button>
                        </div>
                        <div id="twperf-import-result" style="margin-top:8px;font-size:12px;"></div>
                    </div>

                </div>
            </div>

            <!-- PSI TAB -->
            <div class="twperf-tab-pane" data-pane="psi">
                <div id="twperf-psi-results" class="twperf-psi-results">
                    <div class="twperf-empty">Click <strong>PSI Score</strong> to run a PageSpeed Insights analysis</div>
                </div>
            </div>

        </div><!-- /.twperf-panel__body -->

        <div class="twperf-panel__status" id="twperf-status"></div>

    </div><!-- /.twperf-panel__drawer -->
</div>

<!-- Interaction recording bar — fixed to page, outside panel -->
<div class="twperf-record-bar" id="twperf-record-bar" style="display:none;">
    <span class="twperf-record-bar__dot"></span>
    <div class="twperf-record-bar__body">
        <span class="twperf-record-bar__msg">Scroll, hover and click normally</span>
        <span class="twperf-record-bar__count" id="twperf-record-count">0 listeners captured</span>
    </div>
    <button class="twperf-record-bar__stop" id="twperf-record-stop">Stop &amp; Analyse</button>
    <button class="twperf-record-bar__cancel" id="twperf-record-cancel">✕</button>
</div>
