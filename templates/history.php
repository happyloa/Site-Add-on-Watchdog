<?php
/**
 * @var int   $historyRetention
 * @var int   $historyDisplay
 * @var array $historyRecords
 * @var array $historyDownloads
 */
?>
<style>
    .wp-watchdog-history-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px; }
    .wp-watchdog-history-card { background:#f6f7f7; border:1px solid #dcdcde; border-radius:8px; padding:12px 14px; box-shadow:0 1px 1px rgba(0,0,0,0.02); }
    .wp-watchdog-history-card__header { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:8px; }
    .wp-watchdog-history-card__title { margin:0; font-weight:600; font-size:14px; display:flex; align-items:center; gap:6px; }
    .wp-watchdog-history-card__meta { color:#4b5563; font-size:12px; margin:0; }
    .wp-watchdog-history-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:999px; font-weight:600; font-size:12px; text-transform:uppercase; }
    .wp-watchdog-history-badge--safe { background:#e7f7ed; color:#1c5f3a; }
    .wp-watchdog-history-badge--risk { background:#fff4d6; color:#7a5a00; }
    .wp-watchdog-history-card__downloads { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
</style>

<?php if (empty($historyRecords)) : ?>
    <p><?php echo esc_html__('No scans have been recorded yet. Run a scan to populate your history.', 'wp-plugin-watchdog-main'); ?></p>
<?php else : ?>
    <p class="description">
        <?php
        echo esc_html(
            sprintf(
                /* translators: 1: number of scans shown, 2: total scans retained */
                esc_html__('Showing the last %1$s scans (retaining %2$s in total).', 'wp-plugin-watchdog-main'),
                number_format_i18n($historyDisplay),
                number_format_i18n($historyRetention)
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
                        printf(
                            /* translators: %s is a risk count */
                            esc_html(_n('%s risk', '%s risks', $record['risk_count'], 'wp-plugin-watchdog-main')),
                            number_format_i18n($record['risk_count'])
                        );
                        ?>
                    </span>
                </div>
                <p class="wp-watchdog-history-card__meta">
                    <?php echo esc_html__('Download the report for this run:', 'wp-plugin-watchdog-main'); ?>
                </p>
                <div class="wp-watchdog-history-card__downloads">
                    <?php if (! empty($downloads['json'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($downloads['json']); ?>"><?php esc_html_e('Download JSON', 'wp-plugin-watchdog-main'); ?></a>
                    <?php endif; ?>
                    <?php if (! empty($downloads['csv'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($downloads['csv']); ?>"><?php esc_html_e('Download CSV', 'wp-plugin-watchdog-main'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
