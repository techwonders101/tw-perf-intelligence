/**
 * TW Perf Intelligence — Frontend Panel JS
 * Vanilla JS, no dependencies, injected admin-only
 */
(function () {
    'use strict';

    const cfg       = window.twperf || {};
    const ajaxUrl        = cfg.ajax_url;
    const nonce          = cfg.nonce;
    const signatures     = cfg.signatures     || {};
    const handleMap      = cfg.handle_map     || {};
    const handlePatterns = cfg.handle_patterns || {};

    // Resolve signatures for any handle — exact match first, then regex patterns.
    // This handles plugins with dynamic handles like uag-css-557, gs-style-1234.
    function getHandleSignatures(handle) {
        if (signatures[handle]) return signatures[handle];
        for (const [pattern, data] of Object.entries(handlePatterns)) {
            try {
                const flags = pattern.startsWith('/') ? pattern.replace(/.*\/([gimsuy]*)$/, '$1') : '';
                const src   = pattern.startsWith('/') ? pattern.slice(1, pattern.lastIndexOf('/')) : pattern;
                if (new RegExp(src, flags).test(handle)) return data.sigs || [];
            } catch (_) {}
        }
        return null; // no signature known
    }

    function getHandleInfo(handle) {
        if (handleMap[handle]) return handleMap[handle];
        for (const [pattern, data] of Object.entries(handlePatterns)) {
            try {
                const flags = pattern.startsWith('/') ? pattern.replace(/.*\/([gimsuy]*)$/, '$1') : '';
                const src   = pattern.startsWith('/') ? pattern.slice(1, pattern.lastIndexOf('/')) : pattern;
                if (new RegExp(src, flags).test(handle)) return data.info || {};
            } catch (_) {}
        }
        return {};
    }

    let analysisResults  = [];
    let currentFilter    = 'all';
    let hideWpCore       = true;  // WP core scripts hidden by default
    let searchTerm       = '';
    let pendingChanges   = {}; // handle → {action, asset_type}
    let psiStrategy      = 'mobile';

    // Live DOM usage accumulated by MutationObserver from page load
    // Merges into scanDOM() so dynamically-injected elements are never missed
    let liveUsage = {};
    let domObserver = null;

    // -----------------------------------------------------------------------
    // DOM refs (created after panel HTML is injected)
    // -----------------------------------------------------------------------
    const $ = id => document.getElementById(id);
    const panel       = () => document.getElementById('twperf-panel');
    const assetList   = () => $('twperf-asset-list');
    const statusBar   = () => $('twperf-status');

    // -----------------------------------------------------------------------
    // Admin bar click → open panel
    // -----------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        const analyseLink = document.querySelector('#wp-admin-bar-twperf-analyse > a');
        if (analyseLink) {
            analyseLink.addEventListener('click', e => {
                e.preventDefault();
                openPanel();
                setTimeout(runAnalysis, 300);
            });
        }

        const twperfMenu = document.querySelector('#wp-admin-bar-twperf > a');
        if (twperfMenu) {
            twperfMenu.addEventListener('click', e => {
                e.preventDefault();
                openPanel();
            });
        }

        const previewLink = document.querySelector('#wp-admin-bar-twperf-toggle-preview > a');
        if (previewLink) {
            previewLink.addEventListener('click', e => {
                e.preventDefault();
                togglePreview();
            });
        }

        initPanel();
        startLiveDomTracking();

        // Auto-analyse when page was loaded via "Analyse This Page" (adds ?twperf=1).
        // Strip the param from the URL so bookmarks/shares are clean.
        if (new URLSearchParams(location.search).has('twperf')) {
            history.replaceState(null, '', location.pathname +
                location.search.replace(/([?&])twperf=1(&|$)/, (_, p, s) => s ? p : '').replace(/[?&]$/, '') +
                location.hash);
            openPanel();
            setTimeout(runAnalysis, 300);
        }

        // PerformanceObserver: capture the real LCP element's src
        if (window.PerformanceObserver) {
            try {
                const po = new PerformanceObserver(list => {
                    const entries = list.getEntries();
                    const last    = entries[entries.length - 1];
                    if (last && last.element) {
                        const el  = last.element;
                        const src = el.currentSrc || el.src
                            || (el.style && el.style.backgroundImage
                                ? el.style.backgroundImage.replace(/^url\(["']?|["']?\)$/g, '')
                                : null);
                        if (src) window._twperfLcpSrc = src;
                    }
                });
                po.observe({ type: 'largest-contentful-paint', buffered: true });
            } catch (_) {}
        }

        // Show page context badge
        const ctxEl = $('twperf-page-context');
        if (ctxEl && cfg.page_context) {
            const labels = {
                front_page: 'Front Page', blog: 'Blog', shop: 'Shop',
                product_cat: 'Product Category', product_tag: 'Product Tag',
                product: 'Product', cart: 'Cart', checkout: 'Checkout',
                account: 'Account', category: 'Category', tag: 'Tag',
                taxonomy: 'Taxonomy', archive: 'Archive', search: 'Search',
                '404': '404', singular: 'Singular', page: 'Page',
            };
            ctxEl.textContent = labels[cfg.page_context] || cfg.page_context;
        }
    });

    // -----------------------------------------------------------------------
    // Panel open/close
    // -----------------------------------------------------------------------
    function openPanel() {
        const p = panel();
        if (!p) return;
        p.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closePanel() {
        const p = panel();
        if (!p) return;
        p.style.display = 'none';
        document.body.style.overflow = '';
    }

    // -----------------------------------------------------------------------
    // Init all panel interactions
    // -----------------------------------------------------------------------
    function initPanel() {
        const p = panel();
        if (!p) return;

        // Close
        p.querySelector('#twperf-close')
            .addEventListener('click', closePanel);
        p.querySelector('.twperf-panel__backdrop')
            .addEventListener('click', closePanel);

        // Scan button
        p.querySelector('#twperf-scan-btn')
            .addEventListener('click', runAnalysis);

        // PSI button + strategy toggle
        p.querySelector('#twperf-run-psi')
            .addEventListener('click', runPSI);
        p.querySelector('#twperf-psi-strategy')
            ?.addEventListener('click', () => {
                psiStrategy = psiStrategy === 'mobile' ? 'desktop' : 'mobile';
                p.querySelector('#twperf-psi-strategy').textContent = psiStrategy === 'mobile' ? '📱' : '🖥';
                p.querySelector('#twperf-psi-strategy').title = `Strategy: ${psiStrategy} — click to switch`;
            });

        // Purge cache button
        const purgeBtn = p.querySelector('#twperf-purge-cache');
        if (purgeBtn) purgeBtn.addEventListener('click', purgeCache);

        // Tabs
        const filterBar = p.querySelector('#twperf-filter-bar');
        p.querySelectorAll('.twperf-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                p.querySelectorAll('.twperf-tab').forEach(t => t.classList.remove('twperf-tab--active'));
                p.querySelectorAll('.twperf-tab-pane').forEach(t => t.classList.remove('twperf-tab-pane--active'));
                tab.classList.add('twperf-tab--active');
                p.querySelector(`[data-pane="${tab.dataset.tab}"]`).classList.add('twperf-tab-pane--active');
                if (filterBar) filterBar.style.display = tab.dataset.tab === 'assets' ? '' : 'none';
                if (tab.dataset.tab === 'rules')     loadRules();
                if (tab.dataset.tab === 'all-rules') loadAllRules();
            });
        });

        // Quick Wins — seed from PHP state, wire toggles
        initQuickWins(p);

        // Tools tab buttons
        const conflictBtn = p.querySelector('#twperf-run-conflicts');
        if (conflictBtn) conflictBtn.addEventListener('click', runConflictCheck);

        const exportBtn = p.querySelector('#twperf-export-btn');
        if (exportBtn) exportBtn.addEventListener('click', exportRules);

        const importBtn = p.querySelector('#twperf-import-btn');
        if (importBtn) importBtn.addEventListener('click', importRules);

        // Filters
        p.querySelectorAll('.twperf-filter:not(#twperf-hide-wpcore)').forEach(btn => {
            btn.addEventListener('click', () => {
                p.querySelectorAll('.twperf-filter:not(#twperf-hide-wpcore)').forEach(b => b.classList.remove('twperf-filter--active'));
                btn.classList.add('twperf-filter--active');
                currentFilter = btn.dataset.filter;
                renderAssets();
            });
        });

        const hideWpCoreBtn = p.querySelector('#twperf-hide-wpcore');
        if (hideWpCoreBtn) {
            hideWpCoreBtn.classList.add('twperf-filter--active'); // hidden by default
            hideWpCoreBtn.addEventListener('click', () => {
                hideWpCore = !hideWpCore;
                hideWpCoreBtn.classList.toggle('twperf-filter--active', hideWpCore);
                renderAssets();
            });
        }

        // Search
        $('twperf-search').addEventListener('input', e => {
            searchTerm = e.target.value.toLowerCase();
            renderAssets();
        });

        // Apply all
        $('twperf-apply-all').addEventListener('click', applyAllRecommendations);

        // Preview toggle
        $('twperf-preview-toggle').addEventListener('change', togglePreview);

        // Interaction recorder
        const recordBtn  = $('twperf-record-btn');
        const stopBtn    = $('twperf-record-stop');
        const cancelBtn  = $('twperf-record-cancel');
        if (recordBtn)  recordBtn.addEventListener('click', startRecording);
        if (stopBtn)    stopBtn.addEventListener('click', stopRecording);
        if (cancelBtn)  cancelBtn.addEventListener('click', cancelRecording);

        // LCP save + font preloads + preconnects
        $('twperf-save-lcp').addEventListener('click', saveLcpImage);
        initPreloadsTab();
        initCriticalCss();

        // Keyboard ESC
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && panel().style.display !== 'none') closePanel();
        });
    }

    // -----------------------------------------------------------------------
    // MutationObserver — start on page load, accumulate DOM matches
    // This catches dynamically-injected elements (sliders initialising,
    // AJAX content, page builder components) before analysis runs
    // -----------------------------------------------------------------------
    function startLiveDomTracking() {
        if (!window.MutationObserver || !document.body) return;

        // Computed once — handles don't change after page load
        const allHandles = [...new Set([
            ...Object.keys(signatures),
            ...Object.keys(cfg.enqueued_scripts || {}),
            ...Object.keys(cfg.enqueued_styles  || {}),
        ])];

        const checkNode = (node) => {
            if (node.nodeType !== 1) return; // elements only
            const foldLine = window.innerHeight;

            allHandles.forEach(handle => {
                const selectors = getHandleSignatures(handle);
                if (!selectors || !selectors.length) return;
                selectors.forEach(sel => {
                    try {
                        const matches = [];
                        if (node.matches && node.matches(sel)) matches.push(node);
                        if (node.querySelectorAll) {
                            matches.push(...node.querySelectorAll(sel));
                        }
                        if (!matches.length) return;

                        if (!liveUsage[handle]) {
                            liveUsage[handle] = { found: false, above_fold: false, count: 0 };
                        }
                        liveUsage[handle].found = true;
                        liveUsage[handle].count += matches.length;
                        if (!liveUsage[handle].above_fold) {
                            // Use document-relative position so scroll position doesn't
                            // cause below-fold lazy-loaded elements to appear above-fold
                            liveUsage[handle].above_fold = matches.some(el => {
                                const r = el.getBoundingClientRect();
                                return (r.top + window.scrollY) < foldLine;
                            });
                        }
                    } catch (_) {}
                });
            });
        };

        domObserver = new MutationObserver(mutations => {
            mutations.forEach(m => m.addedNodes.forEach(checkNode));
        });

        domObserver.observe(document.body, { childList: true, subtree: true });
    }

    // -----------------------------------------------------------------------
    // Point-in-time DOM scan — merges with accumulated liveUsage
    // -----------------------------------------------------------------------
    function scanDOM() {
        const foldLine  = window.innerHeight;
        const usage     = {};

        const allHandles = [...new Set([
            ...Object.keys(signatures),
            ...Object.keys(cfg.enqueued_scripts || {}),
            ...Object.keys(cfg.enqueued_styles  || {}),
        ])];

        allHandles.forEach(handle => {
            const selectors = getHandleSignatures(handle);
            if (!selectors || !selectors.length) {
                usage[handle] = { found: false, above_fold: false, count: 0 };
                return;
            }

            const elements = selectors.flatMap(s => {
                try { return [...document.querySelectorAll(s)]; }
                catch { return []; }
            });

            const unique = [...new Set(elements)];
            // Only count as above-fold if the element is actually visible and rendered.
            // Hidden elements (display:none, inside collapsed sliders/tabs, not-yet-init
            // galleries) report top=0 via getBoundingClientRect — that's a false positive.
            const isVisiblyAboveFold = el => {
                if (!el.offsetParent && el.tagName !== 'BODY') return false; // display:none parent
                const r = el.getBoundingClientRect();
                if (r.width === 0 && r.height === 0) return false; // no layout box
                // Document-relative: top + scrollY gives position from page top
                // so scroll position during the scroll trick doesn't create false positives
                return (r.top + window.scrollY) < foldLine;
            };
            usage[handle] = {
                found:      unique.length > 0,
                above_fold: unique.some(isVisiblyAboveFold),
                count:      unique.length,
            };
        });

        // Merge accumulated live observations — found:true can never be downgraded
        Object.entries(liveUsage).forEach(([handle, live]) => {
            if (!usage[handle]) {
                usage[handle] = { found: false, above_fold: false, count: 0 };
            }
            if (live.found) {
                usage[handle].found      = true;
                usage[handle].count      = Math.max(usage[handle].count, live.count);
                usage[handle].above_fold = usage[handle].above_fold || live.above_fold;
            }
        });

        // Ensure all enqueued handles appear in map — including DOM-detected ones
        const domAssets = collectDomAssets();
        [
            ...Object.keys(cfg.enqueued_scripts || {}),
            ...Object.keys(cfg.enqueued_styles  || {}),
            ...Object.keys(domAssets.scripts),
            ...Object.keys(domAssets.styles),
        ].forEach(h => {
            if (!usage[h]) usage[h] = { found: false, above_fold: false, count: 0 };
        });

        return usage;
    }

    // -----------------------------------------------------------------------
    // Font audit — Google Fonts display=swap, render-blocking, missing preloads
    // -----------------------------------------------------------------------
    function auditFonts() {
        const issues = [];
        const preloadedFonts = [...document.querySelectorAll('link[rel="preload"][as="font"]')]
            .map(l => l.href);

        // Google Fonts links
        document.querySelectorAll('link[href*="fonts.googleapis.com"]').forEach(link => {
            const href = link.getAttribute('href') || '';
            const rel  = link.getAttribute('rel') || '';

            if (rel === 'stylesheet') {
                if (!href.includes('display=swap')) {
                    issues.push({
                        type:    'font-no-swap',
                        sev:     'error',
                        title:   'Google Fonts: missing display=swap',
                        detail:  'Causes invisible text (FOIT) during load. Add &display=swap to the URL.',
                        fix:     href + (href.includes('?') ? '&' : '?') + 'display=swap',
                        fix_label: 'Corrected URL',
                    });
                }
                issues.push({
                    type:   'font-blocking',
                    sev:    'warning',
                    title:  'Google Fonts: render-blocking stylesheet',
                    detail: 'Synchronous Google Fonts link blocks rendering. Use preconnect + font-display:swap, or self-host.',
                    fix:    '<link rel="preconnect" href="https://fonts.googleapis.com">\n<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>',
                    fix_label: 'Add preconnect hints',
                });
            }
        });

        // Check for @font-face in inline/linked stylesheets without font-display
        try {
            [...document.styleSheets].forEach(sheet => {
                try {
                    [...(sheet.cssRules || [])].forEach(rule => {
                        if (rule.type === CSSRule.FONT_FACE_RULE) {
                            const src   = rule.style.getPropertyValue('src') || '';
                            const disp  = rule.style.getPropertyValue('font-display') || '';
                            const family = rule.style.getPropertyValue('font-family') || '';
                            if (!disp && src && !src.includes('fonts.gstatic.com')) {
                                issues.push({
                                    type:   'fontface-no-display',
                                    sev:    'warning',
                                    title:  'Self-hosted font missing font-display',
                                    detail: 'font-family: ' + family.replace(/"/g, '') + ' — add font-display:swap to prevent invisible text.',
                                    fix:    'Add font-display: swap; inside the @font-face rule',
                                    fix_label: 'Suggested fix',
                                });
                            }
                        }
                    });
                } catch (_) {} // cross-origin sheets throw
            });
        } catch (_) {}

        return { issues, preloaded_fonts: preloadedFonts };
    }

    // -----------------------------------------------------------------------
    // Image / LCP audit
    // -----------------------------------------------------------------------
    function auditImages() {
        const issues = [];
        const foldLine = window.innerHeight;

        // Find all images above fold with meaningful size — document-relative position
        const scrollY = window.scrollY || 0;
        const imgs = [...document.querySelectorAll('img')].filter(img => {
            if (!img.src && !img.currentSrc) return false;
            const r = img.getBoundingClientRect();
            return (r.top + scrollY) < foldLine && r.width > 80 && r.height > 80;
        });

        // Also check background-image hero elements (common pattern)
        const heroCandidates = [...document.querySelectorAll(
            '.hero, .banner, [class*="hero"], [class*="banner"], [class*="slider"], header'
        )].filter(el => {
            const r = el.getBoundingClientRect();
            return (r.top + scrollY) < foldLine && r.width > 200;
        });

        // Find likely LCP: largest above-fold img by area
        let lcpImg = null;
        let maxArea = 0;
        imgs.forEach(img => {
            const r = img.getBoundingClientRect();
            const area = r.width * r.height;
            if (area > maxArea) { maxArea = area; lcpImg = img; }
        });

        // Use PerformanceObserver LCP entry if available (most accurate)
        // We store it when the page loads
        const lcpSrc = window._twperfLcpSrc || (lcpImg ? (lcpImg.currentSrc || lcpImg.src) : null);
        const lcpEl  = lcpImg;

        if (lcpEl) {
            const src            = lcpEl.currentSrc || lcpEl.src;
            const hasFetchPri    = lcpEl.getAttribute('fetchpriority') === 'high';
            const hasLazyLoad    = lcpEl.getAttribute('loading') === 'lazy';
            const hasDecoding    = lcpEl.getAttribute('decoding');
            const isPreloaded    = !!document.querySelector('link[rel="preload"][as="image"]');
            const hasExplicitWH  = lcpEl.getAttribute('width') && lcpEl.getAttribute('height');

            if (!hasFetchPri) {
                issues.push({
                    type:   'lcp-fetchpriority',
                    sev:    'error',
                    title:  'LCP image: missing fetchpriority="high"',
                    detail: 'Tells the browser to fetch this image at highest priority. Single biggest LCP win.',
                    fix:    'fetchpriority="high"',
                    fix_label: 'Add to img tag',
                    src,
                });
            }
            if (hasLazyLoad) {
                issues.push({
                    type:   'lcp-lazy',
                    sev:    'error',
                    title:  'LCP image: has loading="lazy"',
                    detail: 'Never lazy-load the LCP image — it significantly delays LCP.',
                    fix:    'Remove loading="lazy" (or change to loading="eager")',
                    fix_label: 'Required fix',
                    src,
                });
            }
            if (!isPreloaded) {
                issues.push({
                    type:   'lcp-preload',
                    sev:    'warning',
                    title:  'LCP image: not preloaded',
                    detail: 'Add a <link rel="preload"> in <head> to start the fetch as early as possible.',
                    fix:    '<link rel="preload" href="' + esc(src) + '" as="image" fetchpriority="high">',
                    fix_label: 'Add to <head>',
                    src,
                });
            }
            if (!hasExplicitWH) {
                issues.push({
                    type:   'lcp-dimensions',
                    sev:    'warning',
                    title:  'LCP image: missing explicit width/height',
                    detail: 'Without width and height attributes the browser cannot reserve layout space, causing CLS.',
                    fix:    'Add width="…" height="…" matching the intrinsic dimensions',
                    fix_label: 'Suggested fix',
                    src,
                });
            }
        }

        // Check other above-fold imgs for lazy loading (wrong on above-fold)
        imgs.forEach(img => {
            if (img === lcpEl) return;
            if (img.getAttribute('loading') === 'lazy') {
                const src = img.currentSrc || img.src;
                const name = src.split('/').pop().split('?')[0].slice(0, 40);
                issues.push({
                    type:   'above-fold-lazy',
                    sev:    'warning',
                    title:  'Above-fold image: loading="lazy"',
                    detail: name + ' is above fold but lazy-loaded — delays its paint.',
                    fix:    'Remove loading="lazy" (or change to loading="eager")',
                    fix_label: 'Suggested fix',
                    src,
                });
            }
        });

        return {
            issues,
            lcp_src: lcpSrc,
            lcp_area_px: maxArea,
        };
    }

    // -----------------------------------------------------------------------
    // Detect jQuery usage in inline scripts on the page
    // -----------------------------------------------------------------------
    function detectJQueryInlineUsage() {
        const inlineScripts = document.querySelectorAll('script:not([src])');
        const regex = /(?:\bjQuery\s*\(|\$\s*\(|\$\s*\.(?:ajax|get|post|fn|each|extend|ready))/;
        let found = false;
        inlineScripts.forEach(script => {
            if (script.id && script.id.startsWith('twperf')) return;
            if (script.textContent && regex.test(script.textContent)) found = true;
        });
        return found;
    }

    // -----------------------------------------------------------------------
    // Collect extra detection data for smarter server-side analysis
    // -----------------------------------------------------------------------
    function collectExtraData() {
        return {
            jquery_inline_usage: detectJQueryInlineUsage(),
            has_woocommerce:     !!document.querySelector('.woocommerce, .wc-block-grid, body.woocommerce-page'),
            has_forms:           !!document.querySelector('.wpcf7, .wpforms-form, .gform_wrapper, form.search-form'),
            viewport_width:      window.innerWidth,
            total_dom_nodes:     document.querySelectorAll('*').length,
        };
    }

    // -----------------------------------------------------------------------
    // Fix key mapping — which audit issue types can be auto-fixed server-side
    // -----------------------------------------------------------------------
    const AUDIT_FIX_MAP = {
        'fontface-no-display': 'font_display',
        'font-no-swap':        'font_display',
        'lcp-fetchpriority':   'lcp_attrs',
        'lcp-lazy':            'lcp_attrs',
    };

    // Track which fix keys have already been applied (seeded from PHP on page load)
    const appliedFixes = new Set(cfg.enabled_fixes || []);

    function applyAuditFix(fixKey, btn) {
        btn.textContent = '…';
        btn.disabled = true;
        ajax('twperf_apply_audit_fix', { fix: fixKey }).then(() => {
            appliedFixes.add(fixKey);
            btn.textContent = '✓ Enabled';
            btn.classList.add('twperf-audit-fix-btn--done');
            setStatus('Fix enabled: ' + fixKey.replace('_', ' ') + ' — reload page to verify', 'ok');
            // Update all buttons for the same fix key
            document.querySelectorAll('.twperf-audit-fix-btn[data-fix="' + fixKey + '"]').forEach(b => {
                b.textContent = '✓ Enabled';
                b.disabled = true;
                b.classList.add('twperf-audit-fix-btn--done');
            });
        }).catch(err => {
            btn.textContent = 'Apply Fix';
            btn.disabled = false;
            setStatus('Fix failed: ' + err, 'error');
        });
    }

    // -----------------------------------------------------------------------
    // Render the page audit section (fonts + images) above asset list
    // -----------------------------------------------------------------------
    function renderPageAudit(fontAudit, imageAudit) {
        const container = $('twperf-page-audit');
        if (!container) return;

        const allIssues = [
            ...(fontAudit.issues  || []),
            ...(imageAudit.issues || []),
        ];

        if (!allIssues.length) {
            container.style.display = 'none';
            return;
        }

        const sevClass = { error: 'unload', warning: 'delay', info: 'defer' };
        const sevLabel = { error: 'Fix', warning: 'Improve', info: 'Info' };

        let html = '<div class="twperf-audit-section">';
        html += '<div class="twperf-group-header">Page Audit — Fonts &amp; Images (' + allIssues.length + ')</div>';

        allIssues.forEach(issue => {
            const sc     = sevClass[issue.sev] || 'defer';
            const sl     = sevLabel[issue.sev] || 'Note';
            const fixKey = AUDIT_FIX_MAP[issue.type];
            const alreadyApplied = fixKey && appliedFixes.has(fixKey);

            html += '<div class="twperf-audit-item">';
            html += '<div class="twperf-audit-item__head">';
            html += '<span class="twperf-rec-badge twperf-rec-badge--' + sc + '">' + sl + '</span>';
            html += '<strong class="twperf-audit-item__title">' + esc(issue.title) + '</strong>';

            if (issue.type === 'lcp-preload' && issue.src) {
                html += '<button class="twperf-audit-fix-btn twperf-audit-lcp-btn" data-src="' + esc(issue.src) + '">Save Preload</button>';
            } else if (fixKey) {
                const label = alreadyApplied ? '✓ Enabled' : 'Apply Fix';
                const done  = alreadyApplied ? ' twperf-audit-fix-btn--done' : '';
                const dis   = alreadyApplied ? ' disabled' : '';
                html += '<button class="twperf-audit-fix-btn' + done + '" data-fix="' + esc(fixKey) + '"' + dis + '>' + label + '</button>';
            }

            html += '</div>';
            html += '<div class="twperf-audit-item__detail">' + esc(issue.detail) + '</div>';
            if (issue.fix) {
                html += '<div class="twperf-audit-item__fix">';
                html += '<span class="twperf-audit-item__fix-label">' + esc(issue.fix_label || 'Fix') + ':</span> ';
                html += '<code class="twperf-audit-item__fix-code">' + esc(issue.fix) + '</code>';
                html += '</div>';
            }
            html += '</div>';
        });

        html += '</div>';
        container.style.display = 'block';
        container.innerHTML = html;

        // Bind fix buttons
        container.querySelectorAll('.twperf-audit-fix-btn:not([disabled])').forEach(btn => {
            btn.addEventListener('click', () => applyAuditFix(btn.dataset.fix, btn));
        });

        // LCP preload save button — saves directly via twperf_save_lcp
        container.querySelectorAll('.twperf-audit-lcp-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.textContent = '…'; btn.disabled = true;
                ajax('twperf_save_lcp', { post_id: cfg.post_id, lcp_url: btn.dataset.src })
                    .then(() => {
                        btn.textContent = '✓ Saved';
                        btn.classList.add('twperf-audit-fix-btn--done');
                        setStatus('LCP preload saved — reload to verify.', 'ok');
                    })
                    .catch(err => {
                        btn.textContent = 'Save Preload'; btn.disabled = false;
                        setStatus('Failed: ' + err, 'error');
                    });
            });
        });

        // Populate LCP URL field in Preloads tab if we found a candidate
        if (imageAudit.lcp_src) {
            const lcpInput = $('twperf-lcp-url');
            if (lcpInput && !lcpInput.value) {
                lcpInput.value = imageAudit.lcp_src;
            }
            const lcpDetected = $('twperf-lcp-detected');
            if (lcpDetected) {
                lcpDetected.style.display = 'block';
                const msg = document.createElement('div');
                msg.className = 'twperf-audit-detected';
                msg.textContent = 'Detected LCP image: ' + imageAudit.lcp_src.split('/').pop().split('?')[0];
                lcpDetected.textContent = '';
                lcpDetected.appendChild(msg);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Run analysis
    // -----------------------------------------------------------------------
    // -----------------------------------------------------------------------
    // Scan the live DOM for <script src> and <link rel="stylesheet"> tags.
    // Catches assets added after wp_enqueue_scripts fires (UAG, Greenshift,
    // image-gallery plugins, late-enqueued scripts, etc.).
    // WordPress outputs id="handle-js" / id="handle-css" on each tag.
    // -----------------------------------------------------------------------
    function collectDomAssets() {
        const scripts = {};
        const styles  = {};

        const wpIncludesRe = /\/wp-includes\//;
        const wpAdminRe    = /\/wp-admin\//;

        document.querySelectorAll('script[src]').forEach(el => {
            let handle = (el.id || '').replace(/-js$/, '').replace(/-js-\d+$/, '');
            if (!handle) {
                try {
                    const u = new URL(el.src);
                    handle = u.pathname.split('/').pop().replace(/\.min\.js$|\.js$/, '').replace(/[^a-z0-9-_]/gi, '-');
                } catch (_) { return; }
            }
            if (!handle || handle === 'twperf-panel') return;
            if (!scripts[handle]) {
                const isWpCore     = wpIncludesRe.test(el.src) || wpAdminRe.test(el.src);
                const isThirdParty = (() => { try { return new URL(el.src, location.href).origin !== location.origin; } catch(_) { return false; } })();
                scripts[handle] = { handle, src: el.src, deps: [], ver: '', _wp_core: isWpCore, _third_party: isThirdParty };
            }
        });

        document.querySelectorAll('link[rel="stylesheet"]').forEach(el => {
            let handle = (el.id || '').replace(/-css$/, '').replace(/-inline-css$/, '').replace(/-css-\d+$/, '');
            if (!handle || el.id.endsWith('-inline-css')) return;
            if (!handle || handle === 'twperf-panel') return;
            if (!styles[handle]) {
                const isWpCore     = wpIncludesRe.test(el.href) || wpAdminRe.test(el.href);
                const isThirdParty = (() => { try { return new URL(el.href, location.href).origin !== location.origin; } catch(_) { return false; } })();
                styles[handle] = { handle, src: el.href || '', deps: [], ver: '', _wp_core: isWpCore, _third_party: isThirdParty };
            }
        });

        // Performance API fallback — catches scripts/styles loaded dynamically
        // (not in DOM as <script>/<link> at scan time, e.g. plugins that inject via JS)
        if (window.performance && performance.getEntriesByType) {
            // Helper: derive a collision-resistant handle from the full URL path.
            // Uses plugin/theme directory name as prefix so "frontend.js" from
            // two different plugins produces two distinct handles.
            const handleFromUrl = (url) => {
                try {
                    const parts = new URL(url).pathname.split('/').filter(Boolean);
                    const filename = parts.pop().replace(/\.min\.(js|css)$|\.(?:js|css)$/, '');
                    // Find the segment after "plugins" or "themes" — that's the plugin/theme slug
                    const pluginsIdx = parts.lastIndexOf('plugins');
                    const themesIdx  = parts.lastIndexOf('themes');
                    const prefixIdx  = Math.max(pluginsIdx, themesIdx);
                    const prefix     = prefixIdx >= 0 && parts[prefixIdx + 1] ? parts[prefixIdx + 1] : '';
                    const raw        = prefix ? prefix + '-' + filename : filename;
                    return raw.replace(/[^a-z0-9-_]/gi, '-').toLowerCase();
                } catch (_) { return null; }
            };

            performance.getEntriesByType('resource').forEach(entry => {
                const url = entry.name;
                if (!url || url.includes('twperf-panel') || url.includes('admin-ajax')) return;

                const isStyle  = /\.css(\?|$)/.test(url);
                const isScript = /\.js(\?|$)/.test(url);
                if (!isStyle && !isScript) return;

                // Check if this URL is already represented in DOM-derived data
                const alreadyInDom = isStyle
                    ? Object.values(styles).some(s => s.src && s.src.split('?')[0] === url.split('?')[0])
                    : Object.values(scripts).some(s => s.src && s.src.split('?')[0] === url.split('?')[0]);
                if (alreadyInDom) return;

                const handle = handleFromUrl(url);
                if (!handle) return;

                const isWpCore     = wpIncludesRe.test(url) || wpAdminRe.test(url);
                const isThirdParty = (() => { try { return new URL(url).origin !== location.origin; } catch(_) { return false; } })();

                // Use a unique key — append suffix if handle already taken by a different src
                const makeKey = (base, map) => {
                    if (!map[base]) return base;
                    if (map[base].src && map[base].src.split('?')[0] === url.split('?')[0]) return null; // exact dup
                    return base + '-perf';
                };

                if (isStyle) {
                    const key = makeKey(handle, styles);
                    if (key) styles[key] = { handle: key, src: url, deps: [], ver: '', _wp_core: isWpCore, _third_party: isThirdParty, _perf_only: true };
                } else {
                    const key = makeKey(handle, scripts);
                    if (key) scripts[key] = { handle: key, src: url, deps: [], ver: '', _wp_core: isWpCore, _third_party: isThirdParty, _perf_only: true };
                }
            });
        }

        return { scripts, styles };
    }

    function runAnalysis() {
        setStatus('Scanning DOM…', 'busy');
        showLoading();

        // Trigger a scroll to bottom and back to force lazy elements into DOM,
        // then wait briefly for any IntersectionObserver-based lazy loads
        const origScroll = window.scrollY;
        window.scrollTo(0, document.body.scrollHeight);
        window.scrollTo(0, origScroll);

        // Brief delay to let lazy-loaded content settle, then scan
        setTimeout(() => {
            // Merge PHP-provided queue with live DOM scan.
            // DOM scan catches assets added after wp_enqueue_scripts fires.
            // PHP data takes precedence (it has deps/ver info).
            const domAssets     = collectDomAssets();
            const mergedScripts = Object.assign({}, domAssets.scripts, cfg.enqueued_scripts || {});
            const mergedStyles  = Object.assign({}, domAssets.styles,  cfg.enqueued_styles  || {});


            const domUsage = scanDOM();
            const extra    = collectExtraData();

            setStatus('Analysing assets…', 'busy');

            ajax('twperf_analyse', {
                post_id:          cfg.post_id,
                post_type:        cfg.post_type || '',
                url:              cfg.current_url,
                dom_usage:        JSON.stringify(domUsage),
                enqueued_scripts: JSON.stringify(mergedScripts),
                enqueued_styles:  JSON.stringify(mergedStyles),
                extra:            JSON.stringify(extra),
            }).then(data => {
                analysisResults = data.assets || [];
                // Tag third-party, admin-only, and wp-core assets
                analysisResults.forEach(a => {
                    if (a.src && !a._third_party) {
                        try { a._third_party = new URL(a.src, location.href).origin !== location.origin; }
                        catch(_) {}
                    }
                    const clientData = mergedScripts[a.handle] || mergedStyles[a.handle];
                    if (clientData && clientData._third_party) a._third_party = true;
                    // Tag wp-core by src path — covers wp-includes/js/dist/* packages
                    // (react, hooks, i18n, data, etc.) that aren't in the handle map
                    if (!a._wp_core && a.src && /\/wp-includes\//.test(a.src)) a._wp_core = true;
                    if (clientData && clientData._wp_core) a._wp_core = true;
                    a._admin_only = isAdminOnlyAsset(a);
                });
                pendingChanges  = {};
                // Recalculate summary from client-side results so admin-only assets are excluded
                const clientSummary = {};
                analysisResults.forEach(a => {
                    if (a._admin_only) return;
                    const action = a.rec?.action || 'keep';
                    clientSummary[action] = (clientSummary[action] || 0) + 1;
                });
                renderSummary(clientSummary);
                renderAssets();
                $('twperf-summary').style.display = 'flex';

                // Font + image audit (runs client-side, no extra AJAX)
                const fontAudit  = auditFonts();
                const imageAudit = auditImages();
                renderPageAudit(fontAudit, imageAudit);

                // Suggest preconnects for third-party origins found in analysis
                suggestThirdPartyPreconnects(analysisResults);

                // Calculate estimated savings
                const savings = analysisResults
                    .filter(a => !a._admin_only && ['unload','delay','defer','async_css'].includes(a.rec?.action))
                    .reduce((sum, a) => sum + (a.size_bytes || 0), 0);
                const savingsKb = savings > 0 ? (' \u00B7 ~' + (savings / 1024).toFixed(0) + ' KiB saveable') : '';

                // Show cache plugin status in status bar
                const cacheInfo = (data.cache_plugins || []).length
                    ? (' \u00B7 Cache: ' + data.cache_plugins.join(', '))
                    : ' \u00B7 No cache plugin detected';

                // jQuery inline usage note
                const jqNote = extra.jquery_inline_usage ? ' \u00B7 jQuery used in inline scripts' : '';

                const visibleCount = analysisResults.filter(a => !a._admin_only).length;
                setStatus('Found ' + visibleCount + ' assets. ' + countRecommendations(analysisResults) + ' optimisations available.' + savingsKb + cacheInfo + jqNote, 'ok');
            }).catch(err => {
                setStatus('Analysis failed: ' + err, 'error');
                assetList().textContent = '';
                const errDiv = document.createElement('div');
                errDiv.className = 'twperf-empty';
                errDiv.textContent = 'Analysis failed. Check console.';
                assetList().appendChild(errDiv);
            });
        }, 500);
    }

    // -----------------------------------------------------------------------
    // Detect assets that are admin/editor-only and have no business on frontend
    // -----------------------------------------------------------------------
    function isAdminOnlyAsset(asset) {
        const src    = asset.src    || '';
        const handle = asset.handle || '';

        // src path contains editor-only directories
        if (/\/(block-editor|edit-post|edit-site|edit-widgets|format-library|customize-controls|customize-widgets|code-editor)\//i.test(src)) return true;
        if (/\/wp-admin\//i.test(src)) return true;

        // well-known admin-only handles
        const adminHandles = /^wp-(edit-post|edit-site|edit-widgets|block-editor|customize-|block-directory|nux)($|-)/;
        if (adminHandles.test(handle)) return true;

        // wp-block-*-editor suffix (block editor scripts, not block frontend scripts)
        if (/^wp-block-.+-editor$/.test(handle)) return true;

        // WP Gutenberg dist packages — confirmed not loaded on real frontend pages
        if (/\/wp-includes\/js\/dist\//i.test(src)) return true;

        // WooCommerce block client scripts — confirmed not loaded on real frontend pages
        if (/\/woocommerce\/assets\/client\//i.test(src)) return true;

        return false;
    }

    // -----------------------------------------------------------------------
    // Render asset list
    // -----------------------------------------------------------------------
    function renderAssets() {
        const list = assetList();
        if (!analysisResults.length) return;

        // Hide page audit when any filter is active (it doesn't belong to a group)
        const audit = $('twperf-page-audit');
        if (audit) audit.style.display = (currentFilter === 'all' && !searchTerm) ? '' : 'none';

        const groups = {
            unload:      { label: 'Safe to Unload',      items: [] },
            delay:       { label: 'Safe to Delay',        items: [] },
            defer:       { label: 'Safe to Defer',        items: [] },
            async_css:   { label: 'Async CSS',            items: [] },
            investigate: { label: 'Needs Investigation', items: [] },
            manual:      { label: 'Manual Review',       items: [] },
            keep:        { label: 'Active / Keep',        items: [] },
        };

        let adminOnlyCount = 0;

        analysisResults.forEach(asset => {
            // Recording confirmed this asset is active — override recommendation
            const isConfirmedActive = !!(asset._recorded_events || asset._companion_of);
            const action = isConfirmedActive ? 'keep' : (asset.rec?.action || 'keep');
            const key    = groups[action] ? action : 'keep';

            // Always hide admin-only assets from main list (count them)
            if (asset._admin_only) { adminOnlyCount++; return; }

            // Hide WP core assets if toggle active
            if (hideWpCore && (asset.plugin_info?.category === 'wp-core' || asset._wp_core)) return;

            // Apply filter — action-group filters are bypassed when a search term is active
            // so search always finds across all groups
            if (currentFilter !== 'all') {
                if (currentFilter === 'script'      && asset.asset_type !== 'script') return;
                if (currentFilter === 'style'       && asset.asset_type !== 'style')  return;
                if (currentFilter === 'third_party' && !asset._third_party)           return;
                if (!searchTerm && !['script','style','third_party'].includes(currentFilter) && key !== currentFilter) return;
            }

            // Apply search — handle, plugin name, and src path
            if (searchTerm && !asset.handle.toLowerCase().includes(searchTerm)
                && !(asset.plugin_info?.plugin || '').toLowerCase().includes(searchTerm)
                && !(asset.src || '').toLowerCase().includes(searchTerm)) return;

            groups[key].items.push(asset);
        });

        let html = '';

        // Admin-only notice chip
        if (adminOnlyCount) {
            html += `<div class="twperf-admin-only-notice">
                ${adminOnlyCount} admin/editor-only asset${adminOnlyCount > 1 ? 's' : ''} hidden
                <span class="twperf-admin-only-notice__hint">— block editor, customizer, wp-edit-* scripts that only run in wp-admin</span>
            </div>`;
        }

        Object.entries(groups).forEach(([action, group]) => {
            if (!group.items.length) return;
            html += `<div class="twperf-group-header" data-group="${action}">${group.label} (${group.items.length})</div>`;
            group.items.forEach(asset => {
                html += renderAssetItem(asset);
            });
        });

        list.innerHTML = html || '<div class="twperf-empty">No assets match this filter.</div>';

        // Bind action selects + apply buttons
        list.querySelectorAll('.twperf-action-select').forEach(sel => {
            sel.addEventListener('change', e => {
                const handle  = sel.dataset.handle;
                const newAction = e.target.value;
                pendingChanges[handle] = {
                    action:     newAction,
                    asset_type: sel.dataset.assetType,
                    context:    pendingChanges[handle]?.context || 'both',
                };
                // Show/hide context select
                const ctxSel = list.querySelector(`.twperf-context-select[data-handle="${handle}"]`);
                if (ctxSel) ctxSel.style.display = newAction === 'unload' ? 'block' : 'none';
            });
        });

        list.querySelectorAll('.twperf-context-select').forEach(sel => {
            sel.addEventListener('change', e => {
                const handle = sel.dataset.handle;
                pendingChanges[handle] = Object.assign(pendingChanges[handle] || {}, { context: e.target.value });
            });
        });

        list.querySelectorAll('.twperf-btn-apply').forEach(btn => {
            btn.addEventListener('click', () => {
                const handle  = btn.dataset.handle;
                const sel     = list.querySelector(`.twperf-action-select[data-handle="${handle}"]`);
                const ctxSel  = list.querySelector(`.twperf-context-select[data-handle="${handle}"]`);
                if (!sel) return;
                const chosenAction = sel.value;
                const context      = (chosenAction === 'unload' && ctxSel) ? ctxSel.value : 'both';

                // Low confidence — save as preview-only rule (only active when preview cookie set)
                if (btn.dataset.previewOnly === '1') {
                    const ok = confirm(
                        `"${handle}" has low confidence. This will save the rule but it will only activate in Preview Mode so you can test it safely.\n\nContinue?`
                    );
                    if (!ok) return;
                    saveRule(handle, sel.dataset.assetType, chosenAction, btn, context, true);
                    return;
                }

                // Medium confidence — warn before saving
                if (btn.dataset.needsConfirm === '1' && chosenAction === 'unload') {
                    const ok = confirm(
                        `Medium confidence: are you sure you want to unload "${handle}"?\n\nEnable Preview Mode first to test it won't break anything.`
                    );
                    if (!ok) return;
                }

                saveRule(handle, sel.dataset.assetType, chosenAction, btn, context, false);
            });
        });

        // Go Live — promote preview-only rule to always-active
        list.querySelectorAll('.twperf-btn-go-live').forEach(btn => {
            btn.addEventListener('click', () => {
                const handle    = btn.dataset.handle;
                const assetType = btn.dataset.assetType;
                const ok = confirm(`Make "${handle}" live for all visitors (not just preview mode)?`);
                if (!ok) return;
                btn.textContent = '…'; btn.disabled = true;
                goLive(handle, assetType, btn);
            });
        });

        // Dep tree expand buttons
        list.querySelectorAll('.twperf-dep-expand').forEach(btn => {
            btn.addEventListener('click', () => {
                const handle = btn.dataset.handle;
                const type   = btn.dataset.type;
                const panel  = document.getElementById('dep-tree-' + handle);
                if (!panel) return;

                if (panel.style.display !== 'none') {
                    panel.style.display = 'none';
                    btn.textContent = 'show tree ▾';
                    return;
                }

                btn.textContent = 'loading…';
                const assetsData = type === 'script' ? cfg.enqueued_scripts : cfg.enqueued_styles;
                ajax('twperf_dep_tree', { handle, asset_type: type, assets_data: JSON.stringify(assetsData || {}) }).then(data => {
                    panel.style.display = 'block';
                    panel.innerHTML = renderDepTree(data.tree, data.dependents);
                    btn.textContent = 'hide tree ▴';
                }).catch(() => {
                    btn.textContent = 'show tree ▾';
                });
            });
        });
    }

    // Returns true if any of this asset's dependents are confirmed-active (keep group)
    function hasActiveDependents(asset) {
        return (asset.dependents || []).some(depHandle => {
            const dep = analysisResults.find(a => a.handle === depHandle);
            if (!dep) return false;
            const isConfirmed = !!(dep._recorded_events || dep._companion_of);
            const depAction   = isConfirmed ? 'keep' : (dep.rec?.action || 'keep');
            return depAction === 'keep';
        });
    }

    function renderAssetItem(asset) {
        const rec       = asset.rec || {};
        const info      = asset.plugin_info || {};
        const action    = rec.action || 'keep';
        const confidence= rec.confidence || '';
        const sizeKb    = asset.size_bytes ? (asset.size_bytes / 1024).toFixed(1) + ' KiB' : '';
        const deps      = (asset.dependents || []).length;
        const src       = asset.src ? asset.src.split('?')[0].split('/').slice(-2).join('/') : '';

        const savedAction = getSavedAction(asset.handle);
        const selectValue = pendingChanges[asset.handle]?.action || savedAction || action;

        const depWarning = deps > 0
            ? `<span class="twperf-deps" data-handle="${esc(asset.handle)}" data-type="${esc(asset.asset_type)}">
                ⚠️ ${deps} script${deps>1?'s':''} depend on this
                <button class="twperf-dep-expand" data-handle="${esc(asset.handle)}" data-type="${esc(asset.asset_type)}">show tree ▾</button>
               </span>`
            : '';

        // Options vary by asset type
        // Remove unload if: recording confirmed active OR has active dependents (would break them)
        const isConfirmedActive  = !!(asset._recorded_events || asset._companion_of);
        const activeDepsBlocking = hasActiveDependents(asset);
        let actionOpts = asset.asset_type === 'script'
            ? ['keep','unload','defer','delay','preload']
            : ['keep','unload','async_css','preload'];
        if (isConfirmedActive || activeDepsBlocking) actionOpts = actionOpts.filter(o => o !== 'unload');
        const options = actionOpts
            .map(o => `<option value="${o}" ${selectValue===o?'selected':''}>${o.replace('_',' ')}</option>`)
            .join('');

        const savedCtx   = pendingChanges[asset.handle]?.context || 'both';
        const showCtx    = selectValue === 'unload';
        const ctxOptions = ['both','frontend','admin']
            .map(c => `<option value="${c}" ${savedCtx===c?'selected':''}>${c}</option>`)
            .join('');

        const isSaved      = !!savedAction;
        const previewOnly  = isPreviewOnly(asset.handle);

        // Apply button behaviour gated by confidence:
        // high         → normal Apply
        // medium       → Apply with warning data-attr (will prompt on click)
        // low          → "Test in Preview" only (saves rule as preview_only in DB)
        // saved+preview→ "✓ Preview Only" state + separate "Go Live" button
        let applyLabel, applyClass, applyExtra;
        if (isSaved && previewOnly) {
            applyLabel = '✓ Preview Only'; applyClass = 'twperf-btn-apply--preview twperf-btn-apply--saved'; applyExtra = '';
        } else if (isSaved) {
            applyLabel = '✓ Saved'; applyClass = 'twperf-btn-apply--saved'; applyExtra = '';
        } else if (confidence === 'low') {
            applyLabel = 'Test in Preview'; applyClass = 'twperf-btn-apply--preview'; applyExtra = 'data-preview-only="1"';
        } else if (confidence === 'medium') {
            applyLabel = 'Apply'; applyClass = 'twperf-btn-apply--warn'; applyExtra = 'data-needs-confirm="1"';
        } else {
            applyLabel = 'Apply'; applyClass = ''; applyExtra = '';
        }

        const poInfo    = (cfg.preview_only_rules || {})[asset.handle] || {};
        const goLiveBtn = (isSaved && previewOnly)
            ? `<button class="twperf-btn-go-live" data-handle="${esc(asset.handle)}" data-asset-type="${esc(asset.asset_type)}" data-rule-type="${esc(poInfo.rule_type || '')}" data-target="${esc(poInfo.target ?? '')}" title="Make this rule active for all visitors, not just preview mode">Go Live</button>`
            : '';

        const activeDepsNote = activeDepsBlocking
            ? `<span class="twperf-deps twperf-deps--blocked">unload blocked — active scripts depend on this</span>`
            : '';

        return `
<div class="twperf-asset-item" data-handle="${esc(asset.handle)}" data-action="${esc(action)}" data-type="${esc(asset.asset_type)}" data-confidence="${esc(confidence)}">
    <div class="twperf-asset-item__info">
        <div class="twperf-asset-item__header">
            <span class="twperf-asset-item__type twperf-asset-item__type--${esc(asset.asset_type)}">${asset.asset_type}</span>
            <span class="twperf-asset-item__handle">${esc(asset.handle)}</span>
            ${info.plugin ? `<span class="twperf-asset-item__plugin">${esc(info.plugin)}</span>` : ''}
            ${asset._third_party ? '<span class="twperf-badge--3p">3rd party</span>' : ''}
            ${info.note   ? `<span class="twperf-rec-note" title="${esc(info.note)}">ℹ</span>` : ''}
        </div>
        <div class="twperf-asset-item__src" title="${esc(asset.src || '')}">${esc(src)}</div>
        <div class="twperf-asset-item__rec">
            <span class="twperf-rec-badge twperf-rec-badge--${esc(action)}">${esc(action.replace('_',' '))}</span>
            ${confidence && !isConfirmedActive ? `<span class="twperf-rec-confidence">${esc(confidence)} confidence</span>` : ''}
            ${rec.reason && !isConfirmedActive ? `<span class="twperf-rec-reason">${esc(rec.reason)}</span>` : ''}
            ${asset._recorded_events ? `<span class="twperf-recorded-events" title="Registered these events during recording">${asset._recorded_events.map(e => `<span class="twperf-event-badge">${esc(e)}</span>`).join('')}</span>` : ''}
            ${asset._companion_of ? `<span class="twperf-companion-badge" title="CSS companion of ${esc(asset._companion_of)} which was active during recording">CSS active</span>` : ''}
            ${depWarning}
            ${activeDepsNote}
        </div>
        <div class="twperf-dep-tree" id="dep-tree-${esc(asset.handle)}" style="display:none;"></div>
    </div>
    <div class="twperf-asset-item__actions">
        ${sizeKb ? `<div class="twperf-asset-item__size">${esc(sizeKb)}</div>` : ''}
        <select class="twperf-action-select" data-handle="${esc(asset.handle)}" data-asset-type="${esc(asset.asset_type)}">
            ${options}
        </select>
        <select class="twperf-context-select" data-handle="${esc(asset.handle)}" title="Where to apply this unload rule" style="display:${showCtx?'block':'none'}">
            ${ctxOptions}
        </select>
        <button class="twperf-btn-apply ${applyClass}" data-handle="${esc(asset.handle)}" ${applyExtra}>
            ${applyLabel}
        </button>
        ${goLiveBtn}
    </div>
</div>`;
    }

    // -----------------------------------------------------------------------
    // Summary pills
    // -----------------------------------------------------------------------
    function renderSummary(summary) {
        const map = {
            unload:      's-unload',
            delay:       's-delay',
            defer:       's-defer',
            investigate: 's-investigate',
            keep:        's-keep',
        };
        Object.entries(map).forEach(([key, id]) => {
            const el = $(id);
            if (el) el.textContent = `${summary[key] || 0} ${key}`;
        });
    }

    function countRecommendations(assets) {
        return assets.filter(a => !a._admin_only && !['keep','manual'].includes(a.rec?.action)).length;
    }

    // -----------------------------------------------------------------------
    // Save a single rule
    // -----------------------------------------------------------------------
    function saveRule(handle, assetType, action, btn, context = 'frontend', previewOnly = false) {
        const scope  = $('twperf-scope').value;
        const target = getTarget(scope);

        setStatus('Saving…', 'busy');

        ajax('twperf_save_rule', {
            rule_type:    scope,
            target:       target,
            asset_type:   assetType,
            handle:       handle,
            rule_action:  action,
            context:      action === 'unload' ? context : 'frontend',
            preview_only: previewOnly ? '1' : '0',
            url:          cfg.current_url,
        }).then(data => {
            if (btn) {
                btn.textContent = previewOnly ? '✓ Preview Only' : '✓ Saved';
                btn.className   = btn.className.replace(/twperf-btn-apply--\S+/g, '').trim();
                btn.classList.add(previewOnly ? 'twperf-btn-apply--preview' : 'twperf-btn-apply--saved');
                // If saved as preview-only, inject a Go Live button next to it
                if (previewOnly && !btn.nextElementSibling?.classList.contains('twperf-btn-go-live')) {
                    const glBtn = document.createElement('button');
                    glBtn.className = 'twperf-btn-go-live';
                    glBtn.dataset.handle    = handle;
                    glBtn.dataset.assetType = assetType;
                    glBtn.dataset.ruleType  = scope;
                    glBtn.dataset.target    = target;
                    glBtn.title = 'Make this rule active for all visitors, not just preview mode';
                    glBtn.textContent = 'Go Live';
                    glBtn.addEventListener('click', () => {
                        const ok = confirm(`Make "${handle}" live for all visitors (not just preview mode)?`);
                        if (!ok) return;
                        glBtn.textContent = '…'; glBtn.disabled = true;
                        goLive(handle, assetType, glBtn);
                    });
                    btn.insertAdjacentElement('afterend', glBtn);
                }
            }
            updateLocalRules(assetType, handle, action, previewOnly, scope, target);
            const purgeNote = (data.purged || []).length ? ` · Cache purged` : '';
            setStatus(`Saved: ${handle} → ${action} (${scope})${previewOnly ? ' — preview only' : ''}${purgeNote}`, 'ok');
        }).catch(err => {
            setStatus('Save failed: ' + err, 'error');
        });
    }

    function goLive(handle, assetType, btn) {
        // Use the rule_type/target stored in the button's dataset (set when rule was saved),
        // falling back to the stored preview_only_rules info, then the UI scope dropdown.
        const po     = (cfg.preview_only_rules || {})[handle];
        const scope  = btn?.dataset?.ruleType  || po?.rule_type  || $('twperf-scope').value;
        const target = btn?.dataset?.target    ?? po?.target     ?? getTarget(scope);

        ajax('twperf_go_live', {
            rule_type:  scope,
            target:     target,
            asset_type: assetType,
            handle:     handle,
            context:    'frontend',
            url:        cfg.current_url,
        }).then(data => {
            // Update local state: remove from preview_only_rules, add to current_rules
            const action = (cfg.preview_only_rules || {})[handle]?.action || 'keep';
            updateLocalRules(assetType, handle, action, false);
            // Update the Apply button to show normal saved state
            const applyBtn = btn.previousElementSibling;
            if (applyBtn?.classList.contains('twperf-btn-apply')) {
                applyBtn.textContent = '✓ Saved';
                applyBtn.className   = applyBtn.className.replace(/twperf-btn-apply--\S+/g, '').trim();
                applyBtn.classList.add('twperf-btn-apply--saved');
            }
            btn.remove();
            const purgeNote = (data.purged || []).length ? ` · Cache purged` : '';
            setStatus(`"${handle}" is now live for all visitors.${purgeNote}`, 'ok');
        }).catch(err => {
            btn.textContent = 'Go Live'; btn.disabled = false;
            setStatus('Go Live failed: ' + err, 'error');
        });
    }

    // -----------------------------------------------------------------------
    // Dep tree renderer
    // -----------------------------------------------------------------------
    function renderDepTree(tree, dependents) {
        if (!tree) return '<em>No dependency data</em>';

        const renderNode = (node, depth = 0) => {
            const indent = depth * 16;
            const info   = (handleMap[node.handle] || {});
            const rows   = [`
<div class="twperf-tree-node" style="padding-left:${indent}px">
    <span class="twperf-tree-connector">${depth > 0 ? '└─' : ''}</span>
    <span class="twperf-asset-item__handle">${esc(node.handle)}</span>
    ${info.plugin ? `<span class="twperf-asset-item__plugin">${esc(info.plugin)}</span>` : ''}
</div>`];
            (node.deps || []).forEach(dep => rows.push(renderNode(dep, depth + 1)));
            return rows.join('');
        };

        const usedByRows = (dependents || []).map(h =>
            `<div class="twperf-tree-node twperf-tree-node--dependent">
                <span style="color:var(--twp-orange)">▲ used by</span>
                <span class="twperf-asset-item__handle">${esc(h)}</span>
                ${(handleMap[h] || {}).plugin ? `<span class="twperf-asset-item__plugin">${esc(handleMap[h].plugin)}</span>` : ''}
            </div>`
        ).join('');

        return `<div class="twperf-tree-wrap">
            ${usedByRows ? `<div class="twperf-tree-section-label">Required by</div>${usedByRows}` : ''}
            <div class="twperf-tree-section-label">Depends on</div>
            ${(tree.deps || []).length ? (tree.deps || []).map(d => renderNode(d)).join('') : '<div class="twperf-tree-node" style="color:var(--twp-green)">No dependencies</div>'}
        </div>`;
    }

    // -----------------------------------------------------------------------
    // Manual cache purge
    // -----------------------------------------------------------------------
    function purgeCache() {
        setStatus('Purging cache…', 'busy');
        ajax('twperf_purge_cache', { url: cfg.current_url }).then(data => {
            const list = (data.purged || []).join(', ') || 'nothing detected';
            setStatus(`✓ Cache purged: ${list}`, 'ok');
        }).catch(err => {
            setStatus('Purge failed: ' + err, 'error');
        });
    }

    // -----------------------------------------------------------------------
    // Apply all recommendations
    // -----------------------------------------------------------------------
    function applyAllRecommendations() {
        const scope  = $('twperf-scope').value;
        const target = getTarget(scope);

        const rules = analysisResults
            .filter(a => {
                const action = a.rec?.action;
                return action && !['keep','manual','investigate'].includes(action);
            })
            .map(a => ({
                asset_type: a.asset_type,
                handle:     a.handle,
                action:     a.rec.action,
            }));

        if (!rules.length) {
            setStatus('No safe recommendations to apply.', 'ok');
            return;
        }

        setStatus(`Applying ${rules.length} rules…`, 'busy');

        ajax('twperf_save_bulk', {
            rule_type: scope,
            target:    target,
            url:       cfg.current_url,
            rules:     JSON.stringify(rules),
        }).then(data => {
            const purgeNote = (data.purged || []).length ? ` Cache purged (${data.purged.join(', ')}).` : '';
            setStatus(`✓ Applied ${data.count} rules (${scope}).${purgeNote} Reload to verify.`, 'ok');
            renderAssets();
        }).catch(err => {
            setStatus('Bulk save failed: ' + err, 'error');
        });
    }

    // -----------------------------------------------------------------------
    // PSI Analysis
    // -----------------------------------------------------------------------
    function runPSI() {
        // Switch to PSI tab
        document.querySelectorAll('.twperf-tab').forEach(t => t.classList.remove('twperf-tab--active'));
        document.querySelectorAll('.twperf-tab-pane').forEach(t => t.classList.remove('twperf-tab-pane--active'));
        document.querySelector('[data-tab="psi"]').classList.add('twperf-tab--active');
        document.querySelector('[data-pane="psi"]').classList.add('twperf-tab-pane--active');

        $('twperf-psi-results').innerHTML = `<div class="twperf-loading"><div class="twperf-spinner"></div> Running PageSpeed Insights (${psiStrategy})… this takes ~30s</div>`;
        setStatus(`Running PSI (${psiStrategy})…`, 'busy');

        ajax('twperf_run_psi', {
            url:              cfg.current_url,
            strategy:         psiStrategy,
            enqueued_scripts: JSON.stringify(cfg.enqueued_scripts || {}),
            enqueued_styles:  JSON.stringify(cfg.enqueued_styles  || {}),
        }, 90000).then(data => {
            renderPSI(data);
            setStatus(`PSI Score: ${data.score}/100 (${psiStrategy})`, 'ok');
        }).catch(err => {
            $('twperf-psi-results').innerHTML = `<div class="twperf-empty">PSI failed: ${esc(err)}<br><small>Make sure a PSI API key is set in Settings for best results.</small></div>`;
            setStatus('PSI failed', 'error');
        });
    }

    function renderPSI(data) {
        const score      = data.score || 0;
        const scoreClass = score >= 90 ? 'green' : score >= 50 ? 'amber' : 'red';
        const metrics    = data.metrics || {};
        const blocking   = data.blocking_resources || [];
        const matches    = data.handle_matches || {};
        const field      = data.field_data || null;

        const metricRows = [
            { key:'fcp', label:'FCP' },
            { key:'lcp', label:'LCP' },
            { key:'inp', label:'INP' },
            { key:'tbt', label:'TBT' },
            { key:'cls', label:'CLS' },
            { key:'si',  label:'Speed Index' },
            { key:'tti', label:'TTI' },
        ].filter(m => metrics[m.key]).map(m => {
            const s     = metrics[m.key].score;
            const cls   = s === null ? '' : s >= 0.9 ? 'green' : s >= 0.5 ? 'amber' : 'red';
            return `<div class="twperf-metric twperf-metric--${cls}">
                <div class="twperf-metric__label">${m.label}</div>
                <div class="twperf-metric__value">${esc(metrics[m.key].display)}</div>
            </div>`;
        }).join('');

        // CrUX field data block
        const catLabel = { fast: 'Good', average: 'Needs Improvement', slow: 'Poor' };
        const catCls   = { fast: 'green', average: 'amber', slow: 'red' };
        let fieldHtml = '';
        if (field) {
            const note = field.is_origin ? ' <span class="twperf-field-origin-note">(origin data — page has insufficient traffic)</span>' : '';
            const rows = (field.metrics || []).map(m => {
                const cls = catCls[m.category] || '';
                const val = m.value !== null
                    ? (m.unit === 'ms' ? Math.round(m.value) + ' ms' : Number(m.value / 100).toFixed(2))
                    : '—';
                return `<div class="twperf-field-metric twperf-field-metric--${cls}">
                    <span class="twperf-field-metric__label">${esc(m.label)}</span>
                    <span class="twperf-field-metric__value">${esc(val)}</span>
                    <span class="twperf-field-metric__cat">${catLabel[m.category] || m.category}</span>
                </div>`;
            }).join('');
            fieldHtml = `<div class="twperf-field-data">
                <div class="twperf-field-data__head">Real-User Core Web Vitals (CrUX)${note}</div>
                <div class="twperf-field-data__grid">${rows}</div>
            </div>`;
        }

        const blockingRows = blocking.map(item => {
            const filename    = item.url.split('/').pop().split('?')[0];
            const handleMatch = Object.entries(matches).find(([h, m]) => m.url === item.url);
            const handleBadge = handleMatch
                ? `<span class="twperf-blocking-item__handle">${esc(handleMatch[0])}</span>`
                : '';
            const fixBtn = handleMatch
                ? `<button class="twperf-btn twperf-btn--sm twperf-btn--primary twperf-psi-fix"
                       data-handle="${esc(handleMatch[0])}"
                       data-asset-type="${esc(handleMatch[1].asset_type || 'script')}"
                       title="Auto-apply best fix for this asset">Fix</button>`
                : '';
            return `<div class="twperf-blocking-item">
                <div class="twperf-blocking-item__url" title="${esc(item.url)}">${esc(filename)}</div>
                ${handleBadge}
                <div class="twperf-blocking-item__duration">${Math.round(item.duration)}ms</div>
                ${fixBtn}
            </div>`;
        }).join('');

        $('twperf-psi-results').innerHTML = `
<div class="twperf-psi-score">
    <div class="twperf-psi-score__circle twperf-psi-score__circle--${scoreClass}">${score}</div>
    <div>
        <div style="font-size:18px;font-weight:900;color:var(--twp-text);margin-bottom:4px;">${psiStrategy === 'mobile' ? 'Mobile' : 'Desktop'} Performance</div>
        <div style="font-size:12px;color:var(--twp-muted)">${cfg.current_url}</div>
    </div>
</div>
<div class="twperf-metrics-grid">${metricRows}</div>
${fieldHtml}
${blocking.length ? `
<div style="padding:0 0 12px;">
    <div style="font-size:12px;font-weight:700;color:var(--twp-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">
        Render-Blocking Resources
    </div>
    <div class="twperf-blocking-list">${blockingRows}</div>
</div>` : ''}`;

        // Bind PSI fix-it buttons
        document.querySelectorAll('.twperf-psi-fix').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.textContent = '…';
                btn.disabled = true;
                ajax('twperf_fix_from_psi', {
                    handle:     btn.dataset.handle,
                    asset_type: btn.dataset.assetType,
                    url:        cfg.current_url,
                    post_id:    cfg.post_id,
                }).then(data => {
                    btn.textContent = `✓ ${data.action}`;
                    btn.classList.remove('twperf-btn--primary');
                    btn.classList.add('twperf-btn--ghost');
                    setStatus(`Fixed: ${data.handle} → ${data.action}`, 'ok');
                }).catch(err => {
                    btn.textContent = 'Fix';
                    btn.disabled = false;
                    setStatus('Fix failed: ' + err, 'error');
                });
            });
        });
    }

    // -----------------------------------------------------------------------
    // Quick Wins — seed toggles from PHP state and wire save
    // -----------------------------------------------------------------------
    function initQuickWins(panel) {
        const qw = cfg.quick_wins || {};

        // Seed checkboxes from current server state
        panel.querySelectorAll('.twperf-qw-toggle').forEach(cb => {
            const keyMap = {
                twperf_remove_emoji:      qw.remove_emoji,
                twperf_clean_head:        qw.clean_head,
                twperf_fix_font_display:  qw.font_display,
                twperf_fix_lcp_attrs:     qw.lcp_attrs,
                twperf_remove_gfonts:     qw.remove_gfonts,
            };
            if (cb.dataset.key in keyMap) cb.checked = !!keyMap[cb.dataset.key];
            cb.addEventListener('change', () => saveQuickWin(cb.dataset.key, cb.checked ? '1' : '0', cb));
        });

        // Seed heartbeat select
        panel.querySelectorAll('.twperf-qw-select').forEach(sel => {
            if (sel.dataset.key === 'twperf_heartbeat') sel.value = qw.heartbeat || 'frontend';
            sel.addEventListener('change', () => saveQuickWin(sel.dataset.key, sel.value, sel));
        });
    }

    function saveQuickWin(key, value, el) {
        el.disabled = true;
        ajax('twperf_save_quick_win', { key, value })
            .then(() => setStatus('Saved — changes apply on next page load', 'ok'))
            .catch(() => setStatus('Save failed', 'error'))
            .finally(() => { el.disabled = false; });
    }

    // -----------------------------------------------------------------------
    // Tools: Conflict checker
    // -----------------------------------------------------------------------
    function runConflictCheck() {
        const result = $('twperf-conflicts-result');
        result.innerHTML = '<div class="twperf-loading"><div class="twperf-spinner"></div> Checking…</div>';
        setStatus('Checking for conflicts…', 'busy');

        ajax('twperf_conflict_check', {
            enqueued_scripts: JSON.stringify(cfg.enqueued_scripts || {}),
        }).then(data => {
            const conflicts = data.conflicts || [];
            if (!conflicts.length) {
                result.innerHTML = '<div style="padding:12px;color:var(--twp-green);font-size:12px;">✓ No conflicts detected</div>';
                setStatus('No conflicts found', 'ok');
                return;
            }
            result.innerHTML = conflicts.map(c => `
<div class="twperf-conflict-row twperf-conflict-row--${esc(c.severity)}">
    <span class="twperf-conflict-icon">${c.severity === 'error' ? '🔴' : '⚠️'}</span>
    <div>
        <span class="twperf-asset-item__handle">${esc(c.handle)}</span>
        <span class="twperf-asset-item__type twperf-asset-item__type--${esc(c.asset_type)}">${esc(c.asset_type)}</span>
        <div style="font-size:11px;color:var(--twp-muted);margin-top:3px;">${esc(c.message)}</div>
    </div>
</div>`).join('');
            setStatus(`${conflicts.length} conflict${conflicts.length !== 1 ? 's' : ''} found`, 'error');
        }).catch(err => {
            result.innerHTML = '<div class="twperf-empty">Check failed</div>';
            setStatus('Conflict check failed: ' + err, 'error');
        });
    }

    // -----------------------------------------------------------------------
    // Tools: Export rules
    // -----------------------------------------------------------------------
    function exportRules() {
        setStatus('Exporting rules…', 'busy');
        ajax('twperf_export_rules', {}).then(data => {
            const json = JSON.stringify(data, null, 2);
            $('twperf-export-output').value = json;
            $('twperf-export-output').select();

            // Also trigger download
            const blob = new Blob([json], {type: 'application/json'});
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = `twperf-rules-${new Date().toISOString().slice(0,10)}.json`;
            a.click();
            URL.revokeObjectURL(url);

            setStatus(`Exported ${(data.rules || []).length} rules`, 'ok');
        }).catch(err => setStatus('Export failed: ' + err, 'error'));
    }

    // -----------------------------------------------------------------------
    // Tools: Import rules
    // -----------------------------------------------------------------------
    function importRules() {
        const json = $('twperf-import-input').value.trim();
        const mode = $('twperf-import-mode').value;
        const result = $('twperf-import-result');

        if (!json) { result.textContent = 'Paste JSON first'; return; }

        // Validate JSON client-side first
        try { JSON.parse(json); } catch(e) {
            result.style.color = 'var(--twp-red)';
            result.textContent = 'Invalid JSON — check the pasted content';
            return;
        }

        result.textContent = 'Importing…';
        result.style.color = 'var(--twp-muted)';
        setStatus('Importing rules…', 'busy');

        ajax('twperf_import_rules', { json, mode }).then(data => {
            result.style.color = 'var(--twp-green)';
            result.textContent = `✓ Imported ${data.imported} rules from ${data.source}` +
                (data.skipped ? ` (${data.skipped} skipped)` : '');
            $('twperf-import-input').value = '';
            setStatus(`Import complete: ${data.imported} rules`, 'ok');
        }).catch(err => {
            result.style.color = 'var(--twp-red)';
            result.textContent = 'Import failed: ' + err;
            setStatus('Import failed', 'error');
        });
    }

    // -----------------------------------------------------------------------
    // Load all rules across all pages (site-wide view)
    // -----------------------------------------------------------------------
    function loadAllRules() {
        const container = $('twperf-all-rules-list');
        const counter   = $('twperf-all-rules-count');
        container.innerHTML = '<div class="twperf-loading"><div class="twperf-spinner"></div> Loading all rules…</div>';

        ajax('twperf_get_all_rules', {}).then(data => {
            const rules = data.rules || [];

            if (counter) counter.textContent = `${rules.length} rule${rules.length !== 1 ? 's' : ''} saved across all pages`;

            if (!rules.length) {
                container.innerHTML = '<div class="twperf-empty">No rules saved yet. Analyse a page and apply recommendations to get started.</div>';
                return;
            }

            // Group by target_label
            const grouped = {};
            rules.forEach(r => {
                const key = r.target_label || r.target || 'Global';
                if (!grouped[key]) grouped[key] = [];
                grouped[key].push(r);
            });

            let html = '';
            Object.entries(grouped).forEach(([target, targetRules]) => {
                html += `<div class="twperf-group-header">${esc(target)} <span style="color:var(--twp-muted);font-weight:400">(${targetRules.length})</span></div>`;
                targetRules.forEach(r => {
                    html += `
<div class="twperf-rule-row">
    <span class="twperf-rec-badge twperf-rec-badge--${esc(r.action)}">${esc(r.action)}</span>
    <span class="twperf-rule-row__handle">${esc(r.handle)}</span>
    ${r.plugin_label ? `<span class="twperf-asset-item__plugin">${esc(r.plugin_label)}</span>` : ''}
    <span class="twperf-asset-item__type twperf-asset-item__type--${esc(r.asset_type)}">${esc(r.asset_type)}</span>
    <span class="twperf-rule-row__scope">${esc(r.rule_type)}</span>
    <button class="twperf-rule-row__delete"
        data-handle="${esc(r.handle)}"
        data-asset-type="${esc(r.asset_type)}"
        data-rule-type="${esc(r.rule_type)}"
        data-target="${esc(r.target)}"
        title="Remove rule">✕</button>
</div>`;
                });
            });

            container.innerHTML = html;

            // Bind delete buttons
            container.querySelectorAll('.twperf-rule-row__delete').forEach(btn => {
                btn.addEventListener('click', () => {
                    ajax('twperf_delete_rule', {
                        handle:     btn.dataset.handle,
                        asset_type: btn.dataset.assetType,
                        rule_type:  btn.dataset.ruleType,
                        target:     btn.dataset.target,
                    }).then(() => {
                        btn.closest('.twperf-rule-row').remove();
                        setStatus(`Deleted rule: ${btn.dataset.handle}`, 'ok');
                    }).catch(err => setStatus('Delete failed: ' + err, 'error'));
                });
            });
        }).catch(() => {
            container.innerHTML = '<div class="twperf-empty">Failed to load rules.</div>';
        });
    }

    // -----------------------------------------------------------------------
    // Load saved rules for this page
    // -----------------------------------------------------------------------
    function loadRules() {
        const target = getTarget('page');
        $('twperf-rules-list').innerHTML = '<div class="twperf-loading"><div class="twperf-spinner"></div> Loading…</div>';

        ajax('twperf_get_rules', { rule_type: 'page', target }).then(data => {
            const rules = data.rules || [];
            if (!rules.length) {
                $('twperf-rules-list').innerHTML = '<div class="twperf-empty">No rules saved for this page yet.</div>';
                return;
            }

            const scopeLabels = { page: 'this page', post_type: 'post type', global: 'global' };

            $('twperf-rules-list').innerHTML = rules.map(r => {
                const scopeOptions = ['page','post_type','global']
                    .map(s => `<option value="${s}" ${r.rule_type===s?'selected':''}>${scopeLabels[s]}</option>`)
                    .join('');
                const isPreview = r.preview_only == 1; // eslint-disable-line eqeqeq
                return `
<div class="twperf-rule-row">
    <span class="twperf-rec-badge twperf-rec-badge--${esc(r.action)}">${esc(r.action)}</span>
    <span class="twperf-rule-row__handle">${esc(r.handle)}</span>
    <span class="twperf-asset-item__type twperf-asset-item__type--${esc(r.asset_type)}">${esc(r.asset_type)}</span>
    ${isPreview ? `<span class="twperf-badge--preview-only" title="Only active in Preview Mode">preview only</span>` : ''}
    <select class="twperf-rule-scope-select"
        data-handle="${esc(r.handle)}"
        data-asset-type="${esc(r.asset_type)}"
        data-action="${esc(r.action)}"
        data-old-type="${esc(r.rule_type)}"
        data-old-target="${esc(r.target || '')}"
        title="Promote rule scope">${scopeOptions}</select>
    ${isPreview ? `<button class="twperf-btn-go-live twperf-rule-go-live" data-handle="${esc(r.handle)}" data-asset-type="${esc(r.asset_type)}" data-rule-type="${esc(r.rule_type)}" data-target="${esc(r.target || '')}" title="Make active for all visitors">Go Live</button>` : ''}
    <button class="twperf-rule-row__delete" data-handle="${esc(r.handle)}" data-asset-type="${esc(r.asset_type)}"
        data-rule-type="${esc(r.rule_type)}" data-target="${esc(r.target || '')}" title="Remove rule">✕</button>
</div>`;
            }).join('');

            // Delete button
            $('twperf-rules-list').querySelectorAll('.twperf-rule-row__delete').forEach(btn => {
                btn.addEventListener('click', () => {
                    ajax('twperf_delete_rule', {
                        handle:     btn.dataset.handle,
                        asset_type: btn.dataset.assetType,
                        rule_type:  btn.dataset.ruleType,
                        target:     btn.dataset.target,
                    }).then(() => {
                        btn.closest('.twperf-rule-row').remove();
                        setStatus(`Deleted rule: ${btn.dataset.handle}`, 'ok');
                    }).catch(err => setStatus('Delete failed: ' + err, 'error'));
                });
            });

            // Go Live from rules list
            $('twperf-rules-list').querySelectorAll('.twperf-rule-go-live').forEach(btn => {
                btn.addEventListener('click', () => {
                    const handle    = btn.dataset.handle;
                    const assetType = btn.dataset.assetType;
                    const ruleType  = btn.dataset.ruleType;
                    const target    = btn.dataset.target;
                    const ok = confirm(`Make "${handle}" live for all visitors (not just preview mode)?`);
                    if (!ok) return;
                    btn.textContent = '…'; btn.disabled = true;
                    ajax('twperf_go_live', {
                        rule_type:  ruleType,
                        target:     target,
                        asset_type: assetType,
                        handle:     handle,
                        context:    'frontend',
                        url:        cfg.current_url,
                    }).then(() => {
                        const badge = btn.previousElementSibling;
                        if (badge?.classList.contains('twperf-badge--preview-only')) badge.remove();
                        btn.remove();
                        updateLocalRules(assetType, handle, (cfg.preview_only_rules || {})[handle]?.action || 'keep', false);
                        setStatus(`"${handle}" is now live for all visitors.`, 'ok');
                    }).catch(err => {
                        btn.textContent = 'Go Live'; btn.disabled = false;
                        setStatus('Go Live failed: ' + err, 'error');
                    });
                });
            });

            // Scope promotion
            $('twperf-rules-list').querySelectorAll('.twperf-rule-scope-select').forEach(sel => {
                sel.addEventListener('change', () => {
                    const newScope  = sel.value;
                    const oldType   = sel.dataset.oldType;
                    if (newScope === oldType) return;

                    const handle    = sel.dataset.handle;
                    const assetType = sel.dataset.assetType;
                    const action    = sel.dataset.action;
                    const oldTarget = sel.dataset.oldTarget;
                    const newTarget = newScope === 'global' ? '' : (newScope === 'post_type' ? (cfg.post_type || '') : getTarget('page'));

                    sel.disabled = true;

                    // Save first, then delete — sequential to avoid inconsistent state
                    // if one succeeds and the other fails
                    ajax('twperf_save_rule', {
                        rule_type:   newScope,
                        target:      newTarget,
                        asset_type:  assetType,
                        handle:      handle,
                        rule_action: action,
                        context:     'frontend',
                        url:         cfg.current_url,
                    }).then(() => ajax('twperf_delete_rule', {
                        handle:     handle,
                        asset_type: assetType,
                        rule_type:  oldType,
                        target:     oldTarget,
                    })).then(() => {
                        sel.disabled = false;
                        sel.dataset.oldType   = newScope;
                        sel.dataset.oldTarget = newTarget;
                        setStatus(`Promoted: ${handle} → ${newScope}`, 'ok');
                    }).catch(err => {
                        sel.disabled = false;
                        sel.value = oldType;
                        setStatus('Promote failed: ' + err, 'error');
                    });
                });
            });
        }).catch(() => {
            $('twperf-rules-list').innerHTML = '<div class="twperf-empty">Failed to load rules.</div>';
        });
    }

    // -----------------------------------------------------------------------
    // LCP image save
    // -----------------------------------------------------------------------
    function saveLcpImage() {
        const url = $('twperf-lcp-url').value.trim();
        if (!url) return;

        ajax('twperf_save_lcp', { post_id: cfg.post_id, lcp_url: url })
            .then(() => setStatus('LCP image preload saved.', 'ok'))
            .catch(err => setStatus('Failed: ' + err, 'error'));
    }

    // -----------------------------------------------------------------------
    // Font preloads + Preconnects
    // -----------------------------------------------------------------------
    function initCriticalCss() {
        const ta  = $('twperf-critical-css-input');
        const btn = $('twperf-save-critical-css');
        if (!ta || !btn) return;
        if (cfg.critical_css) ta.value = cfg.critical_css;
        btn.addEventListener('click', () => {
            btn.textContent = 'Saving…';
            ajax('twperf_save_critical_css', { css: ta.value })
                .then(() => { btn.textContent = 'Saved ✓'; setStatus('Critical CSS saved.', 'ok'); setTimeout(() => { btn.textContent = 'Save'; }, 2000); })
                .catch(err => { btn.textContent = 'Save'; setStatus('Failed: ' + err, 'error'); });
        });
    }

    function initPreloadsTab() {
        // Render initial lists from data passed by PHP
        renderFontPreloads(cfg.font_preloads || []);
        renderPreconnects(cfg.preconnects   || []);

        // Save font preload
        const fontBtn = $('twperf-save-font');
        fontBtn.addEventListener('click', () => {
            const url         = $('twperf-font-url').value.trim();
            const crossorigin = $('twperf-font-crossorigin').checked;
            if (!url) return;

            const err = validateFontUrl(url);
            if (err) { showInputError('twperf-font-url', err); return; }
            clearInputError('twperf-font-url');

            fontBtn.textContent = 'Checking…';
            fontBtn.disabled = true;
            ajax('twperf_save_font_preload', { url, crossorigin: crossorigin ? '1' : '0' })
                .then(d => {
                    renderFontPreloads(d.fonts || []);
                    $('twperf-font-url').value = '';
                    setStatus('Font preload saved.', 'ok');
                })
                .catch(err => { showInputError('twperf-font-url', err); setStatus('Failed: ' + err, 'error'); })
                .finally(() => { fontBtn.textContent = 'Add'; fontBtn.disabled = false; });
        });

        // Save preconnect
        const pcBtn = $('twperf-save-preconnect');
        pcBtn.addEventListener('click', () => {
            const origin      = $('twperf-preconnect-url').value.trim();
            const crossorigin = $('twperf-preconnect-crossorigin').checked;
            if (!origin) return;

            const err = validateOriginUrl(origin);
            if (err) { showInputError('twperf-preconnect-url', err); return; }
            clearInputError('twperf-preconnect-url');

            pcBtn.textContent = 'Saving…';
            pcBtn.disabled = true;
            ajax('twperf_save_preconnect', { origin, crossorigin: crossorigin ? '1' : '0' })
                .then(d => {
                    renderPreconnects(d.preconnects || []);
                    $('twperf-preconnect-url').value = '';
                    setStatus('Preconnect saved.', 'ok');
                })
                .catch(err => { showInputError('twperf-preconnect-url', err); setStatus('Failed: ' + err, 'error'); })
                .finally(() => { pcBtn.textContent = 'Add'; pcBtn.disabled = false; });
        });
    }

    function validateFontUrl(url) {
        let parsed;
        try { parsed = new URL(url); } catch(_) { return 'Invalid URL'; }
        if (!['http:','https:'].includes(parsed.protocol)) return 'URL must use https://';
        const ext = parsed.pathname.split('.').pop().toLowerCase().split('?')[0];
        if (!['woff2','woff','ttf','otf','eot'].includes(ext)) return 'Must be a font file (.woff2, .woff, .ttf, .otf)';
        return null;
    }

    function validateOriginUrl(url) {
        let parsed;
        try { parsed = new URL(url); } catch(_) { return 'Invalid URL — use https://domain.com'; }
        if (!['http:','https:'].includes(parsed.protocol)) return 'Must use http:// or https://';
        if (!parsed.hostname) return 'Missing hostname';
        return null;
    }

    function showInputError(id, msg) {
        const input = $(id);
        if (!input) return;
        input.style.borderColor = 'var(--twp-red)';
        let hint = input.parentElement.querySelector('.twperf-input-error');
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'twperf-input-error';
            input.parentElement.appendChild(hint);
        }
        hint.textContent = msg;
    }

    function clearInputError(id) {
        const input = $(id);
        if (!input) return;
        input.style.borderColor = '';
        const hint = input.parentElement.querySelector('.twperf-input-error');
        if (hint) hint.remove();
    }

    function renderFontPreloads(fonts) {
        const list = $('twperf-font-preload-list');
        if (!list) return;
        if (!fonts.length) { list.innerHTML = '<div class="twperf-preload-empty">No font preloads saved yet.</div>'; return; }
        list.innerHTML = fonts.map(f => `
            <div class="twperf-preload-item">
                <span class="twperf-preload-item__url">${esc(f.href)}</span>
                ${f.crossorigin ? '<span class="twperf-preload-item__badge">crossorigin</span>' : ''}
                <button class="twperf-btn twperf-btn--ghost twperf-btn--sm twperf-preload-del" data-url="${esc(f.href)}" data-type="font">✕</button>
            </div>`).join('');
        list.querySelectorAll('.twperf-preload-del[data-type="font"]').forEach(btn => {
            btn.addEventListener('click', () => {
                ajax('twperf_delete_font_preload', { url: btn.dataset.url })
                    .then(d => { renderFontPreloads(d.fonts || []); setStatus('Removed.', 'ok'); })
                    .catch(err => setStatus('Failed: ' + err, 'error'));
            });
        });
    }

    function renderPreconnects(items) {
        const list = $('twperf-preconnect-list');
        if (!list) return;
        if (!items.length) { list.innerHTML = '<div class="twperf-preload-empty">No preconnect origins saved yet.</div>'; return; }
        list.innerHTML = items.map(i => `
            <div class="twperf-preload-item">
                <span class="twperf-preload-item__url">${esc(i.href)}</span>
                ${i.crossorigin ? '<span class="twperf-preload-item__badge">crossorigin</span>' : ''}
                <button class="twperf-btn twperf-btn--ghost twperf-btn--sm twperf-preload-del" data-origin="${esc(i.href)}" data-type="preconnect">✕</button>
            </div>`).join('');
        list.querySelectorAll('.twperf-preload-del[data-type="preconnect"]').forEach(btn => {
            btn.addEventListener('click', () => {
                ajax('twperf_delete_preconnect', { origin: btn.dataset.origin })
                    .then(d => { renderPreconnects(d.preconnects || []); setStatus('Removed.', 'ok'); })
                    .catch(err => setStatus('Failed: ' + err, 'error'));
            });
        });
    }

    // -----------------------------------------------------------------------
    // Preview mode toggle
    // -----------------------------------------------------------------------
    function togglePreview() {
        ajax('twperf_toggle_preview', {}).then(data => {
            setStatus(data.preview ? '👁 Preview mode ON — you can now see optimisation effects' : '👁 Preview mode OFF', 'ok');
            $('twperf-preview-toggle').checked = !!data.preview;
        });
    }

    // -----------------------------------------------------------------------
    // Interaction recorder
    // -----------------------------------------------------------------------
    let _recordingActive   = false;
    let _recordCountTimer  = null;
    let _navInterceptBound = false;

    function startRecording() {
        if (_recordingActive) return;

        if (!window.__twperfListeners) {
            setStatus('Listener patch not found — reload the page first', 'error');
            return;
        }

        _recordingActive = true;

        // --- XHR/fetch monitoring during the recording window ---
        // Captures which /wp-content/ scripts make network requests when user interacts
        // (e.g. WooSQ quick view fires an AJAX call — this catches it even without addEventListener)
        window.__twperfXHR = {};
        const _stackPaths = () => {
            const s = (new Error).stack || '';
            return [...s.matchAll(/(?:https?:\/\/[^/]+)(\/wp-content\/[^\s:)?\n]+)/g)]
                .map(m => m[1].split('?')[0]);
        };
        const _origXHROpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function() {
            try { _stackPaths().forEach(p => { if (!window.__twperfXHR[p]) window.__twperfXHR[p] = {}; window.__twperfXHR[p].xhr = 1; }); } catch(e) {}
            return _origXHROpen.apply(this, arguments);
        };
        window.__twperfXHRPatch = _origXHROpen;
        const _origFetch = window.fetch;
        if (_origFetch) {
            window.fetch = function() {
                try { _stackPaths().forEach(p => { if (!window.__twperfXHR[p]) window.__twperfXHR[p] = {}; window.__twperfXHR[p].fetch = 1; }); } catch(e) {}
                return _origFetch.apply(this, arguments);
            };
            window.__twperfFetchPatch = _origFetch;
        }

        // Close panel so user can interact with the page freely
        closePanel();

        const bar = $('twperf-record-bar');
        const btn = $('twperf-record-btn');
        if (bar) bar.style.display = 'flex';
        if (btn) { btn.textContent = 'Recording…'; btn.disabled = true; }

        // Block navigation
        window.addEventListener('beforeunload', _beforeUnloadHandler);
        document.addEventListener('click', _navClickHandler, true);
        _navInterceptBound = true;

        // Live counter
        _recordCountTimer = setInterval(() => {
            const total = _countCaptured();
            const el = $('twperf-record-count');
            if (el) el.textContent = total + ' signal' + (total !== 1 ? 's' : '') + ' captured';
        }, 500);

        setStatus('Recording active — interact with the page, then click Stop & Analyse', 'busy');
    }

    function stopRecording() {
        if (!_recordingActive) return;
        _recordingActive = false;

        // Read XHR/fetch data captured during recording BEFORE cleanup clears it
        const xhrData = window.__twperfXHR || {};
        _cleanupRecording();

        // Merge: listener patch (page-load addEventListener) + XHR/fetch (during-interaction requests)
        const all = Object.assign({}, window.__twperfListeners || {});
        Object.entries(xhrData).forEach(([path, events]) => {
            if (!all[path]) all[path] = {};
            Object.assign(all[path], events);
        });

        if (!Object.keys(all).length) {
            setStatus('No signals captured — check the page reloaded with the patch active', 'ok');
            return;
        }

        // Correlate src paths → handles
        const byHandle = _correlateListeners(all);

        // Annotate analysis results with recorded events
        analysisResults.forEach(a => {
            if (byHandle[a.handle]) a._recorded_events = byHandle[a.handle];
        });

        // Find companion CSS for each detected JS handle and mark them as active.
        // Matches on shared handle base: slick-slider (JS) → slick-slider (CSS), slick-theme, etc.
        const detectedHandles = new Set(Object.keys(byHandle));
        analysisResults.forEach(a => {
            if (a.asset_type !== 'style' || a._recorded_events || a._companion_of) return;
            const styleBase = a.handle.replace(/[-_]?(css|styles?|theme)$/i, '');
            detectedHandles.forEach(jsHandle => {
                const jsBase = jsHandle.replace(/[-_]?js$/i, '');
                if (
                    jsBase === styleBase ||
                    jsHandle === styleBase ||
                    styleBase.startsWith(jsBase) ||
                    jsBase.startsWith(styleBase)
                ) {
                    a._companion_of = jsHandle;
                }
            });
        });

        // Reopen panel, switch to Assets tab and re-render
        openPanel();
        document.querySelectorAll('.twperf-tab').forEach(t => t.classList.remove('twperf-tab--active'));
        document.querySelectorAll('.twperf-tab-pane').forEach(t => t.classList.remove('twperf-tab-pane--active'));
        document.querySelector('[data-tab="assets"]').classList.add('twperf-tab--active');
        document.querySelector('[data-pane="assets"]').classList.add('twperf-tab-pane--active');

        renderAssets();

        const total = Object.keys(byHandle).length;
        setStatus(`Recording complete — ${total} script${total !== 1 ? 's' : ''} detected as active`, 'ok');
    }

    function cancelRecording() {
        _recordingActive = false;
        _cleanupRecording();
        openPanel();
        setStatus('Recording cancelled', 'ok');
    }

    function _cleanupRecording() {
        clearInterval(_recordCountTimer);
        if (_navInterceptBound) {
            window.removeEventListener('beforeunload', _beforeUnloadHandler);
            document.removeEventListener('click', _navClickHandler, true);
            _navInterceptBound = false;
        }
        // Restore XHR/fetch patches
        if (window.__twperfXHRPatch) {
            XMLHttpRequest.prototype.open = window.__twperfXHRPatch;
            delete window.__twperfXHRPatch;
        }
        if (window.__twperfFetchPatch) {
            window.fetch = window.__twperfFetchPatch;
            delete window.__twperfFetchPatch;
        }
        delete window.__twperfXHR;

        const bar = $('twperf-record-bar');
        const btn = $('twperf-record-btn');
        if (bar) bar.style.display = 'none';
        if (btn) { btn.textContent = 'Record'; btn.disabled = false; }
    }

    function _beforeUnloadHandler(e) {
        e.preventDefault();
        e.returnValue = 'Recording in progress — stop recording before leaving.';
        return e.returnValue;
    }

    function _navClickHandler(e) {
        const a = e.target.closest('a[href]');
        if (!a) return;
        const href = a.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript')) return;
        // Allow admin bar clicks
        if (a.closest('#wpadminbar')) return;
        e.preventDefault();
        e.stopPropagation();
        setStatus('Navigation blocked — stop recording first before leaving the page', 'error');
    }

    function _countCaptured() {
        // Unique script paths captured (listener patch + XHR/fetch during recording)
        const listeners = window.__twperfListeners || {};
        const xhr       = window.__twperfXHR       || {};
        return new Set([...Object.keys(listeners), ...Object.keys(xhr)]).size;
    }

    function _correlateListeners(all) {
        // all = { '/wp-content/plugins/foo/js/bar.js': { scroll:1, resize:1 }, … }
        // Map to handles using enqueued_scripts/styles src URLs + DOM script tags
        const byHandle = {};

        const allAssets = Object.assign({}, cfg.enqueued_scripts || {}, cfg.enqueued_styles || {});
        Object.entries(allAssets).forEach(([handle, data]) => {
            if (!data.src) return;
            try {
                const path = new URL(data.src, location.href).pathname.split('?')[0];
                if (all[path]) byHandle[handle] = Object.keys(all[path]);
            } catch(_) {}
        });

        // Also scan DOM script tags for handles not in enqueued data
        document.querySelectorAll('script[src][id]').forEach(el => {
            const handle = el.id.replace(/-js$/, '').replace(/-js-\d+$/, '');
            if (byHandle[handle]) return;
            try {
                const path = new URL(el.src, location.href).pathname.split('?')[0];
                if (all[path]) byHandle[handle] = Object.keys(all[path]);
            } catch(_) {}
        });

        return byHandle;
    }

    // -----------------------------------------------------------------------
    // Third-party origin preconnect suggestions
    // -----------------------------------------------------------------------
    function suggestThirdPartyPreconnects(assets) {
        const container = $('twperf-3p-suggestions');
        if (!container) return;

        // Collect unique origins of third-party assets
        const origins = new Set();
        assets.forEach(a => {
            if (!a._third_party || !a.src) return;
            try {
                origins.add(new URL(a.src, location.href).origin);
            } catch(_) {}
        });

        // Also scan DOM for external scripts/styles not in analysisResults
        document.querySelectorAll('script[src], link[rel="stylesheet"]').forEach(el => {
            const src = el.src || el.href;
            if (!src) return;
            try {
                const o = new URL(src, location.href).origin;
                if (o !== location.origin) origins.add(o);
            } catch(_) {}
        });

        if (!origins.size) { container.style.display = 'none'; return; }

        // Filter out already-saved preconnects
        const saved = new Set((cfg.preconnects || []).map(p => p.href));
        const newOrigins = [...origins].filter(o => !saved.has(o));

        if (!newOrigins.length) { container.style.display = 'none'; return; }

        container.style.display = 'block';
        container.innerHTML = `
<div class="twperf-tool-card">
    <div class="twperf-tool-card__head">
        <span class="twperf-tool-card__icon">🔗</span>
        <div>
            <div class="twperf-tool-card__title">Detected Third-Party Origins</div>
            <div class="twperf-tool-card__desc">Add preconnect hints to reduce connection setup time for these external origins</div>
        </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;">
        ${newOrigins.map(o => `
        <div class="twperf-3p-origin-row">
            <span class="twperf-3p-origin-url">${esc(o)}</span>
            <button class="twperf-btn twperf-btn--sm twperf-btn--ghost twperf-3p-preconnect-btn"
                data-origin="${esc(o)}">+ Preconnect</button>
        </div>`).join('')}
    </div>
</div>`;

        container.querySelectorAll('.twperf-3p-preconnect-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const origin = btn.dataset.origin;
                const crossorigin = origin.includes('fonts.gstatic.com') || origin.includes('cdn.') ? '1' : '0';
                btn.disabled = true;
                btn.textContent = '…';
                ajax('twperf_save_preconnect', { origin, crossorigin }).then(d => {
                    renderPreconnects(d.preconnects || []);
                    btn.textContent = '✓ Added';
                    cfg.preconnects = d.preconnects || [];
                    setStatus(`Preconnect added: ${origin}`, 'ok');
                }).catch(err => {
                    btn.disabled = false;
                    btn.textContent = '+ Preconnect';
                    setStatus('Failed: ' + err, 'error');
                });
            });
        });
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------
    function getTarget(scope) {
        if (scope === 'global')    return '';
        if (scope === 'post_type') return cfg.post_type || '';
        // page scope — use post_id if available
        return cfg.post_id ? 'post_' + cfg.post_id : '';
    }

    function getSavedAction(handle) {
        const keyToAction = {
            unload_js:  'unload',
            unload_css: 'unload',
            defer:      'defer',
            delay:      'delay',
            async_css:  'async_css',
            preload:    'preload',
        };
        const rules = cfg.current_rules || {};
        for (const [type, handles] of Object.entries(rules)) {
            if (Array.isArray(handles) && handles.includes(handle)) {
                return keyToAction[type] ?? type;
            }
        }
        // Also check preview-only saved rules
        const po = (cfg.preview_only_rules || {})[handle];
        return po ? po.action : null;
    }

    function isPreviewOnly(handle) {
        return !!(cfg.preview_only_rules || {})[handle];
    }

    function updateLocalRules(assetType, handle, action, previewOnly = false, ruleType = null, target = null) {
        if (previewOnly) {
            cfg.preview_only_rules = cfg.preview_only_rules || {};
            cfg.preview_only_rules[handle] = {
                action, asset_type: assetType,
                rule_type: ruleType, target,
            };
            return;
        }
        // Remove from preview_only if promoting to live
        if (cfg.preview_only_rules) delete cfg.preview_only_rules[handle];
        cfg.current_rules = cfg.current_rules || {};
        // Remove handle from all buckets to avoid stale state when action changes
        for (const bucket of Object.values(cfg.current_rules)) {
            if (Array.isArray(bucket)) {
                const idx = bucket.indexOf(handle);
                if (idx !== -1) bucket.splice(idx, 1);
            }
        }
        const key = action === 'unload' ? (assetType === 'script' ? 'unload_js' : 'unload_css') : action;
        cfg.current_rules[key] = cfg.current_rules[key] || [];
        if (!cfg.current_rules[key].includes(handle)) {
            cfg.current_rules[key].push(handle);
        }
    }

    function showLoading() {
        assetList().innerHTML = '<div class="twperf-loading"><div class="twperf-spinner"></div> Analysing assets…</div>';
    }

    function setStatus(msg, type) {
        const el = statusBar();
        if (!el) return;
        el.className = 'twperf-panel__status twperf-status--' + (type || '');
        el.textContent = '';
        if (type === 'busy') {
            const spinner = document.createElement('div');
            spinner.className = 'twperf-spinner';
            el.appendChild(spinner);
        }
        el.appendChild(document.createTextNode(msg));
    }

    function esc(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function ajax(action, data = {}, timeout = 30000) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce',  nonce);
        Object.entries(data).forEach(([k, v]) => body.append(k, v));

        return fetch(ajaxUrl, {
            method: 'POST',
            body,
            signal: AbortSignal.timeout(timeout),
        })
        .then(r => r.json())
        .then(r => {
            if (!r.success) throw r.data || 'Server error';
            return r.data;
        });
    }

})();
