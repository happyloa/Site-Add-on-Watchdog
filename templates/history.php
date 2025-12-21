<?php
/**
 * @var int   $watchdogHistoryRetention
 * @var int   $watchdogHistoryDisplay
 * @var array $watchdogHistoryRecords
 * @var array $watchdogHistoryDownloads
 * @var int   $historyRetention
 * @var int   $historyDisplay
 * @var array $historyRecords
 * @var array $historyDownloads
 */
defined('ABSPATH') || exit;

$watchdogHistoryRetention = $watchdogHistoryRetention ?? $historyRetention ?? 0;
$watchdogHistoryDisplay   = $watchdogHistoryDisplay ?? $historyDisplay ?? 0;
$watchdogHistoryRecords   = $watchdogHistoryRecords ?? $historyRecords ?? [];
$watchdogHistoryDownloads = $watchdogHistoryDownloads ?? $historyDownloads ?? [];
?>

<?php if (empty($watchdogHistoryRecords)) : ?>
    <p><?php echo esc_html__('No scans have been recorded yet. Run a scan to populate your history.', 'site-add-on-watchdog'); ?></p>
<?php else : ?>
    <p class="description">
        <?php
        $watchdogDisplayCount = esc_html(number_format_i18n($watchdogHistoryDisplay));
        $watchdogRetentionCount = esc_html(number_format_i18n($watchdogHistoryRetention));
        echo esc_html(
            sprintf(
                /* translators: 1: number of scans shown, 2: total scans retained */
                esc_html__('Showing the last %1$s scans (retaining %2$s in total).', 'site-add-on-watchdog'),
                $watchdogDisplayCount,
                $watchdogRetentionCount
            )
        );
        ?>
    </p>
    <div class="wp-watchdog-history-grid" role="list">
        <?php foreach ($watchdogHistoryRecords as $watchdogRecord) : ?>
            <?php $watchdogDownloads = $watchdogHistoryDownloads[$watchdogRecord['run_at']] ?? []; ?>
            <?php $watchdogHasRisks  = (int) $watchdogRecord['risk_count'] > 0; ?>
            <div class="wp-watchdog-history-card" role="listitem">
                <div class="wp-watchdog-history-card__header">
                    <h3 class="wp-watchdog-history-card__title">
                        <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                        <?php
                        $watchdogFormat = sprintf('%s %s', get_option('date_format', 'Y-m-d'), get_option('time_format', 'H:i'));
                        echo esc_html(wp_date($watchdogFormat, $watchdogRecord['run_at']));
                        ?>
                    </h3>
                    <span class="wp-watchdog-history-badge <?php echo $watchdogHasRisks ? 'wp-watchdog-history-badge--risk' : 'wp-watchdog-history-badge--safe'; ?>">
                        <span class="dashicons <?php echo $watchdogHasRisks ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
                        <?php
                        $watchdogRiskLabel = function_exists('_n')
                            ? /* translators: %s: number of risks. */
                            _n(
                                '%s risk',
                                '%s risks',
                                $watchdogRecord['risk_count'],
                                'site-add-on-watchdog'
                            )
                            : ($watchdogRecord['risk_count'] === 1 ? '%s risk' : '%s risks');

                        $watchdogRiskCount = number_format_i18n($watchdogRecord['risk_count']);
                        printf(esc_html($watchdogRiskLabel), esc_html($watchdogRiskCount));
                        ?>
                    </span>
                </div>
                <p class="wp-watchdog-history-card__meta">
                    <?php echo esc_html__('Download the report for this run:', 'site-add-on-watchdog'); ?>
                </p>
                <div class="wp-watchdog-history-card__downloads">
                    <?php if (! empty($watchdogDownloads['json'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($watchdogDownloads['json']); ?>"><?php esc_html_e('Download JSON', 'site-add-on-watchdog'); ?></a>
                    <?php endif; ?>
                    <?php if (! empty($watchdogDownloads['csv'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($watchdogDownloads['csv']); ?>"><?php esc_html_e('Download CSV', 'site-add-on-watchdog'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
