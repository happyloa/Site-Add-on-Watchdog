<?php
/** @var Risk[] $risks */
/** @var string[] $ignored */
/** @var array $settings */
/** @var string $scanNonce */
/** @var int $historyRetention */
/** @var int $historyDisplay */
/** @var array<int, array{run_at:int, risks:array<int, array<string, mixed>>, risk_count:int}> $historyRecords */
/** @var array<int, array<string, string>> $historyDownloads */
/** @var array $cronStatus */

use Watchdog\TestingMode;
?>
<div class="wrap wp-watchdog-admin">
    <style>
        .wp-watchdog-admin .wp-watchdog-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px; margin:16px 0; }
        .wp-watchdog-admin .wp-watchdog-surface { background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:16px; box-shadow:0 1px 1px rgba(0,0,0,0.04); }
        .wp-watchdog-admin .wp-watchdog-section-title { display:flex; align-items:center; gap:8px; margin-bottom:12px; font-size:16px; font-weight:600; }
        .wp-watchdog-admin .wp-watchdog-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:0.02em; }
        .wp-watchdog-admin .wp-watchdog-badge--success { background:#e7f7ed; color:#1c5f3a; }
        .wp-watchdog-admin .wp-watchdog-badge--warning { background:#fff4d6; color:#7a5a00; }
        .wp-watchdog-admin .wp-watchdog-badge--muted { background:#eef1f3; color:#1d2327; }
        .wp-watchdog-admin .wp-watchdog-section-header { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
        .wp-watchdog-admin .button-hero { display:flex; align-items:center; gap:8px; }
        .wp-watchdog-admin .wp-watchdog-inline-list { display:flex; gap:8px; flex-wrap:wrap; margin:8px 0 0; padding:0; list-style:none; }
        .wp-watchdog-admin .wp-watchdog-inline-list li { margin:0; }
        .wp-watchdog-admin .wp-watchdog-summary { display:flex; align-items:center; gap:10px; }
        .wp-watchdog-admin .wp-watchdog-summary__count { font-size:32px; font-weight:700; }
        .wp-watchdog-admin .wp-watchdog-summary__label { color:#4b5563; }
        .wp-watchdog-admin .wp-watchdog-card-stack { display:flex; flex-direction:column; gap:12px; }
        .wp-watchdog-admin .wp-watchdog-muted { color:#4b5563; margin:0; }
        .wp-watchdog-admin .wp-watchdog-history-table { margin-top:12px; }
        .wp-watchdog-admin .wp-watchdog-divider { border-top:1px solid #dcdcde; margin:16px 0; }
    </style>

    <div class="wp-watchdog-section-header">
        <div>
            <h1 style="display:flex; align-items:center; gap:10px; margin:0;">
                <span class="dashicons dashicons-shield"></span>
                <?php esc_html_e('Site Add-on Watchdog', 'wp-plugin-watchdog-main'); ?>
            </h1>
            <p class="wp-watchdog-muted"><?php esc_html_e('Monitor plugin health, history, and alerts from a single place.', 'wp-plugin-watchdog-main'); ?></p>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wp_watchdog_scan'); ?>
                <input type="hidden" name="action" value="wp_watchdog_scan">
                <button class="button button-primary button-hero" type="submit">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php esc_html_e('Run manual scan', 'wp-plugin-watchdog-main'); ?>
                </button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wp_watchdog_send_notifications'); ?>
                <input type="hidden" name="action" value="wp_watchdog_send_notifications">
                <input type="hidden" name="force" value="1" />
                <button class="button button-secondary button-hero" type="submit">
                    <span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
                    <?php esc_html_e('Send notifications now', 'wp-plugin-watchdog-main'); ?>
                </button>
            </form>
        </div>
    </div>

    <?php $wp_watchdog_webhook_error = get_transient('wp_watchdog_webhook_error'); ?>
    <?php if (! empty($wp_watchdog_webhook_error)) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($wp_watchdog_webhook_error); ?></p></div>
    <?php endif; ?>

    <?php if (! empty($settingsError)) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($settingsError); ?></p></div>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'wp-plugin-watchdog-main'); ?></p></div>
    <?php endif; ?>

    <?php if (isset($_GET['scan'])) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e('Manual scan completed.', 'wp-plugin-watchdog-main'); ?></p></div>
    <?php endif; ?>

    <?php if (isset($_GET['notifications'])) : ?>
        <?php $notificationResult = sanitize_key((string) $_GET['notifications']); ?>
        <?php if ($notificationResult === 'sent') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Notifications were dispatched.', 'wp-plugin-watchdog-main'); ?></p></div>
        <?php elseif ($notificationResult === 'throttled') : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Notifications skipped to avoid rapid re-sends. Please wait a moment and try again.', 'wp-plugin-watchdog-main'); ?></p></div>
        <?php elseif ($notificationResult === 'unchanged') : ?>
            <div class="notice notice-info is-dismissible"><p><?php esc_html_e('No notification changes detected since the last send.', 'wp-plugin-watchdog-main'); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($_GET['failed_notification'])) : ?>
        <?php $failedStatus = sanitize_key((string) $_GET['failed_notification']); ?>
        <?php if ($failedStatus === 'resent') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Queued the captured notification payload for resend.', 'wp-plugin-watchdog-main'); ?></p></div>
        <?php elseif ($failedStatus === 'missing') : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e('No failed notification payload was available to resend.', 'wp-plugin-watchdog-main'); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="wp-watchdog-grid">
        <div class="wp-watchdog-surface">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-heart" aria-hidden="true"></span>
                <?php esc_html_e('Delivery health', 'wp-plugin-watchdog-main'); ?>
            </div>
            <?php $isCronDisabled = ! empty($cronStatus['cron_disabled']); ?>
            <?php if ($isCronDisabled) : ?>
                <p class="wp-watchdog-muted"><?php esc_html_e('WP-Cron appears disabled. Use a real cron job or the server endpoint below to keep scans running.', 'wp-plugin-watchdog-main'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0;">
                    <?php wp_nonce_field('wp_watchdog_send_notifications'); ?>
                    <input type="hidden" name="action" value="wp_watchdog_send_notifications">
                    <input type="hidden" name="force" value="1" />
                    <input type="hidden" name="ignore_throttle" value="1" />
                    <button class="button button-primary" type="submit">
                        <span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
                        <?php esc_html_e('Retry notifications', 'wp-plugin-watchdog-main'); ?>
                    </button>
                </form>
            <?php else : ?>
                <p class="wp-watchdog-muted"><?php esc_html_e('WP-Cron is running. Watchdog will use scheduled scans and backup triggers to deliver alerts.', 'wp-plugin-watchdog-main'); ?></p>
            <?php endif; ?>
            <div class="wp-watchdog-divider"></div>
            <p class="wp-watchdog-muted" style="word-break:break-word;">
                <strong><?php esc_html_e('Server cron endpoint:', 'wp-plugin-watchdog-main'); ?></strong><br />
                <code><?php echo esc_html($cronEndpoint); ?></code>
            </p>
            <p class="wp-watchdog-muted"><?php esc_html_e('Call this URL from a system cron or monitoring service to trigger scans or notification retries even when wp-cron is disabled.', 'wp-plugin-watchdog-main'); ?></p>
            <div class="wp-watchdog-divider"></div>
            <div>
                <p class="wp-watchdog-muted" style="margin-bottom:8px;"><strong><?php esc_html_e('Captured notification payloads', 'wp-plugin-watchdog-main'); ?></strong></p>
                <?php if (! empty($lastFailedNotification)) : ?>
                    <?php
                    $failedTime = isset($lastFailedNotification['failed_at'])
                        ? (int) $lastFailedNotification['failed_at']
                        : time();
                    $failedChannel = $lastFailedNotification['description']
                        ?: ($lastFailedNotification['channel'] ?? __('Unknown channel', 'wp-plugin-watchdog-main'));
                    $failedError = $lastFailedNotification['last_error'] ?? '';
                    ?>
                    <p class="wp-watchdog-muted">
                        <?php
                        printf(
                            /* translators: 1: human readable time, 2: channel name */
                            esc_html__('Last failure recorded %1$s via %2$s.', 'wp-plugin-watchdog-main'),
                            esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $failedTime)),
                            esc_html($failedChannel)
                        );
                        if ($failedError !== '') {
                            echo '<br />' . esc_html($failedError);
                        }
                        ?>
                    </p>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('wp_watchdog_resend_failed_notification'); ?>
                            <input type="hidden" name="action" value="wp_watchdog_resend_failed_notification" />
                            <button class="button button-primary" type="submit">
                                <span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
                                <?php esc_html_e('Re-queue payload', 'wp-plugin-watchdog-main'); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('wp_watchdog_download_failed_notification'); ?>
                            <input type="hidden" name="action" value="wp_watchdog_download_failed_notification" />
                            <button class="button" type="submit">
                                <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                <?php esc_html_e('Download payload', 'wp-plugin-watchdog-main'); ?>
                            </button>
                        </form>
                    </div>
                <?php else : ?>
                    <p class="wp-watchdog-muted"><?php esc_html_e('No failed notification payload has been captured yet.', 'wp-plugin-watchdog-main'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="wp-watchdog-grid">
        <div class="wp-watchdog-surface">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                <?php esc_html_e('Scan History', 'wp-plugin-watchdog-main'); ?>
            </div>
            <?php require __DIR__ . '/history.php'; ?>
        </div>
        <div class="wp-watchdog-surface">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-format-status" aria-hidden="true"></span>
                <?php esc_html_e('Notification channels', 'wp-plugin-watchdog-main'); ?>
            </div>
            <?php
            $channels = [
                'email'   => __('Email', 'wp-plugin-watchdog-main'),
                'slack'   => __('Slack', 'wp-plugin-watchdog-main'),
                'teams'   => __('Microsoft Teams', 'wp-plugin-watchdog-main'),
                'discord' => __('Discord', 'wp-plugin-watchdog-main'),
                'webhook' => __('Custom Webhook', 'wp-plugin-watchdog-main'),
            ];
            ?>
            <ul class="wp-watchdog-inline-list">
                <?php foreach ($channels as $key => $label) : ?>
                    <?php $enabled = ! empty($settings['notifications'][$key]['enabled']); ?>
                    <?php $badgeClass = $enabled ? 'wp-watchdog-badge--success' : 'wp-watchdog-badge--muted'; ?>
                    <li>
                        <span class="wp-watchdog-badge <?php echo esc_attr($badgeClass); ?>">
                            <span class="dashicons <?php echo $enabled ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>" aria-hidden="true"></span>
                            <?php echo esc_html($label); ?>
                            <span aria-hidden="true">•</span>
                            <?php echo $enabled ? esc_html__('On', 'wp-plugin-watchdog-main') : esc_html__('Off', 'wp-plugin-watchdog-main'); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="wp-watchdog-muted" style="margin-top:12px;">
                <?php esc_html_e('Keep channels enabled to receive instant alerts and download-ready reports.', 'wp-plugin-watchdog-main'); ?>
            </p>
        </div>
    </div>

    <div class="wp-watchdog-surface">
        <div class="wp-watchdog-section-header">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
                <?php esc_html_e('Potential Risks', 'wp-plugin-watchdog-main'); ?>
            </div>
            <div class="wp-watchdog-summary">
                <span class="wp-watchdog-summary__count"><?php echo esc_html(number_format_i18n(count($risks))); ?></span>
                <span class="wp-watchdog-summary__label"><?php esc_html_e('items flagged', 'wp-plugin-watchdog-main'); ?></span>
            </div>
        </div>
    <?php if (empty($risks)) : ?>
        <p class="wp-watchdog-muted"><?php esc_html_e('No risks detected.', 'wp-plugin-watchdog-main'); ?></p>
    <?php else : ?>
        <?php
        $columns = [
            'plugin'   => __('Plugin', 'wp-plugin-watchdog-main'),
            'local'    => __('Local Version', 'wp-plugin-watchdog-main'),
            'remote'   => __('Directory Version', 'wp-plugin-watchdog-main'),
            'reasons'  => __('Reasons', 'wp-plugin-watchdog-main'),
            'actions'  => __('Actions', 'wp-plugin-watchdog-main'),
        ];
        $perPage = (int) apply_filters('wp_watchdog_main_admin_risks_per_page', 10);
        $normalizeForSort = static function (string $value): string {
            $normalized = function_exists('remove_accents') ? remove_accents($value) : $value;

            return strtolower($normalized);
        };
        ?>
        <div class="wp-watchdog-risk-table" data-wp-watchdog-table data-per-page="<?php echo esc_attr(max($perPage, 1)); ?>">
            <div class="wp-watchdog-risk-table__controls">
                <div class="wp-watchdog-risk-table__pagination" data-pagination>
                    <button type="button" class="button" data-action="prev" aria-label="<?php esc_attr_e('Previous page', 'wp-plugin-watchdog-main'); ?>" disabled>&lsaquo;</button>
                    <span class="wp-watchdog-risk-table__page-status" data-page-status></span>
                    <button type="button" class="button" data-action="next" aria-label="<?php esc_attr_e('Next page', 'wp-plugin-watchdog-main'); ?>" disabled>&rsaquo;</button>
                </div>
            </div>
            <div class="wp-watchdog-risk-table__scroll">
                <table class="widefat fixed striped wp-list-table">
                    <thead>
                    <tr>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortPlugin" data-sort-default="asc" data-sort-initial aria-sort="ascending">
                                <?php echo esc_html($columns['plugin']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortLocal" data-sort-default="desc" aria-sort="none">
                                <?php echo esc_html($columns['local']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortRemote" data-sort-default="desc" aria-sort="none">
                                <?php echo esc_html($columns['remote']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortReasons" data-sort-default="asc" aria-sort="none">
                                <?php echo esc_html($columns['reasons']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col"><?php echo esc_html($columns['actions']); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($risks as $risk) : ?>
                        <?php
                        $remoteVersion = $risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog-main');
                        $remoteSort    = is_string($risk->remoteVersion) ? $normalizeForSort($risk->remoteVersion) : '';
                        $reasonParts   = $risk->reasons;
                        if (! empty($risk->details['vulnerabilities'])) {
                            foreach ($risk->details['vulnerabilities'] as $vulnerability) {
                                if (! empty($vulnerability['severity_label'])) {
                                    $reasonParts[] = $vulnerability['severity_label'];
                                }
                                if (! empty($vulnerability['title'])) {
                                    $reasonParts[] = $vulnerability['title'];
                                }
                                if (! empty($vulnerability['cve'])) {
                                    $reasonParts[] = $vulnerability['cve'];
                                }
                            }
                        }
                        $reasonSort = $normalizeForSort(implode(' ', $reasonParts));
                        ?>
                        <tr
                            data-sort-plugin="<?php echo esc_attr($normalizeForSort($risk->pluginName)); ?>"
                            data-sort-local="<?php echo esc_attr($normalizeForSort($risk->localVersion)); ?>"
                            data-sort-remote="<?php echo esc_attr($remoteSort); ?>"
                            data-sort-reasons="<?php echo esc_attr($reasonSort); ?>"
                        >
                            <td class="column-primary" data-column="plugin" data-column-label="<?php echo esc_attr($columns['plugin']); ?>">
                                <?php echo esc_html($risk->pluginName); ?>
                            </td>
                            <td data-column="local" data-column-label="<?php echo esc_attr($columns['local']); ?>">
                                <?php echo esc_html($risk->localVersion); ?>
                            </td>
                            <td data-column="remote" data-column-label="<?php echo esc_attr($columns['remote']); ?>">
                                <?php echo esc_html($remoteVersion); ?>
                            </td>
                            <td data-column="reasons" data-column-label="<?php echo esc_attr($columns['reasons']); ?>">
                                <ul>
                                    <?php foreach ($risk->reasons as $reason) : ?>
                                        <li><?php echo esc_html($reason); ?></li>
                                    <?php endforeach; ?>
                                    <?php if (! empty($risk->details['vulnerabilities'])) : ?>
                                        <li>
                                            <?php esc_html_e('WPScan vulnerabilities:', 'wp-plugin-watchdog-main'); ?>
                                            <ul>
                                                <?php foreach ($risk->details['vulnerabilities'] as $vuln) : ?>
                                                    <li>
                                                        <?php if (! empty($vuln['severity']) && ! empty($vuln['severity_label'])) : ?>
                                                            <?php $severityClass = 'wp-watchdog-severity wp-watchdog-severity--' . sanitize_html_class((string) $vuln['severity']); ?>
                                                            <span class="<?php echo esc_attr($severityClass); ?>"><?php echo esc_html($vuln['severity_label']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($vuln['title'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__title"><?php echo esc_html($vuln['title']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($vuln['cve'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__cve">- <?php echo esc_html($vuln['cve']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($vuln['fixed_in'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__fixed">(<?php
                                                            printf(
                                                                /* translators: %s is a plugin version number */
                                                                esc_html__('Fixed in %s', 'wp-plugin-watchdog-main'),
                                                                esc_html($vuln['fixed_in'])
                                                            );
                                                            ?>)</span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </td>
                            <td data-column="actions" data-column-label="<?php echo esc_attr($columns['actions']); ?>">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('wp_watchdog_ignore'); ?>
                                    <input type="hidden" name="action" value="wp_watchdog_ignore">
                                    <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($risk->pluginSlug); ?>">
                                    <button class="button" type="submit"><?php esc_html_e('Ignore', 'wp-plugin-watchdog-main'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    </div>

    <div class="wp-watchdog-grid">
        <div class="wp-watchdog-surface">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                <?php esc_html_e('Ignored Plugins', 'wp-plugin-watchdog-main'); ?>
            </div>
            <?php if (empty($ignored)) : ?>
                <p class="wp-watchdog-muted"><?php esc_html_e('No plugins are being ignored.', 'wp-plugin-watchdog-main'); ?></p>
            <?php else : ?>
                <ul class="wp-watchdog-inline-list">
                    <?php foreach ($ignored as $slug) : ?>
                        <li>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field('wp_watchdog_unignore'); ?>
                                <input type="hidden" name="action" value="wp_watchdog_unignore">
                                <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($slug); ?>">
                                <button class="button" type="submit">
                                    <span class="dashicons dashicons-no" aria-hidden="true"></span>
                                    <?php echo esc_html($slug); ?>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="wp-watchdog-surface">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                <?php esc_html_e('Notifications', 'wp-plugin-watchdog-main'); ?>
            </div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wp_watchdog_settings'); ?>
        <input type="hidden" name="action" value="wp_watchdog_save_settings">
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('History retention', 'wp-plugin-watchdog-main'); ?></th>
                <td>
                    <label for="wp-watchdog-history-retention" class="screen-reader-text"><?php esc_html_e('History retention', 'wp-plugin-watchdog-main'); ?></label>
                    <input
                        type="number"
                        id="wp-watchdog-history-retention"
                        name="settings[history][retention]"
                        value="<?php echo esc_attr($settings['history']['retention'] ?? $historyRetention); ?>"
                        min="1"
                        max="15"
                        step="1"
                    />
                    <p class="description">
                        <?php esc_html_e('Number of recent scans to keep available for review and download (maximum 15).', 'wp-plugin-watchdog-main'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Scan frequency', 'wp-plugin-watchdog-main'); ?></th>
                <td>
                    <?php
                    $defaultFrequencyMessage = __('Choose how often the automatic scan should run.', 'wp-plugin-watchdog-main');
                    $testingFrequencyMessage = sprintf(
                        /* translators: 1: interval minutes, 2: duration hours */
                        __('Testing mode sends notifications every %1$d minutes to all configured channels and automatically switches back to daily scans after %2$d hours.', 'wp-plugin-watchdog-main'),
                        TestingMode::INTERVAL_MINUTES,
                        TestingMode::DURATION_HOURS
                    );
                    $isTestingFrequency      = ($settings['notifications']['frequency'] ?? '') === 'testing';
                    $testingExpiresAt        = (int) ($settings['notifications']['testing_expires_at'] ?? 0);
                    $now                     = time();
                    $showTestingExpiry       = $isTestingFrequency && $testingExpiresAt > $now;
                    $dailyTime               = isset($settings['notifications']['daily_time'])
                        ? (string) $settings['notifications']['daily_time']
                        : '08:00';
                    $weeklyDay = isset($settings['notifications']['weekly_day'])
                        ? (int) $settings['notifications']['weekly_day']
                        : 1;
                    $weeklyTime = isset($settings['notifications']['weekly_time'])
                        ? (string) $settings['notifications']['weekly_time']
                        : '08:00';
                    ?>
                    <label for="wp-watchdog-notification-frequency" class="screen-reader-text"><?php esc_html_e('Scan frequency', 'wp-plugin-watchdog-main'); ?></label>
                    <select id="wp-watchdog-notification-frequency" name="settings[notifications][frequency]">
                        <option value="daily" <?php selected($settings['notifications']['frequency'], 'daily'); ?>><?php esc_html_e('Daily', 'wp-plugin-watchdog-main'); ?></option>
                        <option value="weekly" <?php selected($settings['notifications']['frequency'], 'weekly'); ?>><?php esc_html_e('Weekly', 'wp-plugin-watchdog-main'); ?></option>
                        <option value="testing" <?php selected($settings['notifications']['frequency'], 'testing'); ?>><?php echo esc_html(
                            sprintf(
                                /* translators: %d: interval minutes */
                                __('Testing (every %d minutes)', 'wp-plugin-watchdog-main'),
                                TestingMode::INTERVAL_MINUTES
                            )
                        ); ?></option>
                        <option value="manual" <?php selected($settings['notifications']['frequency'], 'manual'); ?>><?php esc_html_e('Manual (no automatic scans)', 'wp-plugin-watchdog-main'); ?></option>
                    </select>
                    <?php
                    $frequencyDescriptionClass = 'description wp-watchdog-frequency-description';
                    if ($isTestingFrequency) {
                        $frequencyDescriptionClass .= ' wp-watchdog-frequency-description--testing';
                    }
                    ?>
                    <p
                        class="<?php echo esc_attr($frequencyDescriptionClass); ?>"
                        data-watchdog-frequency-description
                        data-default-message="<?php echo esc_attr($defaultFrequencyMessage); ?>"
                        data-testing-message="<?php echo esc_attr($testingFrequencyMessage); ?>"
                    >
                        <?php echo esc_html($isTestingFrequency ? $testingFrequencyMessage : $defaultFrequencyMessage); ?>
                    </p>
                    <div class="wp-watchdog-frequency-options" data-watchdog-frequency-options>
                        <div class="wp-watchdog-frequency-options__row" data-watchdog-frequency-target="daily">
                            <label for="wp-watchdog-daily-time" class="wp-watchdog-frequency-label">
                                <?php esc_html_e('Daily send time', 'wp-plugin-watchdog-main'); ?>
                            </label>
                            <input
                                id="wp-watchdog-daily-time"
                                name="settings[notifications][daily_time]"
                                type="time"
                                value="<?php echo esc_attr($dailyTime); ?>"
                                aria-describedby="wp-watchdog-daily-time-help"
                            />
                            <p class="description" id="wp-watchdog-daily-time-help">
                                <?php esc_html_e('Time of day to start the daily scan (server timezone).', 'wp-plugin-watchdog-main'); ?>
                            </p>
                        </div>
                        <div class="wp-watchdog-frequency-options__row" data-watchdog-frequency-target="weekly">
                            <div class="wp-watchdog-frequency-weekly">
                                <label for="wp-watchdog-weekly-day" class="wp-watchdog-frequency-label">
                                    <?php esc_html_e('Weekly send day', 'wp-plugin-watchdog-main'); ?>
                                </label>
                                <select id="wp-watchdog-weekly-day" name="settings[notifications][weekly_day]">
                                    <option value="1" <?php selected($weeklyDay, 1); ?>><?php esc_html_e('Monday', 'wp-plugin-watchdog-main'); ?></option>
                                    <option value="2" <?php selected($weeklyDay, 2); ?>><?php esc_html_e('Tuesday', 'wp-plugin-watchdog-main'); ?></option>
                                    <option value="3" <?php selected($weeklyDay, 3); ?>><?php esc_html_e('Wednesday', 'wp-plugin-watchdog-main'); ?></option>
                                    <option value="4" <?php selected($weeklyDay, 4); ?>><?php esc_html_e('Thursday', 'wp-plugin-watchdog-main'); ?></option>
                                    <option value="5" <?php selected($weeklyDay, 5); ?>><?php esc_html_e('Friday', 'wp-plugin-watchdog-main'); ?></option>
                                    <option value="6" <?php selected($weeklyDay, 6); ?>><?php esc_html_e('Saturday', 'wp-plugin-watchdog-main'); ?></option>
                                    <option value="7" <?php selected($weeklyDay, 7); ?>><?php esc_html_e('Sunday', 'wp-plugin-watchdog-main'); ?></option>
                                </select>
                            </div>
                            <div class="wp-watchdog-frequency-weekly">
                                <label for="wp-watchdog-weekly-time" class="wp-watchdog-frequency-label">
                                    <?php esc_html_e('Weekly send time', 'wp-plugin-watchdog-main'); ?>
                                </label>
                                <input
                                    id="wp-watchdog-weekly-time"
                                    name="settings[notifications][weekly_time]"
                                    type="time"
                                    value="<?php echo esc_attr($weeklyTime); ?>"
                                    aria-describedby="wp-watchdog-weekly-time-help"
                                />
                            </div>
                            <p class="description" id="wp-watchdog-weekly-time-help">
                                <?php esc_html_e('Day and time to start the weekly scan (server timezone).', 'wp-plugin-watchdog-main'); ?>
                            </p>
                        </div>
                    </div>
                    <?php if ($showTestingExpiry) : ?>
                        <?php
                        $timezone = null;
                        if (function_exists('wp_timezone')) {
                            $timezone = wp_timezone();
                        } else {
                            $timezoneString = (string) get_option('timezone_string');
                            if ($timezoneString !== '') {
                                try {
                                    $timezone = new DateTimeZone($timezoneString);
                                } catch (Exception $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                                }
                            }

                            if (! $timezone) {
                                $gmtOffset = get_option('gmt_offset');
                                if (is_numeric($gmtOffset)) {
                                    $secondsInHour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
                                    $secondsOffset = (int) round((float) $gmtOffset * $secondsInHour);
                                    $timezoneName  = timezone_name_from_abbr('', $secondsOffset, 0);
                                    if ($timezoneName !== false) {
                                        try {
                                            $timezone = new DateTimeZone($timezoneName);
                                        } catch (Exception $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                                        }
                                    }
                                }
                            }
                        }

                        if (! $timezone && class_exists('DateTimeZone')) {
                            $timezone = new DateTimeZone('UTC');
                        }

                        $testingExpiryMessage = sprintf(
                            /* translators: 1: datetime, 2: relative time */
                            __('Testing mode will automatically switch back to daily scans on %1$s (%2$s remaining).', 'wp-plugin-watchdog-main'),
                            wp_date(
                                get_option('date_format') . ' ' . get_option('time_format'),
                                $testingExpiresAt,
                                $timezone
                            ),
                            human_time_diff($now, $testingExpiresAt)
                        );
                        ?>
                        <p class="description wp-watchdog-frequency-description wp-watchdog-frequency-description--expires">
                            <?php echo esc_html($testingExpiryMessage); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Email notifications', 'wp-plugin-watchdog-main'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][email][enabled]" <?php checked($settings['notifications']['email']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog-main'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Recipients (comma separated)', 'wp-plugin-watchdog-main'); ?><br />
                            <input type="text" name="settings[notifications][email][recipients]" value="<?php echo esc_attr($settings['notifications']['email']['recipients']); ?>" class="regular-text" />
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Discord notifications', 'wp-plugin-watchdog-main'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][discord][enabled]" <?php checked($settings['notifications']['discord']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog-main'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Discord webhook URL', 'wp-plugin-watchdog-main'); ?><br />
                            <input type="url" name="settings[notifications][discord][webhook]" value="<?php echo esc_attr($settings['notifications']['discord']['webhook']); ?>" class="regular-text" />
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Slack notifications', 'wp-plugin-watchdog-main'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][slack][enabled]" <?php checked($settings['notifications']['slack']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog-main'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Slack webhook URL', 'wp-plugin-watchdog-main'); ?><br />
                            <input type="url" name="settings[notifications][slack][webhook]" value="<?php echo esc_attr($settings['notifications']['slack']['webhook']); ?>" class="regular-text" />
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Microsoft Teams notifications', 'wp-plugin-watchdog-main'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][teams][enabled]" <?php checked($settings['notifications']['teams']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog-main'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Teams webhook URL', 'wp-plugin-watchdog-main'); ?><br />
                            <input type="url" name="settings[notifications][teams][webhook]" value="<?php echo esc_attr($settings['notifications']['teams']['webhook']); ?>" class="regular-text" />
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Generic webhook', 'wp-plugin-watchdog-main'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][webhook][enabled]" <?php checked($settings['notifications']['webhook']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'wp-plugin-watchdog-main'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Webhook URL', 'wp-plugin-watchdog-main'); ?><br />
                            <input type="url" name="settings[notifications][webhook][url]" value="<?php echo esc_attr($settings['notifications']['webhook']['url']); ?>" class="regular-text" />
                        </label>
                        <p>
                            <label>
                                <?php esc_html_e('Webhook secret (optional)', 'wp-plugin-watchdog-main'); ?><br />
                                <input type="text" name="settings[notifications][webhook][secret]" value="<?php echo esc_attr($settings['notifications']['webhook']['secret'] ?? ''); ?>" class="regular-text" autocomplete="off" />
                            </label>
                            <span class="description"><?php esc_html_e('Used to sign webhook payloads with an HMAC signature.', 'wp-plugin-watchdog-main'); ?></span>
                        </p>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('WPScan API key', 'wp-plugin-watchdog-main'); ?></th>
                <td>
                    <input type="text" name="settings[notifications][wpscan_api_key]" value="<?php echo esc_attr($settings['notifications']['wpscan_api_key']); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Optional. Provide your own WPScan API key to enrich vulnerability reports.', 'wp-plugin-watchdog-main'); ?></p>
                </td>
            </tr>
        </table>
        <p><button class="button button-primary" type="submit"><?php esc_html_e('Save settings', 'wp-plugin-watchdog-main'); ?></button></p>
    </form>
        </div>
    </div>
</div>
