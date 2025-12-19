<?php
/** @var Risk[] $watchdogRisks */
/** @var string[] $watchdogIgnored */
/** @var array $watchdogSettings */
/** @var int $watchdogHistoryRetention */
/** @var int $watchdogHistoryDisplay */
/** @var array<int, array{run_at:int, risks:array<int, array<string, mixed>>, risk_count:int}> $watchdogHistoryRecords */
/** @var array<int, array<string, string>> $watchdogHistoryDownloads */
/** @var array $watchdogCronStatus */
/** @var string $watchdogCronEndpoint */
/** @var string|null $watchdogSettingsError */
/** @var array|null $watchdogWpScanError */
/** @var array|null $watchdogLastFailedNotification */
/** @var array{length:int,next_attempt_at:?int} $watchdogQueueStatus */
/** @var string $watchdogActionPrefix */
/** @var array<string, bool> $watchdogNoticeFlags */
/** @var string $watchdogNotificationResult */
/** @var string $watchdogFailedNotificationStatus */
/** @var bool $watchdogCronSecretPersisted */

use Watchdog\TestingMode;
defined('ABSPATH') || exit;

$watchdogActionPrefix = $watchdogActionPrefix ?? \Watchdog\Version::PREFIX;
?>
<div class="wrap wp-watchdog-admin">
    <div class="wp-watchdog-section-header">
        <div>
            <h1 style="display:flex; align-items:center; gap:10px; margin:0;">
                <span class="dashicons dashicons-shield"></span>
                <?php esc_html_e('Site Add-on Watchdog', 'site-add-on-watchdog'); ?>
            </h1>
            <p class="wp-watchdog-muted"><?php esc_html_e('Monitor plugin health, history, and alerts from a single place.', 'site-add-on-watchdog'); ?></p>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field($watchdogActionPrefix . '_scan'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_scan'); ?>">
                <button class="button button-primary button-hero" type="submit">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <?php esc_html_e('Run manual scan', 'site-add-on-watchdog'); ?>
                </button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field($watchdogActionPrefix . '_send_notifications'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_send_notifications'); ?>">
                <input type="hidden" name="force" value="1" />
                <button class="button button-secondary button-hero" type="submit">
                    <span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
                    <?php esc_html_e('Send notifications now', 'site-add-on-watchdog'); ?>
                </button>
            </form>
        </div>
    </div>

    <?php $watchdogWebhookError = get_transient($watchdogActionPrefix . '_webhook_error'); ?>
    <?php if (! empty($watchdogWebhookError)) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($watchdogWebhookError); ?></p></div>
    <?php endif; ?>

    <?php if (! empty($watchdogSettingsError)) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($watchdogSettingsError); ?></p></div>
    <?php endif; ?>

    <?php if (empty($watchdogCronSecretPersisted)) : ?>
        <div class="notice notice-warning"><p><?php esc_html_e('cron secret 尚未初始化或無法儲存，請重新保存設定', 'site-add-on-watchdog'); ?></p></div>
    <?php endif; ?>

    <?php if (! empty($watchdogWpScanError) && is_array($watchdogWpScanError)) : ?>
        <?php
        $watchdogWpScanMessage = $watchdogWpScanError['message'] ?? '';
        $watchdogWpScanCode = isset($watchdogWpScanError['code']) ? (string) $watchdogWpScanError['code'] : '';
        if ($watchdogWpScanMessage !== '' && $watchdogWpScanCode !== '') {
            $watchdogWpScanMessage = sprintf(
                /* translators: 1: WPScan response code, 2: message */
                __('WPScan error (%1$s): %2$s', 'site-add-on-watchdog'),
                $watchdogWpScanCode,
                $watchdogWpScanMessage
            );
        }
        ?>
        <?php if ($watchdogWpScanMessage !== '') : ?>
            <div class="notice notice-warning"><p><?php echo esc_html($watchdogWpScanMessage); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (! empty($watchdogNoticeFlags['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'site-add-on-watchdog'); ?></p></div>
    <?php endif; ?>

    <?php if (! empty($watchdogNoticeFlags['scan'])) : ?>
        <div class="notice notice-info is-dismissible"><p><?php esc_html_e('Manual scan completed.', 'site-add-on-watchdog'); ?></p></div>
    <?php endif; ?>

    <?php if ($watchdogNotificationResult !== '') : ?>
        <?php if ($watchdogNotificationResult === 'sent') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Notifications were dispatched.', 'site-add-on-watchdog'); ?></p></div>
        <?php elseif ($watchdogNotificationResult === 'throttled') : ?>
            <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Notifications skipped to avoid rapid re-sends. Please wait a moment and try again.', 'site-add-on-watchdog'); ?></p></div>
        <?php elseif ($watchdogNotificationResult === 'unchanged') : ?>
            <div class="notice notice-info is-dismissible"><p><?php esc_html_e('No notification changes detected since the last send.', 'site-add-on-watchdog'); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($watchdogFailedNotificationStatus !== '') : ?>
        <?php if ($watchdogFailedNotificationStatus === 'resent') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Queued the captured notification payload for resend.', 'site-add-on-watchdog'); ?></p></div>
        <?php elseif ($watchdogFailedNotificationStatus === 'missing') : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e('No failed notification payload was available to resend.', 'site-add-on-watchdog'); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="wp-watchdog-grid">
        <div class="wp-watchdog-surface">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-heart" aria-hidden="true"></span>
                <?php esc_html_e('Delivery health', 'site-add-on-watchdog'); ?>
            </div>
                <?php $watchdogIsCronDisabled = ! empty($watchdogCronStatus['cron_disabled']); ?>
            <?php if ($watchdogIsCronDisabled) : ?>
                <p class="wp-watchdog-muted"><?php esc_html_e('WP-Cron appears disabled. Use a real cron job or the server endpoint below to keep scans running.', 'site-add-on-watchdog'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0;">
                    <?php wp_nonce_field($watchdogActionPrefix . '_send_notifications'); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_send_notifications'); ?>">
                    <input type="hidden" name="force" value="1" />
                    <input type="hidden" name="ignore_throttle" value="1" />
                    <button class="button button-primary" type="submit">
                        <span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
                        <?php esc_html_e('Retry notifications', 'site-add-on-watchdog'); ?>
                    </button>
                </form>
            <?php else : ?>
                <p class="wp-watchdog-muted"><?php esc_html_e('WP-Cron is running. Watchdog will use scheduled scans and backup triggers to deliver alerts.', 'site-add-on-watchdog'); ?></p>
            <?php endif; ?>
            <div class="wp-watchdog-divider"></div>
            <p class="wp-watchdog-muted" style="word-break:break-word;">
                <strong><?php esc_html_e('Server cron endpoint:', 'site-add-on-watchdog'); ?></strong><br />
                <code><?php echo esc_html($watchdogCronEndpoint); ?></code>
            </p>
            <p class="wp-watchdog-muted"><?php esc_html_e('Call this URL from a system cron or monitoring service to trigger scans or notification retries even when wp-cron is disabled.', 'site-add-on-watchdog'); ?></p>
            <div class="wp-watchdog-divider"></div>
            <div>
                <p class="wp-watchdog-muted" style="margin-bottom:8px;"><strong><?php esc_html_e('Queue status', 'site-add-on-watchdog'); ?></strong></p>
                <?php
                $watchdogQueueLength = (int) ($watchdogQueueStatus['length'] ?? 0);
                $watchdogQueueNextAttempt = $watchdogQueueStatus['next_attempt_at'] ?? null;
                ?>
                <p class="wp-watchdog-muted">
                    <?php
                    printf(
                        esc_html__('Queued jobs: %d', 'site-add-on-watchdog'),
                        esc_html(number_format_i18n($watchdogQueueLength))
                    );
                    ?>
                </p>
                <?php if ($watchdogQueueLength > 0 && $watchdogQueueNextAttempt) : ?>
                    <p class="wp-watchdog-muted">
                        <?php
                        printf(
                            /* translators: 1: scheduled time */
                            esc_html__('Next attempt: %1$s', 'site-add-on-watchdog'),
                            esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $watchdogQueueNextAttempt))
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p class="wp-watchdog-muted"><?php esc_html_e('No pending notifications in the queue.', 'site-add-on-watchdog'); ?></p>
                <?php endif; ?>
            </div>
            <div class="wp-watchdog-divider"></div>
            <div>
                <p class="wp-watchdog-muted" style="margin-bottom:8px;"><strong><?php esc_html_e('Captured notification payloads', 'site-add-on-watchdog'); ?></strong></p>
                <?php if (! empty($watchdogLastFailedNotification)) : ?>
                    <?php
                    $watchdogFailedTime = isset($watchdogLastFailedNotification['failed_at'])
                        ? (int) $watchdogLastFailedNotification['failed_at']
                        : time();
                    $watchdogFailedChannel = $watchdogLastFailedNotification['description']
                        ?: ($watchdogLastFailedNotification['channel'] ?? __('Unknown channel', 'site-add-on-watchdog'));
                    $watchdogFailedError = $watchdogLastFailedNotification['last_error'] ?? '';
                    ?>
                    <p class="wp-watchdog-muted">
                        <?php
                        printf(
                            /* translators: 1: human readable time, 2: channel name */
                            esc_html__('Last failure recorded %1$s via %2$s.', 'site-add-on-watchdog'),
                            esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $watchdogFailedTime)),
                            esc_html($watchdogFailedChannel)
                        );
                        if ($watchdogFailedError !== '') {
                            echo '<br />' . esc_html($watchdogFailedError);
                        }
                        ?>
                    </p>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field($watchdogActionPrefix . '_resend_failed_notification'); ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_resend_failed_notification'); ?>" />
                            <button class="button button-primary" type="submit">
                                <span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
                                <?php esc_html_e('Re-queue payload', 'site-add-on-watchdog'); ?>
                            </button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field($watchdogActionPrefix . '_download_failed_notification'); ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_download_failed_notification'); ?>" />
                            <button class="button" type="submit">
                                <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                <?php esc_html_e('Download payload', 'site-add-on-watchdog'); ?>
                            </button>
                        </form>
                    </div>
                <?php else : ?>
                    <p class="wp-watchdog-muted"><?php esc_html_e('No failed notification payload has been captured yet.', 'site-add-on-watchdog'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="wp-watchdog-grid">
        <div class="wp-watchdog-surface">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                <?php esc_html_e('Scan History', 'site-add-on-watchdog'); ?>
            </div>
            <?php require __DIR__ . '/history.php'; ?>
        </div>
        <div class="wp-watchdog-surface">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-format-status" aria-hidden="true"></span>
                <?php esc_html_e('Notification channels', 'site-add-on-watchdog'); ?>
            </div>
            <?php
            $watchdogChannels = [
                'email'   => __('Email', 'site-add-on-watchdog'),
                'slack'   => __('Slack', 'site-add-on-watchdog'),
                'teams'   => __('Microsoft Teams', 'site-add-on-watchdog'),
                'discord' => __('Discord', 'site-add-on-watchdog'),
                'webhook' => __('Custom Webhook', 'site-add-on-watchdog'),
            ];
            ?>
            <ul class="wp-watchdog-inline-list">
                <?php foreach ($watchdogChannels as $watchdogChannelKey => $watchdogChannelLabel) : ?>
                    <?php $watchdogChannelEnabled = ! empty($watchdogSettings['notifications'][$watchdogChannelKey]['enabled']); ?>
                    <?php $watchdogBadgeClass = $watchdogChannelEnabled ? 'wp-watchdog-badge--success' : 'wp-watchdog-badge--muted'; ?>
                    <li>
                        <span class="wp-watchdog-badge <?php echo esc_attr($watchdogBadgeClass); ?>">
                            <span class="dashicons <?php echo $watchdogChannelEnabled ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>" aria-hidden="true"></span>
                            <?php echo esc_html($watchdogChannelLabel); ?>
                            <span aria-hidden="true">•</span>
                            <?php echo $watchdogChannelEnabled ? esc_html__('On', 'site-add-on-watchdog') : esc_html__('Off', 'site-add-on-watchdog'); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="wp-watchdog-muted" style="margin-top:12px;">
                <?php esc_html_e('Keep channels enabled to receive instant alerts and download-ready reports.', 'site-add-on-watchdog'); ?>
            </p>
        </div>
    </div>

    <div class="wp-watchdog-surface">
        <div class="wp-watchdog-section-header">
            <div class="wp-watchdog-section-title">
                <span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
                <?php esc_html_e('Potential Risks', 'site-add-on-watchdog'); ?>
            </div>
            <div class="wp-watchdog-summary">
                <span class="wp-watchdog-summary__count"><?php echo esc_html(number_format_i18n(count($watchdogRisks))); ?></span>
                <span class="wp-watchdog-summary__label"><?php esc_html_e('items flagged', 'site-add-on-watchdog'); ?></span>
            </div>
        </div>
    <?php if (empty($watchdogRisks)) : ?>
        <p class="wp-watchdog-muted"><?php esc_html_e('No risks detected.', 'site-add-on-watchdog'); ?></p>
    <?php else : ?>
        <?php
        $watchdogColumns = [
            'plugin'   => __('Plugin', 'site-add-on-watchdog'),
            'local'    => __('Local Version', 'site-add-on-watchdog'),
            'remote'   => __('Directory Version', 'site-add-on-watchdog'),
            'reasons'  => __('Reasons', 'site-add-on-watchdog'),
            'actions'  => __('Actions', 'site-add-on-watchdog'),
        ];
        $watchdogPerPage = (int) apply_filters('siteadwa_main_admin_risks_per_page', 10);
        $watchdogNormalizeForSort = static function (string $watchdogValue): string {
            $watchdogNormalized = function_exists('remove_accents') ? remove_accents($watchdogValue) : $watchdogValue;

            return strtolower($watchdogNormalized);
        };
        $watchdogRiskCount = static function (Risk $watchdogRisk): int {
            $watchdogCount = count($watchdogRisk->reasons);
            if (! empty($watchdogRisk->details['vulnerabilities'])) {
                $watchdogCount += count($watchdogRisk->details['vulnerabilities']);
            }

            return $watchdogCount;
        };
        $watchdogVersionScore = static function (string $watchdogVersion): int {
            preg_match_all('/\d+/', $watchdogVersion, $watchdogMatches);
            $watchdogNumbers = $watchdogMatches[0] ?? [];
            if (empty($watchdogNumbers)) {
                return 0;
            }

            $watchdogWeights = [100000000, 100000, 100, 1];
            $watchdogScore = 0;
            foreach ($watchdogWeights as $watchdogIndex => $watchdogWeight) {
                if (! isset($watchdogNumbers[$watchdogIndex])) {
                    continue;
                }
                $watchdogScore += ((int) $watchdogNumbers[$watchdogIndex]) * $watchdogWeight;
            }

            return $watchdogScore;
        };
        ?>
        <div class="wp-watchdog-risk-table" data-wp-watchdog-table data-per-page="<?php echo esc_attr(max($watchdogPerPage, 1)); ?>">
            <div class="wp-watchdog-risk-table__controls">
                <form method="get" class="wp-watchdog-risk-table__filter-form">
                    <input type="hidden" name="page" value="site-add-on-watchdog" />
                    <label class="wp-watchdog-risk-table__search">
                        <span class="screen-reader-text"><?php esc_html_e('Search risks', 'site-add-on-watchdog'); ?></span>
                        <input
                            type="search"
                            name="risk_search"
                            value="<?php echo esc_attr($watchdogRiskSearch); ?>"
                            placeholder="<?php esc_attr_e('Search plugin or reason', 'site-add-on-watchdog'); ?>"
                            data-risk-search
                        />
                    </label>
                    <div class="wp-watchdog-risk-table__sort-select">
                        <label class="screen-reader-text" for="wp-watchdog-risk-sort"><?php esc_html_e('Sort risks by', 'site-add-on-watchdog'); ?></label>
                        <select id="wp-watchdog-risk-sort" name="risk_sort" data-risk-sort>
                            <option
                                value="plugin"
                                data-sort-key="sortPlugin"
                                data-sort-default="asc"
                                <?php selected($watchdogRiskSortSelection, 'plugin'); ?>
                            >
                                <?php esc_html_e('Plugin name', 'site-add-on-watchdog'); ?>
                            </option>
                            <option
                                value="risk_count"
                                data-sort-key="sortRiskCount"
                                data-sort-default="desc"
                                <?php selected($watchdogRiskSortSelection, 'risk_count'); ?>
                            >
                                <?php esc_html_e('Risk count', 'site-add-on-watchdog'); ?>
                            </option>
                            <option
                                value="version_gap"
                                data-sort-key="sortVersionGap"
                                data-sort-default="desc"
                                <?php selected($watchdogRiskSortSelection, 'version_gap'); ?>
                            >
                                <?php esc_html_e('Version gap', 'site-add-on-watchdog'); ?>
                            </option>
                            <option
                                value="local"
                                data-sort-key="sortLocal"
                                data-sort-default="desc"
                                <?php selected($watchdogRiskSortSelection, 'local'); ?>
                            >
                                <?php esc_html_e('Local version', 'site-add-on-watchdog'); ?>
                            </option>
                            <option
                                value="remote"
                                data-sort-key="sortRemote"
                                data-sort-default="desc"
                                <?php selected($watchdogRiskSortSelection, 'remote'); ?>
                            >
                                <?php esc_html_e('Directory version', 'site-add-on-watchdog'); ?>
                            </option>
                            <option
                                value="reasons"
                                data-sort-key="sortReasons"
                                data-sort-default="asc"
                                <?php selected($watchdogRiskSortSelection, 'reasons'); ?>
                            >
                                <?php esc_html_e('Reasons', 'site-add-on-watchdog'); ?>
                            </option>
                        </select>
                        <label class="screen-reader-text" for="wp-watchdog-risk-order"><?php esc_html_e('Sort order', 'site-add-on-watchdog'); ?></label>
                        <select id="wp-watchdog-risk-order" name="risk_order" data-risk-order>
                            <option value="asc" <?php selected($watchdogRiskOrderSelection, 'asc'); ?>>
                                <?php esc_html_e('Ascending', 'site-add-on-watchdog'); ?>
                            </option>
                            <option value="desc" <?php selected($watchdogRiskOrderSelection, 'desc'); ?>>
                                <?php esc_html_e('Descending', 'site-add-on-watchdog'); ?>
                            </option>
                        </select>
                        <button class="button" type="submit"><?php esc_html_e('Apply', 'site-add-on-watchdog'); ?></button>
                    </div>
                </form>
                <div class="wp-watchdog-risk-table__pagination" data-pagination>
                    <button type="button" class="button" data-action="prev" aria-label="<?php esc_attr_e('Previous page', 'site-add-on-watchdog'); ?>" disabled>&lsaquo;</button>
                    <span class="wp-watchdog-risk-table__page-status" data-page-status></span>
                    <button type="button" class="button" data-action="next" aria-label="<?php esc_attr_e('Next page', 'site-add-on-watchdog'); ?>" disabled>&rsaquo;</button>
                </div>
            </div>
            <div class="wp-watchdog-risk-table__scroll">
                <table class="widefat fixed striped wp-list-table">
                    <thead>
                    <tr>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortPlugin" data-sort-default="asc" data-sort-initial aria-sort="ascending">
                                <?php echo esc_html($watchdogColumns['plugin']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortLocal" data-sort-default="desc" aria-sort="none">
                                <?php echo esc_html($watchdogColumns['local']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortRemote" data-sort-default="desc" aria-sort="none">
                                <?php echo esc_html($watchdogColumns['remote']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="wp-watchdog-risk-table__sort" data-sort-key="sortReasons" data-sort-default="asc" aria-sort="none">
                                <?php echo esc_html($watchdogColumns['reasons']); ?>
                                <span class="wp-watchdog-risk-table__sort-indicator" aria-hidden="true"></span>
                            </button>
                        </th>
                        <th scope="col"><?php echo esc_html($watchdogColumns['actions']); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($watchdogRisks as $watchdogRisk) : ?>
                        <?php
                        $watchdogRemoteVersion = $watchdogRisk->remoteVersion ?? __('N/A', 'site-add-on-watchdog');
                        $watchdogRemoteSort    = is_string($watchdogRisk->remoteVersion) ? $watchdogNormalizeForSort($watchdogRisk->remoteVersion) : '';
                        $watchdogReasonParts   = $watchdogRisk->reasons;
                        if (! empty($watchdogRisk->details['vulnerabilities'])) {
                            foreach ($watchdogRisk->details['vulnerabilities'] as $watchdogVulnerability) {
                                if (! empty($watchdogVulnerability['severity_label'])) {
                                    $watchdogReasonParts[] = $watchdogVulnerability['severity_label'];
                                }
                                if (! empty($watchdogVulnerability['title'])) {
                                    $watchdogReasonParts[] = $watchdogVulnerability['title'];
                                }
                                if (! empty($watchdogVulnerability['cve'])) {
                                    $watchdogReasonParts[] = $watchdogVulnerability['cve'];
                                }
                            }
                        }
                        $watchdogReasonSort = $watchdogNormalizeForSort(implode(' ', $watchdogReasonParts));
                        $watchdogRiskCountValue = $watchdogRiskCount($watchdogRisk);
                        $watchdogVersionGapValue = abs(
                            $watchdogVersionScore($watchdogRemoteVersion) - $watchdogVersionScore($watchdogRisk->localVersion)
                        );
                        $watchdogFilterText = $watchdogNormalizeForSort(implode(' ', [
                            $watchdogRisk->pluginName,
                            $watchdogRisk->localVersion,
                            $watchdogRemoteVersion,
                            implode(' ', $watchdogReasonParts),
                        ]));
                        ?>
                        <tr
                            data-sort-plugin="<?php echo esc_attr($watchdogNormalizeForSort($watchdogRisk->pluginName)); ?>"
                            data-sort-local="<?php echo esc_attr($watchdogNormalizeForSort($watchdogRisk->localVersion)); ?>"
                            data-sort-remote="<?php echo esc_attr($watchdogRemoteSort); ?>"
                            data-sort-reasons="<?php echo esc_attr($watchdogReasonSort); ?>"
                            data-sort-risk-count="<?php echo esc_attr((string) $watchdogRiskCountValue); ?>"
                            data-sort-version-gap="<?php echo esc_attr((string) $watchdogVersionGapValue); ?>"
                            data-filter-text="<?php echo esc_attr($watchdogFilterText); ?>"
                        >
                            <td class="column-primary" data-column="plugin" data-column-label="<?php echo esc_attr($watchdogColumns['plugin']); ?>">
                                <?php echo esc_html($watchdogRisk->pluginName); ?>
                            </td>
                            <td data-column="local" data-column-label="<?php echo esc_attr($watchdogColumns['local']); ?>">
                                <?php echo esc_html($watchdogRisk->localVersion); ?>
                            </td>
                            <td data-column="remote" data-column-label="<?php echo esc_attr($watchdogColumns['remote']); ?>">
                                <?php echo esc_html($watchdogRemoteVersion); ?>
                            </td>
                            <td data-column="reasons" data-column-label="<?php echo esc_attr($watchdogColumns['reasons']); ?>">
                                <ul>
                                    <?php foreach ($watchdogRisk->reasons as $watchdogReason) : ?>
                                        <li><?php echo esc_html($watchdogReason); ?></li>
                                    <?php endforeach; ?>
                                    <?php if (! empty($watchdogRisk->details['vulnerabilities'])) : ?>
                                        <li>
                                            <?php esc_html_e('WPScan vulnerabilities:', 'site-add-on-watchdog'); ?>
                                            <ul>
                                                <?php foreach ($watchdogRisk->details['vulnerabilities'] as $watchdogVuln) : ?>
                                                    <li>
                                                        <?php if (! empty($watchdogVuln['severity']) && ! empty($watchdogVuln['severity_label'])) : ?>
                                                            <?php $watchdogSeverityClass = 'wp-watchdog-severity wp-watchdog-severity--' . sanitize_html_class((string) $watchdogVuln['severity']); ?>
                                                            <span class="<?php echo esc_attr($watchdogSeverityClass); ?>"><?php echo esc_html($watchdogVuln['severity_label']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($watchdogVuln['title'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__title"><?php echo esc_html($watchdogVuln['title']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($watchdogVuln['cve'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__cve">- <?php echo esc_html($watchdogVuln['cve']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (! empty($watchdogVuln['fixed_in'])) : ?>
                                                            <span class="wp-watchdog-vulnerability__fixed">(<?php
                                                            printf(
                                                                /* translators: %s is a plugin version number */
                                                                esc_html__('Fixed in %s', 'site-add-on-watchdog'),
                                                                esc_html($watchdogVuln['fixed_in'])
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
                            <td data-column="actions" data-column-label="<?php echo esc_attr($watchdogColumns['actions']); ?>">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field($watchdogActionPrefix . '_ignore'); ?>
                                    <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_ignore'); ?>">
                                    <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($watchdogRisk->pluginSlug); ?>">
                                    <button class="button" type="submit"><?php esc_html_e('Ignore', 'site-add-on-watchdog'); ?></button>
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
                <?php esc_html_e('Ignored Plugins', 'site-add-on-watchdog'); ?>
            </div>
            <?php if (empty($watchdogIgnored)) : ?>
                <p class="wp-watchdog-muted"><?php esc_html_e('No plugins are being ignored.', 'site-add-on-watchdog'); ?></p>
            <?php else : ?>
                <ul class="wp-watchdog-inline-list">
                    <?php foreach ($watchdogIgnored as $watchdogIgnoredSlug) : ?>
                        <li>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field($watchdogActionPrefix . '_unignore'); ?>
                                <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_unignore'); ?>">
                                <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($watchdogIgnoredSlug); ?>">
                                <button class="button" type="submit">
                                    <span class="dashicons dashicons-no" aria-hidden="true"></span>
                                    <?php echo esc_html($watchdogIgnoredSlug); ?>
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
                <?php esc_html_e('Notifications', 'site-add-on-watchdog'); ?>
            </div>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field($watchdogActionPrefix . '_settings'); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr($watchdogActionPrefix . '_save_settings'); ?>">
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('History retention', 'site-add-on-watchdog'); ?></th>
                <td>
                    <label for="wp-watchdog-history-retention" class="screen-reader-text"><?php esc_html_e('History retention', 'site-add-on-watchdog'); ?></label>
                    <input
                        type="number"
                        id="wp-watchdog-history-retention"
                        name="settings[history][retention]"
                        value="<?php echo esc_attr($watchdogSettings['history']['retention'] ?? $watchdogHistoryRetention); ?>"
                        min="1"
                        max="15"
                        step="1"
                    />
                    <p class="description">
                        <?php esc_html_e('Number of recent scans to keep available for review and download (maximum 15).', 'site-add-on-watchdog'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Scan frequency', 'site-add-on-watchdog'); ?></th>
                <td>
                    <?php
                    $watchdogDefaultFrequencyMessage = __('Choose how often the automatic scan should run.', 'site-add-on-watchdog');
                    $watchdogTestingFrequencyMessage = sprintf(
                        /* translators: 1: interval minutes, 2: duration hours */
                        __('Testing mode sends notifications every %1$d minutes to all configured channels and automatically switches back to daily scans after %2$d hours.', 'site-add-on-watchdog'),
                        TestingMode::INTERVAL_MINUTES,
                        TestingMode::DURATION_HOURS
                    );
                    $watchdogIsTestingFrequency      = ($watchdogSettings['notifications']['frequency'] ?? '') === 'testing';
                    $watchdogTestingExpiresAt        = (int) ($watchdogSettings['notifications']['testing_expires_at'] ?? 0);
                    $watchdogNow                     = time();
                    $watchdogShowTestingExpiry       = $watchdogIsTestingFrequency && $watchdogTestingExpiresAt > $watchdogNow;
                    $watchdogDailyTime               = isset($watchdogSettings['notifications']['daily_time'])
                        ? (string) $watchdogSettings['notifications']['daily_time']
                        : '08:00';
                    $watchdogWeeklyDay = isset($watchdogSettings['notifications']['weekly_day'])
                        ? (int) $watchdogSettings['notifications']['weekly_day']
                        : 1;
                    $watchdogWeeklyTime = isset($watchdogSettings['notifications']['weekly_time'])
                        ? (string) $watchdogSettings['notifications']['weekly_time']
                        : '08:00';
                    ?>
                    <label for="wp-watchdog-notification-frequency" class="screen-reader-text"><?php esc_html_e('Scan frequency', 'site-add-on-watchdog'); ?></label>
                    <select id="wp-watchdog-notification-frequency" name="settings[notifications][frequency]">
                        <option value="daily" <?php selected($watchdogSettings['notifications']['frequency'], 'daily'); ?>><?php esc_html_e('Daily', 'site-add-on-watchdog'); ?></option>
                        <option value="weekly" <?php selected($watchdogSettings['notifications']['frequency'], 'weekly'); ?>><?php esc_html_e('Weekly', 'site-add-on-watchdog'); ?></option>
                        <option value="testing" <?php selected($watchdogSettings['notifications']['frequency'], 'testing'); ?>><?php echo esc_html(
                            sprintf(
                                /* translators: %d: interval minutes */
                                __('Testing (every %d minutes)', 'site-add-on-watchdog'),
                                TestingMode::INTERVAL_MINUTES
                            )
                        ); ?></option>
                        <option value="manual" <?php selected($watchdogSettings['notifications']['frequency'], 'manual'); ?>><?php esc_html_e('Manual (no automatic scans)', 'site-add-on-watchdog'); ?></option>
                    </select>
                    <?php
                    $watchdogFrequencyDescriptionClass = 'description wp-watchdog-frequency-description';
                    if ($watchdogIsTestingFrequency) {
                        $watchdogFrequencyDescriptionClass .= ' wp-watchdog-frequency-description--testing';
                    }
                    ?>
                    <p
                        class="<?php echo esc_attr($watchdogFrequencyDescriptionClass); ?>"
                        data-watchdog-frequency-description
                        data-default-message="<?php echo esc_attr($watchdogDefaultFrequencyMessage); ?>"
                        data-testing-message="<?php echo esc_attr($watchdogTestingFrequencyMessage); ?>"
                    >
                        <?php echo esc_html($watchdogIsTestingFrequency ? $watchdogTestingFrequencyMessage : $watchdogDefaultFrequencyMessage); ?>
                    </p>
                    <div class="wp-watchdog-frequency-options" data-watchdog-frequency-options>
                        <div class="wp-watchdog-frequency-options__row" data-watchdog-frequency-target="daily">
                            <label for="wp-watchdog-daily-time" class="wp-watchdog-frequency-label">
                                <?php esc_html_e('Daily send time', 'site-add-on-watchdog'); ?>
                            </label>
                            <input
                                id="wp-watchdog-daily-time"
                                name="settings[notifications][daily_time]"
                                type="time"
                                value="<?php echo esc_attr($watchdogDailyTime); ?>"
                                aria-describedby="wp-watchdog-daily-time-help"
                            />
                            <p class="description" id="wp-watchdog-daily-time-help">
                                <?php esc_html_e('Time of day to start the daily scan (server timezone).', 'site-add-on-watchdog'); ?>
                            </p>
                        </div>
                        <div class="wp-watchdog-frequency-options__row" data-watchdog-frequency-target="weekly">
                            <div class="wp-watchdog-frequency-weekly">
                                <label for="wp-watchdog-weekly-day" class="wp-watchdog-frequency-label">
                                    <?php esc_html_e('Weekly send day', 'site-add-on-watchdog'); ?>
                                </label>
                                <select id="wp-watchdog-weekly-day" name="settings[notifications][weekly_day]">
                                    <option value="1" <?php selected($watchdogWeeklyDay, 1); ?>><?php esc_html_e('Monday', 'site-add-on-watchdog'); ?></option>
                                    <option value="2" <?php selected($watchdogWeeklyDay, 2); ?>><?php esc_html_e('Tuesday', 'site-add-on-watchdog'); ?></option>
                                    <option value="3" <?php selected($watchdogWeeklyDay, 3); ?>><?php esc_html_e('Wednesday', 'site-add-on-watchdog'); ?></option>
                                    <option value="4" <?php selected($watchdogWeeklyDay, 4); ?>><?php esc_html_e('Thursday', 'site-add-on-watchdog'); ?></option>
                                    <option value="5" <?php selected($watchdogWeeklyDay, 5); ?>><?php esc_html_e('Friday', 'site-add-on-watchdog'); ?></option>
                                    <option value="6" <?php selected($watchdogWeeklyDay, 6); ?>><?php esc_html_e('Saturday', 'site-add-on-watchdog'); ?></option>
                                    <option value="7" <?php selected($watchdogWeeklyDay, 7); ?>><?php esc_html_e('Sunday', 'site-add-on-watchdog'); ?></option>
                                </select>
                            </div>
                            <div class="wp-watchdog-frequency-weekly">
                                <label for="wp-watchdog-weekly-time" class="wp-watchdog-frequency-label">
                                    <?php esc_html_e('Weekly send time', 'site-add-on-watchdog'); ?>
                                </label>
                                <input
                                    id="wp-watchdog-weekly-time"
                                    name="settings[notifications][weekly_time]"
                                    type="time"
                                    value="<?php echo esc_attr($watchdogWeeklyTime); ?>"
                                    aria-describedby="wp-watchdog-weekly-time-help"
                                />
                            </div>
                            <p class="description" id="wp-watchdog-weekly-time-help">
                                <?php esc_html_e('Day and time to start the weekly scan (server timezone).', 'site-add-on-watchdog'); ?>
                            </p>
                        </div>
                    </div>
                    <?php if ($watchdogShowTestingExpiry) : ?>
                        <?php
                        $watchdogTimezone = null;
                        if (function_exists('wp_timezone')) {
                            $watchdogTimezone = wp_timezone();
                        } else {
                            $watchdogTimezoneString = (string) get_option('timezone_string');
                            if ($watchdogTimezoneString !== '') {
                                try {
                                    $watchdogTimezone = new DateTimeZone($watchdogTimezoneString);
                                } catch (Exception $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                                }
                            }

                            if (! $watchdogTimezone) {
                                $watchdogGmtOffset = get_option('gmt_offset');
                                if (is_numeric($watchdogGmtOffset)) {
                                    $watchdogSecondsInHour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
                                    $watchdogSecondsOffset = (int) round((float) $watchdogGmtOffset * $watchdogSecondsInHour);
                                    $watchdogTimezoneName  = timezone_name_from_abbr('', $watchdogSecondsOffset, 0);
                                    if ($watchdogTimezoneName !== false) {
                                        try {
                                            $watchdogTimezone = new DateTimeZone($watchdogTimezoneName);
                                        } catch (Exception $exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                                        }
                                    }
                                }
                            }
                        }

                        if (! $watchdogTimezone && class_exists('DateTimeZone')) {
                            $watchdogTimezone = new DateTimeZone('UTC');
                        }

                        $watchdogTestingExpiryMessage = sprintf(
                            /* translators: 1: datetime, 2: relative time */
                            __('Testing mode will automatically switch back to daily scans on %1$s (%2$s remaining).', 'site-add-on-watchdog'),
                            wp_date(
                                get_option('date_format') . ' ' . get_option('time_format'),
                                $watchdogTestingExpiresAt,
                                $watchdogTimezone
                            ),
                            human_time_diff($watchdogNow, $watchdogTestingExpiresAt)
                        );
                        ?>
                        <p class="description wp-watchdog-frequency-description wp-watchdog-frequency-description--expires">
                            <?php echo esc_html($watchdogTestingExpiryMessage); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Email notifications', 'site-add-on-watchdog'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][email][enabled]" <?php checked($watchdogSettings['notifications']['email']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'site-add-on-watchdog'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Recipients (comma separated)', 'site-add-on-watchdog'); ?><br />
                            <input type="text" name="settings[notifications][email][recipients]" value="<?php echo esc_attr($watchdogSettings['notifications']['email']['recipients']); ?>" class="regular-text" />
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Discord notifications', 'site-add-on-watchdog'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][discord][enabled]" <?php checked($watchdogSettings['notifications']['discord']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'site-add-on-watchdog'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Discord webhook URL', 'site-add-on-watchdog'); ?><br />
                            <input type="url" name="settings[notifications][discord][webhook]" value="<?php echo esc_attr($watchdogSettings['notifications']['discord']['webhook']); ?>" class="regular-text" />
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Slack notifications', 'site-add-on-watchdog'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][slack][enabled]" <?php checked($watchdogSettings['notifications']['slack']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'site-add-on-watchdog'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Slack webhook URL', 'site-add-on-watchdog'); ?><br />
                            <input type="url" name="settings[notifications][slack][webhook]" value="<?php echo esc_attr($watchdogSettings['notifications']['slack']['webhook']); ?>" class="regular-text" />
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Microsoft Teams notifications', 'site-add-on-watchdog'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][teams][enabled]" <?php checked($watchdogSettings['notifications']['teams']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'site-add-on-watchdog'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Teams webhook URL', 'site-add-on-watchdog'); ?><br />
                            <input type="url" name="settings[notifications][teams][webhook]" value="<?php echo esc_attr($watchdogSettings['notifications']['teams']['webhook']); ?>" class="regular-text" />
                        </label>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Generic webhook', 'site-add-on-watchdog'); ?></th>
                <td data-watchdog-notification>
                    <label class="wp-watchdog-notification-toggle">
                        <input type="checkbox" name="settings[notifications][webhook][enabled]" <?php checked($watchdogSettings['notifications']['webhook']['enabled']); ?> data-watchdog-toggle />
                        <?php esc_html_e('Enabled', 'site-add-on-watchdog'); ?>
                    </label>
                    <div class="wp-watchdog-notification-fields" data-watchdog-fields>
                        <label>
                            <?php esc_html_e('Webhook URL', 'site-add-on-watchdog'); ?><br />
                            <input type="url" name="settings[notifications][webhook][url]" value="<?php echo esc_attr($watchdogSettings['notifications']['webhook']['url']); ?>" class="regular-text" />
                        </label>
                        <p>
                            <label>
                                <?php esc_html_e('Webhook secret (optional)', 'site-add-on-watchdog'); ?><br />
                                <input type="text" name="settings[notifications][webhook][secret]" value="<?php echo esc_attr($watchdogSettings['notifications']['webhook']['secret'] ?? ''); ?>" class="regular-text" autocomplete="off" />
                            </label>
                            <span class="description"><?php esc_html_e('Used to sign webhook payloads with an HMAC signature.', 'site-add-on-watchdog'); ?></span>
                        </p>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('WPScan API key', 'site-add-on-watchdog'); ?></th>
                <td>
                    <input type="text" name="settings[notifications][wpscan_api_key]" value="<?php echo esc_attr($watchdogSettings['notifications']['wpscan_api_key']); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Optional. Provide your own WPScan API key to enrich vulnerability reports.', 'site-add-on-watchdog'); ?></p>
                </td>
            </tr>
        </table>
        <p><button class="button button-primary" type="submit"><?php esc_html_e('Save settings', 'site-add-on-watchdog'); ?></button></p>
    </form>
        </div>
    </div>
</div>
