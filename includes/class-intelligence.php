<?php
defined('ABSPATH') || exit;

class TW_Perf_Intelligence {

    // -------------------------------------------------------------------------
    // DOM signatures — CSS selectors that confirm a library is actually used
    // -------------------------------------------------------------------------
    public static function get_signatures(): array {
        return [
            // Sliders
            'slick'                    => ['.slick-slider', '.slick-initialized', '[data-slick]'],
            'slick-css'                => ['.slick-slider', '.slick-initialized'],
            'swiper-bundle'            => ['.swiper', '.swiper-container', '.swiper-wrapper'],
            'swiper-bundle-css'        => ['.swiper', '.swiper-container'],

            // Animation / scroll
            'aos'                      => ['[data-aos]'],
            'aoslight'                 => ['[data-aos]'],
            'gsap'                     => ['[data-gsap]'],

            // Filtering / masonry
            'isotope'                  => ['.isotope', '[data-isotope]', '.isotope-container'],
            'isotope-pkgd'             => ['.isotope', '[data-isotope]'],
            'imagesloaded'             => ['.imagesloaded', '[data-imagesloaded]'],

            // Image galleries
            'magnific-popup'           => ['.mfp-content', '[data-mfp-src]', '.magnific-popup'],
            'magnific-popup-css'       => ['.mfp-content', '[data-mfp-src]'],
            'fancybox'                 => ['[data-fancybox]', '.fancybox'],
            'fancybox3'                => ['[data-fancybox]', '.fancybox'],
            'lightgallery'             => ['[data-lg-size]', '.lg-container'],
            'image-gallery'            => ['.image-gallery', '[data-gallery]', '.gallery-item', '.wp-block-gallery'],
            'image-gallery-js'         => ['.image-gallery', '[data-gallery]', '.gallery-item', '.wp-block-gallery'],
            'envira-gallery'           => ['.envira-gallery-wrap', '[data-envira-id]'],
            'modula-gallery'           => ['.modula', '.modula-item'],
            'justified-gallery'        => ['.justified-gallery', '[data-justifiedgallery]'],
            'nextgen-gallery'          => ['.ngg-gallery-container', '.ngg-galleryoverview'],
            'meow-gallery'             => ['.mgl-gallery', '.meow-gallery'],

            // Forms
            'contact-form-7'           => ['.wpcf7', '.wpcf7-form'],
            'wpcf7'                    => ['.wpcf7', '.wpcf7-form'],
            'wpforms'                  => ['.wpforms-form', '.wpforms-container'],
            'wpforms-css'              => ['.wpforms-form'],
            'gravityforms'             => ['.gform_wrapper', '.gform_body'],
            'gform_css'                => ['.gform_wrapper'],

            // WooCommerce
            'woocommerce'              => ['.woocommerce', '.wc-block-grid'],
            'woocommerce-layout'       => ['.woocommerce'],
            'woocommerce-smallscreen'  => ['.woocommerce'],
            'wc-add-to-cart'           => ['.add_to_cart_button', '.single_add_to_cart_button'],
            'wc-cart-fragments'        => ['.cart-contents', '.wc-block-mini-cart'],
            'wc-checkout'              => ['.woocommerce-checkout', '.wc-block-checkout'],

            // Page builders
            'elementor-frontend'       => ['.elementor-widget', '.elementor-section', '.elementor'],
            'elementor-frontend-css'   => ['.elementor'],
            'uagb-style'               => ['[class*="uagb-"]'],
            'uagb-js'                  => ['[class*="uagb-"]'],

            // PSEC / theme-specific scripts (from network dependency tree)
            'greenpanel'               => ['.green-panel', '[data-greenpanel]', '.gp-panel'],
            'greensyncpanels'          => ['.sync-panel', '[data-sync]', '.gsp-container'],
            'greentooltip'             => ['[data-tooltip]', '[data-greentooltip]', '.gtooltip'],
            'sticky-header'            => ['header.sticky', '.sticky-header', '[data-sticky]', 'header.stuck'],
            'menu'                     => ['nav.primary-menu', '.main-navigation', '#site-navigation'],

            // Events
            'tribe-events-calendar'    => ['.tribe-events', '.tribe-block'],
            'tribe-common'             => ['.tribe-common'],

            // Tables
            'datatables'               => ['table.dataTable', '.dataTables_wrapper'],

            // Maps
            'google-maps'              => ['.gmap', '[data-map]', '#map', '.acf-map'],

            // Popups / modals
            'jquery-modal'             => ['[data-modal]', '.modal-overlay'],
            'sweetalert'               => ['.swal2-container', '[data-swal]'],

            // Countdown / timers
            'countdown'                => ['.countdown', '[data-countdown]'],

            // Cookie consent
            'cookieyes'                => ['.cky-consent-container', '.cky-overlay'],
            'cookiebot'                => ['#CybotCookiebotDialog', '.CybotCookiebotDialogBodyButton'],
            'cookie-notice'            => ['#cookie-notice', '.cookie-notice-container'],

            // Social / sharing
            'addtoany'                 => ['.a2a_kit', '.addtoany_share'],
            'sharethis'                => ['.sharethis-inline-share-buttons', '.st-btn'],

            // SEO
            'rank-math'                => ['.rank-math-breadcrumb', '.rank-math-faq'],
            'yoast-seo'                => ['.yoast-breadcrumbs', '.yoast-schema-graph'],

            // Accessibility
            'skip-link'                => ['.skip-link', '[href="#main-content"]'],

            // Scroll / parallax
            'parallax'                 => ['[data-parallax]', '.parallax-section'],
            'scrollmagic'              => ['[data-scrollmagic]'],

            // Video
            'wp-mediaelement'          => ['.mejs-container', '.wp-video'],
            'fitvids'                  => ['.fluid-width-video-wrapper', '[data-fitvids]'],

            // jQuery — no DOM sig but never auto-unload
            'jquery'                   => [],
            'jquery-core'              => [],
            'jquery-migrate'           => [],
            'jquery-ui-core'           => [],
        ];
    }

    // -------------------------------------------------------------------------
    // Pattern-based handle matching — for plugins that generate dynamic handles
    // e.g. uag-css-557, gs-style-1234, elementor-post-123
    // Each entry: regex => [ 'info' => [...], 'sigs' => [...] ]
    // -------------------------------------------------------------------------
    public static function get_handle_patterns(): array {
        return [
            // Spectra / Ultimate Addons for Gutenberg — dynamic per-page CSS/JS
            '/^uag-css-\d+$/'         => ['info' => ['plugin' => 'Spectra (UAGB) CSS', 'category' => 'builder'], 'sigs' => ['[class*="uagb-"]', '[class*="wp-block-uagb-"]']],
            '/^uag-js-\d+$/'          => ['info' => ['plugin' => 'Spectra (UAGB) JS',  'category' => 'builder'], 'sigs' => ['[class*="uagb-"]', '[class*="wp-block-uagb-"]']],
            '/^uagb-style-\d+$/'      => ['info' => ['plugin' => 'Spectra (UAGB) CSS', 'category' => 'builder'], 'sigs' => ['[class*="uagb-"]']],
            '/^uagb-js-\d+$/'         => ['info' => ['plugin' => 'Spectra (UAGB) JS',  'category' => 'builder'], 'sigs' => ['[class*="uagb-"]']],

            // Greenshift — dynamic per-page and block CSS/JS
            '/^gs-style-\d+$/'        => ['info' => ['plugin' => 'Greenshift CSS',     'category' => 'builder'], 'sigs' => ['[class*="gs-"]', '[class*="greenshift"]', '[class*="wp-block-greenshift"]']],
            '/^gs-script-\d+$/'       => ['info' => ['plugin' => 'Greenshift JS',      'category' => 'builder'], 'sigs' => ['[class*="gs-"]', '[class*="greenshift"]']],
            '/^greenshift-animation/' => ['info' => ['plugin' => 'Greenshift Animate', 'category' => 'animation'], 'sigs' => ['[class*="gs-"]', '[data-gsap]']],

            // Elementor — per-post CSS
            '/^elementor-post-\d+$/'  => ['info' => ['plugin' => 'Elementor Post CSS', 'category' => 'builder'], 'sigs' => ['.elementor', '.elementor-section', '.elementor-widget']],
            '/^elementor-global-/'    => ['info' => ['plugin' => 'Elementor Global',   'category' => 'builder'], 'sigs' => ['.elementor']],

            // Divi builder dynamic CSS
            '/^et-dynamic-/'          => ['info' => ['plugin' => 'Divi Dynamic CSS',   'category' => 'builder'], 'sigs' => ['.et_pb_section', '.et_pb_row']],

            // Beaver Builder
            '/^fl-builder-layout-/'   => ['info' => ['plugin' => 'Beaver Builder CSS', 'category' => 'builder'], 'sigs' => ['.fl-builder-content', '.fl-row']],

            // WooCommerce block styles
            '/^wc-blocks-style-/'     => ['info' => ['plugin' => 'WooCommerce Blocks', 'category' => 'ecommerce'], 'sigs' => ['.wp-block-woocommerce-', '.wc-block-']],
            '/^wc-blocks-/'           => ['info' => ['plugin' => 'WooCommerce Blocks', 'category' => 'ecommerce'], 'sigs' => ['.wc-block-', '.wp-block-woocommerce-']],

            // Generic page-builder inline CSS patterns
            '/^wp-block-.*-style$/'   => ['info' => ['plugin' => 'Block CSS',          'category' => 'builder'], 'sigs' => []],
        ];
    }

    // -------------------------------------------------------------------------
    // Resolve a handle to its info + signatures, with pattern-matching fallback
    // -------------------------------------------------------------------------
    public static function resolve_handle(string $handle): array {
        $map  = self::get_handle_map();
        $sigs = self::get_signatures();

        // Exact match first
        if (isset($map[$handle])) {
            return [
                'info' => $map[$handle],
                'sigs' => $sigs[$handle] ?? [],
            ];
        }

        // Pattern match
        foreach (self::get_handle_patterns() as $pattern => $data) {
            if (preg_match($pattern, $handle)) {
                return $data;
            }
        }

        return ['info' => [], 'sigs' => []];
    }

    // -------------------------------------------------------------------------
    // Handle map — maps WP handles to human-readable info
    // -------------------------------------------------------------------------
    public static function get_handle_map(): array {
        return [
            // jQuery ecosystem
            'jquery'                   => ['plugin' => 'jQuery Core',              'category' => 'core',       'note' => 'Required by many plugins — never unload until all dependents removed'],
            'jquery-core'              => ['plugin' => 'jQuery Core',              'category' => 'core'],
            'jquery-migrate'           => ['plugin' => 'jQuery Migrate',           'category' => 'core',       'note' => 'Only needed for deprecated jQuery API usage'],
            'jquery-ui-core'           => ['plugin' => 'jQuery UI Core',           'category' => 'ui'],
            'jquery-ui-datepicker'     => ['plugin' => 'jQuery UI Datepicker',     'category' => 'ui'],
            'jquery-ui-sortable'       => ['plugin' => 'jQuery UI Sortable',       'category' => 'ui'],
            'jquery-ui-draggable'      => ['plugin' => 'jQuery UI Draggable',      'category' => 'ui'],
            'jquery-ui-dialog'         => ['plugin' => 'jQuery UI Dialog',         'category' => 'ui'],

            // Sliders — multiple handle naming conventions across themes/plugins
            'slick'                    => ['plugin' => 'Slick Slider',             'category' => 'slider',     'note' => 'jQuery dependent'],
            'slick-css'                => ['plugin' => 'Slick Slider CSS',         'category' => 'slider'],
            'slick-min'                => ['plugin' => 'Slick Slider',             'category' => 'slider'],
            'slick-theme'              => ['plugin' => 'Slick Slider Theme CSS',   'category' => 'slider'],
            'swiper-bundle'            => ['plugin' => 'Swiper',                   'category' => 'slider',     'note' => 'Vanilla JS — no jQuery needed'],
            'swiper-bundle-css'        => ['plugin' => 'Swiper CSS',               'category' => 'slider'],
            'swiper-bundle-min'        => ['plugin' => 'Swiper CSS',               'category' => 'slider'],
            'swiper'                   => ['plugin' => 'Swiper',                   'category' => 'slider'],
            'swiper-css'               => ['plugin' => 'Swiper CSS',               'category' => 'slider'],

            // Animation
            'aos'                      => ['plugin' => 'AOS (Animate on Scroll)',  'category' => 'animation'],
            'aoslight'                 => ['plugin' => 'AOS Light',                'category' => 'animation'],
            'gsap'                     => ['plugin' => 'GSAP',                     'category' => 'animation'],

            // Filtering
            'isotope'                  => ['plugin' => 'Isotope',                  'category' => 'filtering'],
            'isotope-pkgd'             => ['plugin' => 'Isotope',                  'category' => 'filtering'],
            'imagesloaded'             => ['plugin' => 'imagesLoaded',             'category' => 'utility'],

            // Forms
            'contact-form-7'           => ['plugin' => 'Contact Form 7',           'category' => 'forms'],
            'wpcf7'                    => ['plugin' => 'Contact Form 7',           'category' => 'forms'],
            'wpforms'                  => ['plugin' => 'WPForms',                  'category' => 'forms'],
            'gravityforms'             => ['plugin' => 'Gravity Forms',            'category' => 'forms'],

            // WooCommerce
            'woocommerce'              => ['plugin' => 'WooCommerce',              'category' => 'ecommerce'],
            'wc-add-to-cart'           => ['plugin' => 'WooCommerce',              'category' => 'ecommerce'],
            'wc-cart-fragments'        => ['plugin' => 'WooCommerce',              'category' => 'ecommerce',  'note' => 'Runs AJAX on every page load — safe to delay on non-shop pages'],
            'wc-checkout'              => ['plugin' => 'WooCommerce',              'category' => 'ecommerce'],
            'woocommerce-layout'       => ['plugin' => 'WooCommerce',              'category' => 'ecommerce'],
            'woocommerce-smallscreen'  => ['plugin' => 'WooCommerce',              'category' => 'ecommerce'],

            // Page builders
            'elementor-frontend'       => ['plugin' => 'Elementor',               'category' => 'builder'],
            'elementor-frontend-css'   => ['plugin' => 'Elementor CSS',           'category' => 'builder'],
            'uagb-style'               => ['plugin' => 'Spectra (UAGB)',           'category' => 'builder'],
            'uagb-js'                  => ['plugin' => 'Spectra (UAGB)',           'category' => 'builder'],

            // Analytics
            'google-tag-manager'       => ['plugin' => 'Google Tag Manager',      'category' => 'analytics'],
            'monsterinsights-js'       => ['plugin' => 'MonsterInsights',          'category' => 'analytics'],
            'gtag'                     => ['plugin' => 'Google Analytics',         'category' => 'analytics'],

            // Galleries — multiple handle naming conventions
            'magnific-popup'           => ['plugin' => 'Magnific Popup',           'category' => 'gallery',    'note' => 'jQuery dependent'],
            'magnific-popup-css'       => ['plugin' => 'Magnific Popup CSS',       'category' => 'gallery'],
            'fancybox'                 => ['plugin' => 'FancyBox',                 'category' => 'gallery'],
            'fancybox3'                => ['plugin' => 'FancyBox 3',               'category' => 'gallery'],
            'image-gallery'            => ['plugin' => 'Image Gallery',            'category' => 'gallery'],
            'image-gallery-js'         => ['plugin' => 'Image Gallery JS',         'category' => 'gallery'],
            'image-gallery-css'        => ['plugin' => 'Image Gallery CSS',        'category' => 'gallery'],
            'envira-gallery'           => ['plugin' => 'Envira Gallery',           'category' => 'gallery'],
            'envira-gallery-css'       => ['plugin' => 'Envira Gallery CSS',       'category' => 'gallery'],
            'modula-gallery'           => ['plugin' => 'Modula Gallery',           'category' => 'gallery'],
            'justified-gallery'        => ['plugin' => 'Justified Gallery',        'category' => 'gallery'],
            'nextgen-gallery'          => ['plugin' => 'NextGen Gallery',          'category' => 'gallery'],
            'meow-gallery'             => ['plugin' => 'Meow Gallery',             'category' => 'gallery'],

            // PSEC / theme-specific (from the actual network dependency tree)
            'sticky-header'            => ['plugin' => 'Theme — Sticky Header',   'category' => 'theme'],
            'greenpanel'               => ['plugin' => 'Theme — Green Panel',     'category' => 'theme'],
            'greensyncpanels'          => ['plugin' => 'Theme — Sync Panels',     'category' => 'theme'],
            'greentooltip'             => ['plugin' => 'Theme — Tooltip',         'category' => 'theme'],
            'menu'                     => ['plugin' => 'Theme — Navigation Menu', 'category' => 'theme'],

            // Greenshift theme/plugin handles
            'greenshift-animation'     => ['plugin' => 'Greenshift Animation',     'category' => 'animation'],
            'greenshift-scripts'       => ['plugin' => 'Greenshift Scripts',       'category' => 'builder'],
            'greenshift-style'         => ['plugin' => 'Greenshift CSS',           'category' => 'builder'],

            // Gallery
            'image-gallery-min'        => ['plugin' => 'Image Gallery',            'category' => 'gallery'],

            // Sticky header — common handle names
            'sticky-header-js'         => ['plugin' => 'Sticky Header JS',        'category' => 'theme'],
            'sticky-header-css'        => ['plugin' => 'Sticky Header CSS',        'category' => 'theme'],

            // Cookie consent
            'cookieyes'                => ['plugin' => 'CookieYes',               'category' => 'compliance'],
            'cookiebot'                => ['plugin' => 'Cookiebot',               'category' => 'compliance'],
            'cookie-notice'            => ['plugin' => 'Cookie Notice',           'category' => 'compliance'],

            // Social
            'addtoany'                 => ['plugin' => 'AddToAny Share',           'category' => 'social'],
            'sharethis'                => ['plugin' => 'ShareThis',                'category' => 'social'],

            // Video
            'wp-mediaelement'          => ['plugin' => 'WP MediaElement',         'category' => 'media'],
            'mediaelement'             => ['plugin' => 'MediaElement.js',          'category' => 'media'],
            'fitvids'                  => ['plugin' => 'FitVids',                  'category' => 'media'],

            // Scroll
            'parallax'                 => ['plugin' => 'Parallax',                'category' => 'scroll'],
            'scrollmagic'              => ['plugin' => 'ScrollMagic',             'category' => 'scroll'],

            // SEO
            'rank-math'                => ['plugin' => 'Rank Math SEO',           'category' => 'seo'],
            'yoast-seo-frontend'       => ['plugin' => 'Yoast SEO',               'category' => 'seo'],

            // WordPress core — shown in analysis but handled carefully
            'wp-embed'                 => ['plugin' => 'WordPress Embed',          'category' => 'wp-core',    'note' => 'Safe to unload if you do not embed external content or want your posts embedded elsewhere'],
            'wp-block-library'         => ['plugin' => 'Block Editor CSS',         'category' => 'wp-core',    'note' => 'Safe to unload on pages built without blocks — check carefully'],
            'wp-block-library-theme'   => ['plugin' => 'Block Editor Theme CSS',   'category' => 'wp-core',    'note' => 'Adds default block colours/spacing — safe to unload if theme handles all block styles'],
            'classic-theme-styles'     => ['plugin' => 'Classic Theme Styles',     'category' => 'wp-core',    'note' => 'Legacy stylesheet for classic themes — safe to unload on block themes'],
            'dashicons'                => ['plugin' => 'Dashicons',                 'category' => 'wp-core',    'note' => 'Admin icon font — should not load on frontend. Safe to unload unless your theme uses dashicons'],
            'admin-bar'                => ['plugin' => 'Admin Bar CSS',             'category' => 'wp-core',    'note' => 'Only needed when admin bar is visible — handled by WP automatically'],
            'wp-i18n'                  => ['plugin' => 'WordPress i18n',            'category' => 'wp-core',    'note' => 'Internationalisation — needed by blocks and many plugins'],
            'wp-hooks'                 => ['plugin' => 'WordPress Hooks API',       'category' => 'wp-core',    'note' => 'Core WP JS hooks — dependency of many scripts'],
            'wp-element'               => ['plugin' => 'WordPress Element (React)', 'category' => 'wp-core',    'note' => 'React wrapper used by blocks and Gutenberg'],
            'wp-dom-ready'             => ['plugin' => 'WordPress DOM Ready',       'category' => 'wp-core',    'note' => 'Tiny utility — usually a dependency, do not unload'],
            'regenerator-runtime'      => ['plugin' => 'Regenerator Runtime',       'category' => 'wp-core',    'note' => 'Async/await polyfill — needed by many blocks'],
            'wp-polyfill'              => ['plugin' => 'WordPress Polyfills',       'category' => 'wp-core',    'note' => 'Browser polyfills for older browsers'],
            'wp-a11y'                  => ['plugin' => 'WordPress Accessibility',   'category' => 'wp-core',    'note' => 'Screen reader announcements — keep unless you have no interactive elements'],
            'wp-api-fetch'             => ['plugin' => 'WordPress REST API Fetch',  'category' => 'wp-core',    'note' => 'Used by blocks that call the REST API — keep if blocks make API requests'],
        ];
    }

    // -------------------------------------------------------------------------
    // Server-side recommendation (called from AJAX analyser)
    // DOM usage data comes from client-side scan result
    // -------------------------------------------------------------------------
    /**
     * @param array $all_dom_usage Full DOM usage map for all handles (used for jQuery dependency checking)
     */
    public static function get_recommendation(
        string $handle,
        string $asset_type,
        array  $dom_usage,       // ['found' => bool, 'above_fold' => bool, 'count' => int]
        array  $dependents,      // handles that depend on this one
        array  $all_dom_usage = [],  // full DOM usage map (optional, for cross-handle analysis)
        array  $extra = []       // extra client-side detection data
    ): array {

        $resolved   = self::resolve_handle($handle);
        $info       = $resolved['info'];
        $category   = $info['category'] ?? 'unknown';
        $has_sig    = !empty($resolved['sigs']);

        $found       = $dom_usage['found']       ?? false;
        $above_fold  = $dom_usage['above_fold']  ?? false;
        $count       = $dom_usage['count']       ?? 0;

        // jQuery — smart analysis: check if dependents are actually needed on this page
        if (in_array($handle, ['jquery', 'jquery-core'])) {
            $needed_dependents = [];
            $unneeded_dependents = [];

            foreach ($dependents as $dep) {
                $dep_usage = $all_dom_usage[$dep] ?? null;
                $dep_has_sig = isset($signatures[$dep]) && !empty($signatures[$dep]);

                // If dependent has a signature and wasn't found, it's not needed
                if ($dep_has_sig && $dep_usage && !($dep_usage['found'] ?? false)) {
                    $unneeded_dependents[] = $dep;
                } else {
                    $needed_dependents[] = $dep;
                }
            }

            // Check if jQuery is used in inline scripts
            $jquery_in_inline = $extra['jquery_inline_usage'] ?? false;

            if (empty($needed_dependents) && !$jquery_in_inline && !empty($dependents)) {
                return [
                    'action'     => 'unload',
                    'confidence' => 'medium',
                    'reason'     => count($dependents) . ' script(s) depend on jQuery but none are needed on this page (' . implode(', ', array_slice($unneeded_dependents, 0, 3)) . '). Test before unloading.',
                    'badge'      => 'test-first',
                ];
            }

            if (empty($dependents) && !$jquery_in_inline) {
                return [
                    'action'     => 'unload',
                    'confidence' => 'medium',
                    'reason'     => 'No scripts depend on jQuery on this page and no inline jQuery detected. Test before unloading.',
                    'badge'      => 'test-first',
                ];
            }

            // Some dependents are needed — check if any are above fold
            $any_above_fold = false;
            foreach ($needed_dependents as $dep) {
                if (($all_dom_usage[$dep]['above_fold'] ?? false)) {
                    $any_above_fold = true;
                    break;
                }
            }

            if (!$any_above_fold && !$jquery_in_inline) {
                return [
                    'action'     => 'defer',
                    'confidence' => 'medium',
                    'reason'     => count($needed_dependents) . ' dependent script(s) needed but all below fold — defer jQuery to unblock render',
                    'badge'      => 'perf',
                ];
            }

            return [
                'action'     => 'keep',
                'confidence' => 'high',
                'reason'     => count($needed_dependents) . ' active script(s) depend on jQuery above fold — keep',
                'badge'      => 'core',
            ];
        }

        // WP core assets — never auto-recommend unload, flag specific ones
        if ($category === 'wp-core') {
            if ($handle === 'wp-embed') {
                return ['action' => 'unload', 'confidence' => 'medium', 'reason' => 'Safe to remove if you do not use WordPress embeds on this site. Test first.', 'badge' => 'test-first'];
            }
            if (in_array($handle, ['wp-block-library', 'wp-block-library-theme', 'classic-theme-styles'], true)) {
                return ['action' => 'manual', 'confidence' => 'low', 'reason' => 'Block editor stylesheet — safe to unload only if this page uses no Gutenberg blocks. Verify visually.', 'badge' => 'review'];
            }
            if ($handle === 'dashicons') {
                return ['action' => 'unload', 'confidence' => 'medium', 'reason' => 'Dashicons should not load for visitors. Auto-protected when the admin bar is showing — your toolbar will stay intact.', 'badge' => 'test-first'];
            }
            return ['action' => 'keep', 'confidence' => 'high', 'reason' => 'WordPress core script — keep unless you have a specific reason to remove it', 'badge' => 'core'];
        }

        // jquery-migrate — usually safe to remove
        if ($handle === 'jquery-migrate') {
            return [
                'action'     => 'unload',
                'confidence' => 'medium',
                'reason'     => 'Usually safe to remove unless your theme uses deprecated jQuery APIs. Test before unloading.',
                'badge'      => 'test-first',
            ];
        }

        // Has DOM signature and not found anywhere on page
        if ($has_sig && !$found && empty($dependents)) {
            return [
                'action'     => 'unload',
                'confidence' => 'high',
                'reason'     => 'No matching DOM elements found on this page',
                'badge'      => 'safe',
            ];
        }

        // Has DOM signature, found but only below fold
        if ($has_sig && $found && !$above_fold) {
            return [
                'action'     => 'delay',
                'confidence' => 'high',
                'reason'     => "Found {$count} element(s) but all below fold — delay until scroll",
                'badge'      => 'safe',
            ];
        }

        // Two slider libraries loaded — flag investigation
        if ($category === 'slider') {
            // This gets enriched in AJAX handler with full enqueued list
            return [
                'action'     => 'investigate',
                'confidence' => 'medium',
                'reason'     => 'Check if multiple slider libraries are loaded — only one should be active',
                'badge'      => 'review',
            ];
        }

        // Render-blocking CSS — safe to async load
        if ($asset_type === 'style' && $found && !$above_fold) {
            return [
                'action'     => 'async_css',
                'confidence' => 'medium',
                'reason'     => 'Stylesheet is render-blocking but content appears below fold',
                'badge'      => 'perf',
            ];
        }

        // Analytics / tag managers — always safe to delay
        if ($category === 'analytics') {
            return [
                'action'     => 'delay',
                'confidence' => 'high',
                'reason'     => 'Analytics scripts never need to block initial render',
                'badge'      => 'safe',
            ];
        }

        // Animation scripts — delay until interaction
        if ($category === 'animation' && !$above_fold) {
            return [
                'action'     => 'delay',
                'confidence' => 'high',
                'reason'     => 'Animation targets are below fold — delay until scroll',
                'badge'      => 'safe',
            ];
        }

        // Heavy Gutenberg/editor packages that WooCommerce blocks pull onto the frontend.
        // These are editor-only utilities — they have no business loading on a product listing.
        $gutenberg_editor_heavy = [
            'components'   => 'Entire Gutenberg admin UI library (786 KB) — only needed in wp-admin. Loaded as a WooCommerce blocks dependency but not used on frontend pages.',
            'rich-text'    => 'Block editor rich-text library — editor-only, not needed on frontend.',
            'date'         => 'Date/time formatting library (765 KB) pulled in by WooCommerce blocks — not needed on product listing pages.',
            'moment'       => 'Moment.js (57 KB) — date utility loaded as a dependency of wp-date. Investigate removing on non-checkout pages.',
            'autop'        => 'wpautop text formatter — editor-only utility, should not load on frontend.',
            'wordcount'    => 'Word count utility — editor-only, no frontend purpose.',
            'style-engine' => 'Block style engine — only needed when rendering block styles server-side in editor.',
            'plugins'      => 'wp.plugins registry — Gutenberg plugin API, editor-only.',
            'deprecated'   => 'WordPress deprecation warning helper — should never load on frontend.',
        ];
        if (isset($gutenberg_editor_heavy[$handle])) {
            if (empty($dependents)) {
                return [
                    'action'     => 'unload',
                    'confidence' => 'medium',
                    'reason'     => $gutenberg_editor_heavy[$handle] . ' No other loaded scripts depend on it on this page.',
                    'badge'      => 'test-first',
                ];
            }
            return [
                'action'     => 'investigate',
                'confidence' => 'medium',
                'reason'     => $gutenberg_editor_heavy[$handle] . ' ' . count($dependents) . ' script(s) depend on it — check if those are truly needed on this page.',
                'badge'      => 'review',
            ];
        }

        // wc-cart-fragments — notoriously bad for performance
        if ($handle === 'wc-cart-fragments') {
            return [
                'action'     => 'delay',
                'confidence' => 'high',
                'reason'     => 'wc-cart-fragments runs AJAX on every page load — safe to delay on non-cart/checkout pages',
                'badge'      => 'perf',
            ];
        }

        // No signature defined — can't auto-analyse
        if (!$has_sig) {
            return [
                'action'     => 'manual',
                'confidence' => 'low',
                'reason'     => 'No DOM signature defined for this asset — manual review recommended',
                'badge'      => 'manual',
            ];
        }

        return [
            'action'     => 'keep',
            'confidence' => 'high',
            'reason'     => 'Asset appears to be actively used above fold',
            'badge'      => 'active',
        ];
    }

    // -------------------------------------------------------------------------
    // Build full dependency tree for a handle (recursive, returns nested array)
    // Uses $wp_scripts/$wp_styles globals — only works on frontend page loads
    // -------------------------------------------------------------------------
    public static function build_dep_tree(string $handle, string $type = 'script', int $depth = 0): array {
        if ($depth > 5) return []; // safety cap

        global $wp_scripts, $wp_styles;
        $registry = $type === 'script' ? $wp_scripts : $wp_styles;
        $asset    = $registry->registered[$handle] ?? null;

        $map  = self::get_handle_map();
        $node = [
            'handle'  => $handle,
            'plugin'  => $map[$handle]['plugin'] ?? '',
            'deps'    => [],
            'used_by' => self::get_dependents($handle, $type),
        ];

        if ($asset && !empty($asset->deps)) {
            foreach ($asset->deps as $dep) {
                $node['deps'][] = self::build_dep_tree($dep, $type, $depth + 1);
            }
        }

        return $node;
    }

    // -------------------------------------------------------------------------
    // Build dependency tree from client-supplied asset data (for AJAX context)
    // $assets_data = ['handle' => ['src' => '...', 'deps' => [...]], ...]
    // -------------------------------------------------------------------------
    public static function build_dep_tree_from_data(string $handle, array $assets_data, int $depth = 0): array {
        if ($depth > 5) return []; // safety cap

        $asset = $assets_data[$handle] ?? null;
        $deps  = (array) ($asset['deps'] ?? []);

        $resolved = self::resolve_handle($handle);
        $node = [
            'handle'  => $handle,
            'plugin'  => $resolved['info']['plugin'] ?? '',
            'deps'    => [],
            'used_by' => self::get_dependents_from_data($handle, $assets_data),
        ];

        foreach ($deps as $dep) {
            $node['deps'][] = self::build_dep_tree_from_data($dep, $assets_data, $depth + 1);
        }

        return $node;
    }

    // -------------------------------------------------------------------------
    // Get dependents for a handle
    // Uses $wp_scripts/$wp_styles globals — only works on frontend page loads
    // -------------------------------------------------------------------------
    public static function get_dependents(string $handle, string $type = 'script'): array {
        global $wp_scripts, $wp_styles;
        $registry   = $type === 'script' ? $wp_scripts : $wp_styles;
        $dependents = [];

        if (!$registry || empty($registry->registered)) {
            return $dependents;
        }

        foreach ($registry->registered as $h => $asset) {
            if (in_array($handle, (array) $asset->deps)) {
                $dependents[] = $h;
            }
        }

        return $dependents;
    }

    // -------------------------------------------------------------------------
    // Get dependents from client-supplied asset data (for AJAX context)
    // $assets_data = ['handle' => ['src' => '...', 'deps' => [...]], ...]
    // -------------------------------------------------------------------------
    public static function get_dependents_from_data(string $handle, array $assets_data): array {
        $dependents = [];

        foreach ($assets_data as $h => $asset) {
            $deps = (array) ($asset['deps'] ?? []);
            if (in_array($handle, $deps)) {
                $dependents[] = $h;
            }
        }

        return $dependents;
    }

    // -------------------------------------------------------------------------
    // Estimate file size from registered src
    // -------------------------------------------------------------------------
    public static function estimate_size(string $src): int {
        if (!$src) return 0;

        // Convert URL to local path
        $uploads = wp_upload_dir();
        $path    = str_replace(
            [site_url('/'), home_url('/')],
            [ABSPATH, ABSPATH],
            $src
        );

        // Strip query string
        $path = strtok($path, '?');

        if (file_exists($path)) {
            return (int) filesize($path);
        }

        return 0;
    }
}
