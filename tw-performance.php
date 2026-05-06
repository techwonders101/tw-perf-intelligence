<?php
/**
 * Plugin Name: TW Perf Intelligence
 * Plugin URI:  https://github.com/techwonders101/tw-perf-intelligence
 * Description: Intelligent asset optimisation — defer, delay, unload JS/CSS per page with DOM-aware recommendations.
 * Version:     1.0.7
 * Author:      TechWonders / PC Buddy 247
 * Author URI:  https://techwonders.co.uk
 * License:     GPL-2.0+
 * Text Domain: tw-performance
 */

defined('ABSPATH') || exit;

if (!defined('TWPERF_VERSION')) define('TWPERF_VERSION',  '1.0.7');
if (!defined('TWPERF_FILE'))    define('TWPERF_FILE',     __FILE__);
if (!defined('TWPERF_DIR'))     define('TWPERF_DIR',      plugin_dir_path(__FILE__));
if (!defined('TWPERF_URL'))     define('TWPERF_URL',      plugin_dir_url(__FILE__));
if (!defined('TWPERF_BASENAME'))define('TWPERF_BASENAME', plugin_basename(__FILE__));

// Load core classes explicitly (autoloader can't load itself)
require_once TWPERF_DIR . 'includes/class-install.php';
require_once TWPERF_DIR . 'includes/class-rules.php';
require_once TWPERF_DIR . 'includes/class-intelligence.php';
require_once TWPERF_DIR . 'includes/class-asset-optimizer.php';
require_once TWPERF_DIR . 'includes/class-preload-manager.php';
require_once TWPERF_DIR . 'includes/class-html-optimizer.php';
require_once TWPERF_DIR . 'includes/class-psi-api.php';
require_once TWPERF_DIR . 'includes/class-cache-purger.php';
require_once TWPERF_DIR . 'includes/class-admin.php';
require_once TWPERF_DIR . 'includes/class-ajax.php';
require_once TWPERF_DIR . 'includes/class-plugin.php';
require_once TWPERF_DIR . 'includes/class-updater.php';

// Boot
add_action('plugins_loaded', function (): void {
    TW_Perf_Plugin::instance();
    (new TW_Perf_Updater())->init();
});

register_activation_hook(__FILE__, ['TW_Perf_Install', 'activate']);
register_deactivation_hook(__FILE__, ['TW_Perf_Install', 'deactivate']);
// Run migrations on every request type (frontend, admin, AJAX) — guarded by a DB-version option
add_action('plugins_loaded', ['TW_Perf_Install', 'maybe_upgrade'], 5);
