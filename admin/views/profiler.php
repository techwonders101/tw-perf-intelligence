<?php
defined('ABSPATH') || exit;

$twperf_profiler_reports = TW_Perf_Profiler::get_reports();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report selector for this admin view.
$twperf_profiler_selected_id = sanitize_key(wp_unslash($_GET['twperf_profile_id'] ?? ''));
$twperf_profiler_selected    = null;

foreach ($twperf_profiler_reports as $twperf_profiler_report) {
    if (!is_array($twperf_profiler_report)) continue;
    if (($twperf_profiler_report['id'] ?? '') === $twperf_profiler_selected_id) {
        $twperf_profiler_selected = $twperf_profiler_report;
        break;
    }
}

if (!$twperf_profiler_selected && !empty($twperf_profiler_reports[0]) && is_array($twperf_profiler_reports[0])) {
    $twperf_profiler_selected = $twperf_profiler_reports[0];
}

$twperf_profiler_summary = $twperf_profiler_selected ? TW_Perf_Profiler::get_report_summary_data($twperf_profiler_selected) : [];
?>
<div class="twperf-admin-page twperf-profiler-page">

    <div class="twperf-admin-header">
        <img src="<?php echo esc_url(TWPERF_URL . 'assets/images/logo.jpg'); ?>" alt="TW Perf" class="twperf-admin-logo">
        <div class="twperf-admin-header__text">
            <h1>TW Perf Profiler</h1>
            <p>Excimer-powered PHP profiling for wp-admin and frontend requests</p>
        </div>
        <div class="twperf-admin-header__actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=tw-performance')); ?>" class="button">
                Settings
            </a>
            <?php if (TW_Perf_Profiler::is_available() && TW_Perf_Profiler::is_enabled()) : ?>
                <a href="<?php echo esc_url(TW_Perf_Profiler::get_profile_url()); ?>" class="button button-primary">
                    Profile This Page
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_GET['twperf_deleted'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect flag ?>
        <div class="notice notice-success is-dismissible"><p>Profile report deleted.</p></div>
    <?php endif; ?>

    <div class="twperf-profiler-status-grid">
        <div class="twperf-profiler-status">
            <span class="twperf-profiler-status__label">Excimer</span>
            <strong class="<?php echo TW_Perf_Profiler::is_available() ? 'twperf-text-ok' : 'twperf-text-bad'; ?>">
                <?php echo TW_Perf_Profiler::is_available() ? esc_html__('Available', 'tw-performance') : esc_html__('Unavailable', 'tw-performance'); ?>
            </strong>
        </div>
        <div class="twperf-profiler-status">
            <span class="twperf-profiler-status__label">Profiler</span>
            <strong class="<?php echo TW_Perf_Profiler::is_enabled() ? 'twperf-text-ok' : 'twperf-text-muted'; ?>">
                <?php echo TW_Perf_Profiler::is_enabled() ? esc_html__('Enabled', 'tw-performance') : esc_html__('Disabled', 'tw-performance'); ?>
            </strong>
        </div>
        <div class="twperf-profiler-status">
            <span class="twperf-profiler-status__label">Sampling</span>
            <strong><?php echo esc_html(TW_Perf_Profiler::get_period_ms()); ?> ms</strong>
        </div>
        <div class="twperf-profiler-status">
            <span class="twperf-profiler-status__label">Reports</span>
            <strong><?php echo esc_html(number_format_i18n(count($twperf_profiler_reports))); ?> / <?php echo esc_html(TW_Perf_Profiler::get_retention()); ?></strong>
        </div>
    </div>

    <?php if (!TW_Perf_Profiler::is_available()) : ?>
        <div class="twperf-admin-card">
            <div class="twperf-admin-card__body">
                <div class="twperf-profiler-empty">
                    <p>Excimer is not visible to this PHP process.</p>
                    <span>Make sure the extension is loaded for the same PHP-FPM/Apache PHP version that runs WordPress, not only the CLI.</span>
                </div>
            </div>
        </div>
    <?php elseif (!TW_Perf_Profiler::is_enabled()) : ?>
        <div class="twperf-admin-card">
            <div class="twperf-admin-card__body">
                <div class="twperf-profiler-empty">
                    <p>Profiler controls are disabled.</p>
                    <span>Enable PHP Profiler in TW Perf settings, then use the admin bar item on any wp-admin screen or frontend page.</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="twperf-admin-card">
        <div class="twperf-admin-card__head">
            Recent Reports
        </div>
        <div class="twperf-admin-card__body">
            <?php if (!$twperf_profiler_reports) : ?>
                <div class="twperf-profiler-empty">
                    <p>No profile reports yet.</p>
                    <span>Once enabled, profile any wp-admin screen or frontend page from the TW Perf admin-bar menu.</span>
                </div>
            <?php else : ?>
                <div class="twperf-profiler-table-wrap">
                    <table class="twperf-profiler-table">
                        <thead>
                            <tr>
                                <th>Captured</th>
                                <th>PHP Request</th>
                                <th>Samples</th>
                                <th>Duration</th>
                                <th>Peak Memory</th>
                                <th>Downloads</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($twperf_profiler_reports as $twperf_profiler_report) : ?>
                                <?php
                                if (!is_array($twperf_profiler_report)) continue;
                                $twperf_profiler_id         = (string) ($twperf_profiler_report['id'] ?? '');
                                $twperf_profiler_is_current = $twperf_profiler_selected && (($twperf_profiler_selected['id'] ?? '') === $twperf_profiler_id);
                                $twperf_profiler_created    = (string) ($twperf_profiler_report['created_at'] ?? '');
                                $twperf_profiler_created    = $twperf_profiler_created ? get_date_from_gmt($twperf_profiler_created, 'Y-m-d H:i:s') : '';
                                ?>
                                <tr class="<?php echo $twperf_profiler_is_current ? 'twperf-profiler-row--active' : ''; ?>">
                                    <td>
                                        <a href="<?php echo esc_url(TW_Perf_Profiler::get_admin_page_url(['twperf_profile_id' => $twperf_profiler_id])); ?>">
                                            <?php echo esc_html($twperf_profiler_created ?: $twperf_profiler_id); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html((string) ($twperf_profiler_report['admin_label'] ?? '')); ?></code>
                                        <?php if (!empty($twperf_profiler_report['url'])) : ?>
                                            <span class="twperf-profiler-url"><?php echo esc_html((string) $twperf_profiler_report['url']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(number_format_i18n((int) ($twperf_profiler_report['sample_count'] ?? 0))); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((float) ($twperf_profiler_report['duration_ms'] ?? 0), 1)); ?> ms</td>
                                    <td><?php echo esc_html(number_format_i18n((float) ($twperf_profiler_report['memory_peak_mb'] ?? 0), 1)); ?> MB</td>
                                    <td>
                                        <div class="twperf-profiler-actions">
                                            <?php foreach (['speedscope' => 'Speedscope', 'collapsed' => 'Collapsed', 'summary' => 'Summary'] as $twperf_profiler_format => $twperf_profiler_label) : ?>
                                                <?php if (!empty($twperf_profiler_report['files'][$twperf_profiler_format])) : ?>
                                                    <a href="<?php echo esc_url(TW_Perf_Profiler::get_download_url($twperf_profiler_report, $twperf_profiler_format)); ?>"><?php echo esc_html($twperf_profiler_label); ?></a>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <a class="twperf-profiler-delete" href="<?php echo esc_url(TW_Perf_Profiler::get_delete_url($twperf_profiler_report)); ?>">Delete</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($twperf_profiler_selected) : ?>
        <div class="twperf-admin-card">
            <div class="twperf-admin-card__head">
                Top Sampled Functions
                <span class="twperf-card-status">Report <?php echo esc_html((string) ($twperf_profiler_selected['id'] ?? '')); ?></span>
            </div>
            <div class="twperf-admin-card__body">
                <?php $twperf_profiler_top_functions = is_array($twperf_profiler_summary['top_functions'] ?? null) ? $twperf_profiler_summary['top_functions'] : []; ?>
                <?php if (!$twperf_profiler_top_functions) : ?>
                    <div class="twperf-profiler-empty">
                        <p>No function aggregate was available for this report.</p>
                        <span>Download the Speedscope JSON for the full trace if samples were captured.</span>
                    </div>
                <?php else : ?>
                    <div class="twperf-profiler-table-wrap">
                        <table class="twperf-profiler-table twperf-profiler-table--functions">
                            <thead>
                                <tr>
                                    <th>Function</th>
                                    <th>Inclusive</th>
                                    <th>Self</th>
                                    <th>File</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($twperf_profiler_top_functions, 0, 25) as $twperf_profiler_row) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string) ($twperf_profiler_row['name'] ?? '')); ?></code></td>
                                        <td><?php echo esc_html(number_format_i18n((int) ($twperf_profiler_row['inclusive'] ?? 0))); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) ($twperf_profiler_row['self'] ?? 0))); ?></td>
                                        <td>
                                            <?php if (!empty($twperf_profiler_row['file'])) : ?>
                                                <span class="twperf-profiler-file">
                                                    <?php echo esc_html((string) $twperf_profiler_row['file']); ?><?php echo !empty($twperf_profiler_row['line']) ? ':' . esc_html((string) $twperf_profiler_row['line']) : ''; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="twperf-profiler-footnote">
                        Open the Speedscope JSON at <a href="https://www.speedscope.app/" target="_blank" rel="noopener noreferrer">speedscope.app</a> for the interactive flame chart.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>
