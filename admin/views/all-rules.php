<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);

global $wpdb;
$table = esc_sql($wpdb->prefix . 'twperf_rules'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Handle bulk delete
if (!empty($_POST['bulk_delete']) && check_admin_referer('twperf_bulk_delete')) {
    $ids = array_map('intval', $_POST['rule_ids'] ?? []); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders)", ...$ids)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        TW_Perf_Cache_Purger::purge();
        echo '<div class="notice notice-success is-dismissible"><p>' . count($ids) . ' rules deleted.</p></div>';
    }
}

// Fetch all rules
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$rows = $wpdb->get_results(
    "SELECT r.*, p.post_title
     FROM {$table} r
     LEFT JOIN {$wpdb->posts} p ON (r.rule_type = 'page' AND r.target = CONCAT('post_', p.ID))
     ORDER BY r.rule_type, r.target, r.asset_type, r.handle",
    ARRAY_A
) ?: [];
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$handle_map   = TW_Perf_Intelligence::get_handle_map(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$cache_active = TW_Perf_Cache_Purger::detect_active(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Build a src lookup from registered scripts + styles so we can parse the plugin folder
// for handles that aren't in the intelligence map.
$registered_src = []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
foreach ( wp_scripts()->registered as $h => $dep ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    if ( ! empty( $dep->src ) ) $registered_src[ $h ] = $dep->src; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
}
foreach ( wp_styles()->registered as $h => $dep ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    if ( ! empty( $dep->src ) ) $registered_src[ $h ] = $dep->src; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
}

/**
 * Return a display-ready plugin name for a rule row.
 * Prefers the intelligence-map label; falls back to the folder slug parsed from the src path.
 *
 * @param string $handle
 * @param array  $handle_map
 * @param array  $registered_src
 * @return string
 */
function twperf_format_plugin_slug( string $slug ): string {
    $known = [
        'wordpress-seo'             => 'Yoast SEO',
        'woocommerce'               => 'WooCommerce',
        'contact-form-7'            => 'Contact Form 7',
        'elementor'                 => 'Elementor',
        'revslider'                 => 'Revolution Slider',
        'js_composer'               => 'WPBakery',
        'gravityforms'              => 'Gravity Forms',
        'ninja-forms'               => 'Ninja Forms',
        'wpforms-lite'              => 'WPForms',
        'wpforms'                   => 'WPForms',
        'advanced-custom-fields'    => 'ACF',
        'acf-pro'                   => 'ACF Pro',
        'popup-maker'               => 'Popup Maker',
        'mailchimp-for-wp'          => 'Mailchimp for WP',
        'woo-variation-swatches'    => 'Variation Swatches',
        'woo-smart-quick-view'      => 'WPC Smart Quick View',
        'convertivo'                => 'Convertivo',
        'seo-by-rank-math'          => 'Rank Math SEO',
        'the-events-calendar'       => 'The Events Calendar',
        'tribe-common'              => 'The Events Calendar',
        'tablepress'                => 'TablePress',
        'presto-player'             => 'Presto Player',
        'fluent-crm'                => 'FluentCRM',
        'fluentform'                => 'Fluent Forms',
        'learndash'                 => 'LearnDash',
        'tutor'                     => 'Tutor LMS',
        'wpdiscuz'                  => 'wpDiscuz',
        'litespeed-cache'           => 'LiteSpeed Cache',
        'w3-total-cache'            => 'W3 Total Cache',
        'wp-super-cache'            => 'WP Super Cache',
        'autoptimize'               => 'Autoptimize',
        'wp-rocket'                 => 'WP Rocket',
        'imagify'                   => 'Imagify',
        'smush'                     => 'Smush',
        'greenshift-animation-and-page-builder-blocks' => 'GreenShift',
        'greenshift'                => 'GreenShift',
    ];
    if ( isset( $known[ $slug ] ) ) return $known[ $slug ];
    return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
}

function twperf_rule_plugin_label( string $handle, array $handle_map, array $registered_src ): string {
    if ( ! empty( $handle_map[ $handle ]['plugin'] ) ) {
        return $handle_map[ $handle ]['plugin'];
    }
    $src = $registered_src[ $handle ] ?? '';
    if ( $src && preg_match( '~/plugins/([^/?#]+)/~', $src, $m ) ) {
        $slug = $m[1];
        if ( $slug === 'tw-performance' ) return '';
        return twperf_format_plugin_slug( $slug );
    }
    return '';
}

// Group by scope
$grouped = []; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
foreach ($rows as $row) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    $label = match($row['rule_type']) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
        'global'    => '🌐 Global — all pages',
        'post_type' => '📄 ' . TW_Perf_Rules::post_type_label( $row['target'] ),
        'page'      => '📃 ' . ($row['post_title'] ?: $row['target']),
        default     => $row['target'],
    };
    $grouped[$label][] = $row; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
}

$action_colours = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
    'unload'    => ['bg' => '#fef2f2', 'text' => '#ef4444'],
    'delay'     => ['bg' => '#fffbeb', 'text' => '#f59e0b'],
    'defer'     => ['bg' => '#eff6ff', 'text' => '#3b82f6'],
    'async_css' => ['bg' => '#f5f3ff', 'text' => '#a78bfa'],
    'preload'   => ['bg' => '#f0fdf4', 'text' => '#22c55e'],
    'keep'      => ['bg' => '#f8fafc', 'text' => '#64748b'],
];
?>
<div class="twperf-admin-page twperf-rules-page">

    <!-- Header -->
    <div class="twperf-admin-header">
        <img src="<?php echo esc_url(TWPERF_URL . 'assets/images/logo.jpg'); ?>" alt="TW Perf" class="twperf-admin-logo">
        <div class="twperf-admin-header__text">
            <h1>All Rules</h1>
            <p>
                <?php echo count($rows); ?> rule<?php echo count($rows) !== 1 ? 's' : ''; ?> across <?php echo count($grouped); ?> scope<?php echo count($grouped) !== 1 ? 's' : ''; ?>
                <?php if ($cache_active) : ?>
                · Cache: <?php echo esc_html(implode(', ', $cache_active)); ?> (purged automatically on change)
                <?php endif; ?>
            </p>
        </div>
        <div class="twperf-admin-header__actions">
            <a href="<?php echo esc_url(admin_url('options-general.php?page=tw-performance')); ?>" class="button">Settings</a>
        </div>
    </div>

    <?php if (empty($rows)) : ?>
    <div class="twperf-admin-card">
        <div class="twperf-rules-empty">
            <p>No rules saved yet.</p>
            <p>Visit any page on your site, open the TW Perf panel from the admin bar, analyse the page and apply recommendations.</p>
        </div>
    </div>
    <?php else : ?>

    <form method="post">
        <?php wp_nonce_field('twperf_bulk_delete'); ?>

        <!-- Toolbar -->
        <div class="twperf-rules-toolbar">
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#475569;cursor:pointer;">
                <input type="checkbox" id="twperf-select-all" style="margin:0;">
                Select all
            </label>
            <button type="submit" name="bulk_delete" value="1" class="button button-secondary">
                Delete Selected <span class="twperf-bulk-count"></span>
            </button>
            <button type="button" id="twperf-admin-purge-all" class="button" style="margin-left:auto;">
                Purge All Caches
            </button>
        </div>

        <!-- Rules body -->
        <div class="twperf-rules-body">
            <div class="twperf-rules-scope">

            <?php foreach ($grouped as $label => $group_rules) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>

                <div class="twperf-rules-scope-label">
                    <?php echo esc_html($label); ?>
                    <span style="font-weight:400;color:#94a3b8;">(<?php echo count($group_rules); ?>)</span>
                    <label style="margin-left:auto;display:flex;align-items:center;gap:5px;font-weight:400;font-size:11px;cursor:pointer;">
                        <input type="checkbox" class="twperf-group-select" style="margin:0;"> Select group
                    </label>
                </div>

                <div class="twperf-rules-scope-scroll">
                <table class="twperf-rules-table">
                    <thead>
                        <tr>
                            <th style="width:24px;"></th>
                            <th>Action</th>
                            <th>Handle</th>
                            <th>Plugin</th>
                            <th>Type</th>
                            <th>Context</th>
                            <th>Saved</th>
                            <th style="width:24px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group_rules as $r) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        $colour = $action_colours[$r['action']] ?? ['bg' => '#f8fafc', 'text' => '#64748b']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        // Stored slug wins; fall back to intelligence map + registered src path
                        $stored_slug = $r['plugin_slug'] ?? ''; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                        $plugin = $stored_slug
                            ? twperf_format_plugin_slug( $stored_slug )
                            : twperf_rule_plugin_label( $r['handle'], $handle_map, $registered_src ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                    ?>
                    <tr>
                        <td><input type="checkbox" name="rule_ids[]" value="<?php echo (int) $r['id']; ?>"></td>
                        <td>
                            <span class="twperf-action-badge"
                                  style="background:<?php echo esc_attr($colour['bg']); ?>;color:<?php echo esc_attr($colour['text']); ?>;">
                                <?php echo esc_html($r['action']); ?>
                            </span>
                            <?php if (!empty($r['preview_only'])) : ?>
                            <span style="font-size:10px;color:#f59e0b;margin-left:4px;">preview</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($r['handle']); ?></code></td>
                        <td style="color:#64748b;"><?php echo esc_html($plugin); ?></td>
                        <td>
                            <span class="twperf-type-badge twperf-type-badge--<?php echo esc_attr($r['asset_type']); ?>">
                                <?php echo esc_html($r['asset_type']); ?>
                            </span>
                        </td>
                        <td style="color:#94a3b8;"><?php echo esc_html($r['context'] ?? 'frontend'); ?></td>
                        <td style="color:#94a3b8;"><?php echo esc_html(gmdate('d M Y', strtotime($r['created_at']))); ?></td>
                        <td>
                            <a href="<?php echo esc_url(wp_nonce_url(
                                add_query_arg(['delete_rule' => $r['id'], 'page' => 'tw-performance-rules'], admin_url('options-general.php')),
                                'twperf_delete_' . $r['id']
                            )); ?>"
                               class="twperf-rule-delete"
                               onclick="return confirm('Delete this rule?')">✕</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div><!-- .twperf-rules-scope-scroll -->

            <?php endforeach; ?>
            </div>
        </div><!-- .twperf-rules-body -->

        <div style="padding:12px 0;">
            <button type="submit" name="bulk_delete" value="1" class="button button-secondary">
                Delete Selected <span class="twperf-bulk-count"></span>
            </button>
        </div>

    </form>

    <?php endif; ?>
</div>

<script>
document.getElementById('twperf-select-all')?.addEventListener('change', function() {
    document.querySelectorAll('input[name="rule_ids[]"]').forEach(cb => cb.checked = this.checked);
    updateBulkCount();
});
document.querySelectorAll('.twperf-group-select').forEach(cb => {
    cb.addEventListener('change', function() {
        this.closest('table').querySelectorAll('input[name="rule_ids[]"]').forEach(r => r.checked = this.checked);
        updateBulkCount();
    });
});
document.querySelectorAll('input[name="rule_ids[]"]').forEach(cb => {
    cb.addEventListener('change', updateBulkCount);
});
function updateBulkCount() {
    const n = document.querySelectorAll('input[name="rule_ids[]"]:checked').length;
    document.querySelectorAll('.twperf-bulk-count').forEach(el => el.textContent = n ? ' (' + n + ')' : '');
}
</script>

<?php
// Handle single delete (non-AJAX fallback)
if (!empty($_GET['delete_rule']) && check_admin_referer('twperf_delete_' . (int)$_GET['delete_rule'])) {
    $wpdb->delete($table, ['id' => (int)$_GET['delete_rule']]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    TW_Perf_Cache_Purger::purge();
    echo '<script>window.location = "' . esc_url(admin_url('options-general.php?page=tw-performance-rules')) . '";</script>';
}
