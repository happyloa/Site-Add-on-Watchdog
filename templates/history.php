<?php
/**
 * @var int   $historyRetention
 * @var int   $historyDisplay
 * @var array $historyRecords
 * @var array $historyDownloads
 */
?>
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
    <table class="widefat fixed striped">
        <thead>
        <tr>
            <th scope="col"><?php esc_html_e('Scan time', 'wp-plugin-watchdog-main'); ?></th>
            <th scope="col"><?php esc_html_e('Detected risks', 'wp-plugin-watchdog-main'); ?></th>
            <th scope="col"><?php esc_html_e('Downloads', 'wp-plugin-watchdog-main'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($historyRecords as $record) : ?>
            <?php $downloads = $historyDownloads[$record['run_at']] ?? []; ?>
            <tr>
                <td>
                    <?php
                    $format = sprintf('%s %s', get_option('date_format', 'Y-m-d'), get_option('time_format', 'H:i'));
                    echo esc_html(wp_date($format, $record['run_at']));
                    ?>
                </td>
                <td>
                    <?php echo esc_html(number_format_i18n($record['risk_count'])); ?>
                </td>
                <td>
                    <?php if (! empty($downloads['json'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($downloads['json']); ?>"><?php esc_html_e('Download JSON', 'wp-plugin-watchdog-main'); ?></a>
                    <?php endif; ?>
                    <?php if (! empty($downloads['csv'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url($downloads['csv']); ?>"><?php esc_html_e('Download CSV', 'wp-plugin-watchdog-main'); ?></a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
