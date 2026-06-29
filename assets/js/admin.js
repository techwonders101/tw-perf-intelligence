/**
 * TW Perf Intelligence — Admin JS
 * Handles: settings page PSI key test, all-rules page bulk actions,
 * live feedback on settings saves.
 */
(function () {
    'use strict';

    const ajaxUrl = window.twperfAdmin?.ajax_url || '';
    const nonce   = window.twperfAdmin?.nonce    || '';

    function doAjax(action, data) {
        if (!ajaxUrl || !nonce) {
            return Promise.reject('Admin AJAX config is missing. Reload the page and try again.');
        }

        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        return fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(r => { if (!r.success) throw r.data || 'Error'; return r.data; });
    }

    // -----------------------------------------------------------------------
    // Settings page: PSI key tester
    // -----------------------------------------------------------------------
    function initSettingsPage() {
        const testBtn    = document.getElementById('twperf-test-psi-key');
        const testResult = document.getElementById('twperf-psi-key-result');

        if (testBtn && testResult) {
            testBtn.addEventListener('click', () => {
                const key = document.querySelector('input[name="twperf_psi_api_key"]')?.value?.trim();
                if (!key) {
                    testResult.textContent = 'Enter an API key first';
                    testResult.style.color = '#f59e0b';
                    return;
                }

                testBtn.disabled    = true;
                testBtn.textContent = 'Testing...';
                testResult.textContent = '';

                const testUrl = encodeURIComponent(window.location.origin);
                fetch(`https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=${testUrl}&strategy=mobile&key=${encodeURIComponent(key)}&category=performance`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            testResult.innerHTML = `<span style="color:#ef4444">✗ ${data.error.message}</span>`;
                        } else {
                            const score = Math.round((data.lighthouseResult?.categories?.performance?.score || 0) * 100);
                            testResult.innerHTML = `<span style="color:#22c55e">✓ Key valid — got score ${score}/100 for this origin</span>`;
                        }
                    })
                    .catch(() => {
                        testResult.innerHTML = '<span style="color:#ef4444">✗ Network error — check your connection</span>';
                    })
                    .finally(() => {
                        testBtn.disabled    = false;
                        testBtn.textContent = 'Test API Key';
                    });
            });
        }

        initLocalGoogleFonts();

        // Submitting settings: brief visual feedback
        const form = document.querySelector('form[action="options.php"]');
        if (form) {
            form.addEventListener('submit', () => {
                const btn = form.querySelector('[type="submit"]');
                if (btn) { btn.value = 'Saving…'; btn.disabled = true; }
            });
        }
    }

    function initLocalGoogleFonts() {
        const scanBtn = document.getElementById('twperf-scan-local-gfonts');
        if (!scanBtn) return;

        const purgeBtn = document.getElementById('twperf-purge-local-gfonts');
        const result   = document.getElementById('twperf-local-gfonts-result');

        scanBtn.addEventListener('click', () => {
            scanBtn.disabled = true;
            if (purgeBtn) purgeBtn.disabled = true;
            scanBtn.textContent = 'Scanning...';
            setInlineStatus(result, 'Fetching pages and Google font files...', '');

            doAjax('twperf_scan_local_gfonts', {})
                .then(data => {
                    updateLocalGoogleFontsStatus(data);
                    const message = `Cached ${data.cached_stylesheets || 0} stylesheet${(data.cached_stylesheets || 0) === 1 ? '' : 's'} and ${data.cached_fonts || 0} font file${(data.cached_fonts || 0) === 1 ? '' : 's'}.`;
                    setInlineStatus(result, data.errors?.length ? `${message} ${data.errors.length} warning${data.errors.length === 1 ? '' : 's'}.` : message, 'ok');
                })
                .catch(error => {
                    setInlineStatus(result, ajaxErrorMessage(error), 'bad');
                })
                .finally(() => {
                    scanBtn.disabled = false;
                    if (purgeBtn) purgeBtn.disabled = false;
                    scanBtn.textContent = 'Scan & Cache Google Fonts';
                });
        });

        if (purgeBtn) {
            purgeBtn.addEventListener('click', () => {
                purgeBtn.disabled = true;
                scanBtn.disabled = true;
                purgeBtn.textContent = 'Clearing...';
                setInlineStatus(result, '', '');

                doAjax('twperf_purge_local_gfonts', {})
                    .then(data => {
                        updateLocalGoogleFontsStatus(data);
                        setInlineStatus(result, `Removed ${data.removed || 0} cached file${(data.removed || 0) === 1 ? '' : 's'}.`, 'ok');
                    })
                    .catch(error => {
                        setInlineStatus(result, ajaxErrorMessage(error), 'bad');
                    })
                    .finally(() => {
                        purgeBtn.disabled = false;
                        scanBtn.disabled = false;
                        purgeBtn.textContent = 'Clear Cache';
                    });
            });
        }
    }

    function updateLocalGoogleFontsStatus(data) {
        const stylesheets = document.querySelector('[data-twperf-local-gfonts="stylesheets"]');
        const fonts       = document.querySelector('[data-twperf-local-gfonts="fonts"]');
        const updated     = document.querySelector('[data-twperf-local-gfonts="updated"]');
        const names       = document.querySelector('[data-twperf-local-gfonts="names"]');

        if (stylesheets) stylesheets.textContent = String(data.cached_stylesheets || 0);
        if (fonts) fonts.textContent = String(data.cached_fonts || 0);
        if (updated && data.updated_at_label) updated.textContent = data.updated_at_label;
        if (names) {
            names.textContent = Array.isArray(data.font_names) && data.font_names.length
                ? data.font_names.join(', ')
                : 'None yet';
        }
    }

    function setInlineStatus(element, message, state) {
        if (!element) return;
        element.textContent = message;
        element.classList.toggle('twperf-inline-status--ok', state === 'ok');
        element.classList.toggle('twperf-inline-status--bad', state === 'bad');
    }

    function ajaxErrorMessage(error) {
        if (typeof error === 'string') return error;
        if (error?.errors?.length) return error.errors.join(' ');
        if (error?.message) return error.message;
        return 'Request failed.';
    }

    // -----------------------------------------------------------------------
    // All-rules admin page
    // -----------------------------------------------------------------------
    function initAllRulesPage() {
        if (!document.querySelector('.twperf-rules-admin')) return;

        // Select-all
        const selectAll = document.getElementById('twperf-select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('input[name="rule_ids[]"]')
                    .forEach(cb => { cb.checked = this.checked; });
                updateBulkCount();
            });
        }

        // Group-level selects
        document.querySelectorAll('.twperf-group-select').forEach(cb => {
            cb.addEventListener('change', function () {
                this.closest('table')
                    .querySelectorAll('input[name="rule_ids[]"]')
                    .forEach(r => { r.checked = this.checked; });
                updateBulkCount();
            });
        });

        // Individual row checkboxes → update count
        document.querySelectorAll('input[name="rule_ids[]"]').forEach(cb => {
            cb.addEventListener('change', updateBulkCount);
        });

        function updateBulkCount() {
            const n = document.querySelectorAll('input[name="rule_ids[]"]:checked').length;
            document.querySelectorAll('.twperf-bulk-count').forEach(el => {
                el.textContent = n > 0 ? ` (${n} selected)` : '';
            });
        }

        // Inline purge-all button
        const purgeAllBtn = document.getElementById('twperf-admin-purge-all');
        if (purgeAllBtn) {
            purgeAllBtn.addEventListener('click', e => {
                e.preventDefault();
                purgeAllBtn.textContent = 'Purging…';
                purgeAllBtn.disabled    = true;

                doAjax('twperf_purge_cache', {})
                    .then(data => {
                        const purged = (data.purged || []).join(', ') || 'nothing detected';
                        purgeAllBtn.textContent = `✓ Purged: ${purged}`;
                        setTimeout(() => {
                            purgeAllBtn.textContent = '🗑 Purge All Caches';
                            purgeAllBtn.disabled    = false;
                        }, 4000);
                    })
                    .catch(() => {
                        purgeAllBtn.textContent = 'Error — try again';
                        purgeAllBtn.disabled    = false;
                    });
            });
        }

        // Bulk delete form confirmation
        const bulkForm = document.querySelector('form[method="post"]');
        if (bulkForm) {
            bulkForm.addEventListener('submit', e => {
                const checked = bulkForm.querySelectorAll('input[name="rule_ids[]"]:checked');
                if (checked.length === 0) {
                    e.preventDefault();
                    showNotice('Select at least one rule to delete.', 'warning');
                    return;
                }
                if (!confirm(`Delete ${checked.length} rule${checked.length !== 1 ? 's' : ''}? This cannot be undone.`)) {
                    e.preventDefault();
                }
            });
        }
    }

    // -----------------------------------------------------------------------
    // Inline WP-style notice helper
    // -----------------------------------------------------------------------
    function showNotice(message, type = 'info') {
        const wrap  = document.querySelector('.wrap');
        if (!wrap) return;
        const div   = document.createElement('div');
        div.className = `notice notice-${type} is-dismissible`;
        div.innerHTML = `<p>${message}</p>`;
        wrap.insertBefore(div, wrap.children[1]);
        setTimeout(() => div.remove(), 4000);
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        initSettingsPage();
        initAllRulesPage();
    });
})();
