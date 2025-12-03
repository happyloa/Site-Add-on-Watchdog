<?php
/**
 * @var int   $historyRetention
 * @var int   $historyDisplay
 * @var array $historyRecords
 * @var array $historyDownloads
 */
defined('ABSPATH') || exit;
?>

<?php if (empty($historyRecords)) : ?>
    <p><?php echo esc_html__('No scans have been recorded yet. Run a scan to populate your history.', 'site-add-on-watchdog'); ?></p>
<?php else : ?>
    <p class="description">
        <?php
        $displayCount = esc_html(number_format_i18n($historyDisplay));
        $retentionCount = esc_html(number_format_i18n($historyRetention));
        echo esc_html(
            sprintf(
                /* translators: 1: number of scans shown, 2: total scans retained */
                esc_html__('Showing the last %1$s scans (retaining %2$s in total).', 'site-add-on-watchdog'),
                $displayCount,
                $retentionCount
            )
        );
        ?>
    </p>
    <div class="wp-watchdog-history-grid" role="list">
        <?php foreach ($historyRecords as $record) : ?>
            <?php $downloads = $historyDownloads[$record['run_at']] ?? []; ?>
            <?php $hasRisks  = (int) $record['risk_count'] > 0; ?>
            <div class="wp-watchdog-history-card" role="listitem">
                <div class="wp-watchdog-history-card__header">
                    <h3 class="wp-watchdog-history-card__title">
                        <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                        <?php
                        $format = sprintf('%s %s', get_option('date_format', 'Y-m-d'), get_option('time_format', 'H:i'));
                        echo esc_html(wp_date($format, $record['run_at']));
                        ?>
                    </h3>
                    <span class="wp-watchdog-history-badge <?php echo $hasRisks ? 'wp-watchdog-history-badge--risk' : 'wp-watchdog-history-badge--safe'; ?>">
                        <span class="dashicons <?php echo $hasRisks ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span>
                        <?php
                        $riskLabel = function_exists('_n')
                            ? _n('%s risk', '%s risks', $record['risk_count'], 'site-add-on-watchdog')
                            : ($record['risk_count'] === 1 ? '%s risk' : '%s risks');

                        $riskCount = esc_html(number_format_i18n($record['risk_count']));
                        printf(esc_html($riskLabel), $riskCount);
                        ?>
                    </span>
                </div>
                <p class="wp-watchdog-history-card__meta">
                    <?php echo esc_html__('Download the report for this run:', 'site-add-on-watchdog'); ?>
                </p>
                <div class="wp-watchdog-history-card__downloads">
                    <?php if (! empty($downloads['json'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($downloads['json']); ?>"><?php esc_html_e('Download JSON', 'site-add-on-watchdog'); ?></a>
                    <?php endif; ?>
                    <?php if (! empty($downloads['csv'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($downloads['csv']); ?>"><?php esc_html_e('Download CSV', 'site-add-on-watchdog'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
