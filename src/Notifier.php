<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Services\NotificationQueue;
use Watchdog\Version;

class Notifier
{
    private const PREFIX = Version::PREFIX;

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly NotificationQueue $notificationQueue
    ) {
    }

    /**
     * @param Risk[] $risks
     */
    public function notify(array $risks): void
    {
        $settings        = $this->settingsRepository->get();
        $notifications   = $settings['notifications'];
        $emailSettings   = $notifications['email'];
        $discordSettings = $notifications['discord'];
        $slackSettings   = is_array($notifications['slack'] ?? null) ? $notifications['slack'] : [];
        $teamsSettings   = is_array($notifications['teams'] ?? null) ? $notifications['teams'] : [];
        $webhookSettings = $notifications['webhook'];
        $plainTextReport = $this->formatPlainTextMessage($risks);
        $emailReport     = $this->formatEmailMessage($risks);

        $jobs = [];

        if (! empty($emailSettings['enabled'])) {
            $configuredRecipients = [];
            if (! empty($emailSettings['recipients'])) {
                $configuredRecipients = $this->parseRecipients($emailSettings['recipients']);
            }

            $recipients = $this->uniqueEmails(array_merge(
                $configuredRecipients,
                $this->getAdministratorEmails()
            ));

            if (! empty($recipients)) {
                $jobs[] = [
                    'channel'     => 'email',
                    'description' => __('Email alert', 'site-add-on-watchdog'),
                    'payload'     => [
                        'recipients' => $recipients,
                        'subject'    => __('Site Add-on Watchdog Risk Alert', 'site-add-on-watchdog'),
                        'body'       => $emailReport,
                        'headers'    => ['Content-Type: text/html; charset=UTF-8'],
                    ],
                ];
            }
        }

        if (! empty($discordSettings['enabled']) && ! empty($discordSettings['webhook'])) {
            $jobs[] = [
                'channel'     => 'webhook',
                'description' => __('Discord webhook', 'site-add-on-watchdog'),
                'payload'     => [
                    'url'    => $discordSettings['webhook'],
                    'body'   => $this->formatDiscordMessage($risks, $plainTextReport),
                    'secret' => null,
                ],
            ];
        }

        if (! empty($slackSettings['enabled']) && ! empty($slackSettings['webhook'])) {
            $jobs[] = [
                'channel'     => 'webhook',
                'description' => __('Slack webhook', 'site-add-on-watchdog'),
                'payload'     => [
                    'url'    => $slackSettings['webhook'],
                    'body'   => $this->formatSlackMessage($risks, $plainTextReport),
                    'secret' => null,
                ],
            ];
        }

        if (! empty($teamsSettings['enabled']) && ! empty($teamsSettings['webhook'])) {
            $jobs[] = [
                'channel'     => 'webhook',
                'description' => __('Microsoft Teams webhook', 'site-add-on-watchdog'),
                'payload'     => [
                    'url'    => $teamsSettings['webhook'],
                    'body'   => $this->formatTeamsMessage($risks),
                    'secret' => null,
                ],
            ];
        }

        if (! empty($webhookSettings['enabled']) && ! empty($webhookSettings['url'])) {
            $jobs[] = [
                'channel'     => 'webhook',
                'description' => __('Custom webhook', 'site-add-on-watchdog'),
                'payload'     => [
                    'url'    => $webhookSettings['url'],
                    'body'   => [
                        'message' => $plainTextReport,
                        'risks'   => array_map(static fn (Risk $risk): array => $risk->toArray(), $risks),
                        'links'   => [
                            'dashboard' => admin_url('admin.php?page=site-add-on-watchdog'),
                            'updates'   => admin_url('update-core.php'),
                        ],
                        'meta'    => [
                            'count'      => count($risks),
                            'generated'  => time(),
                            'source'     => 'Site Add-on Watchdog',
                        ],
                    ],
                    'secret' => $webhookSettings['secret'] ?? null,
                ],
            ];
        }

        if ($jobs === []) {
            return;
        }

        $this->notificationQueue->enqueue($jobs);
        $this->processQueue();
    }

    public function processQueue(): array
    {
        return $this->notificationQueue->process(function (array $job): bool|string {
            return $this->sendQueuedJob($job);
        });
    }

    public function getLastFailedNotification(): ?array
    {
        return $this->notificationQueue->getLastFailed();
    }

    public function requeueLastFailedNotification(): bool
    {
        return $this->notificationQueue->requeueLastFailed();
    }

    private function dispatchWebhookJob(array $payload): bool|string
    {
        $url = isset($payload['url']) ? (string) $payload['url'] : '';
        if ($url === '') {
            return __('Webhook URL missing.', 'site-add-on-watchdog');
        }

        $body = $payload['body'] ?? [];
        if (! is_array($body)) {
            return __('Webhook payload invalid.', 'site-add-on-watchdog');
        }

        $secret  = isset($payload['secret']) ? (string) $payload['secret'] : null;
        $encoded = wp_json_encode($body);
        if (! is_string($encoded)) {
            $encoded = '';
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($secret !== null && $secret !== '') {
            $headers['X-Watchdog-Signature'] = 'sha256=' . hash_hmac('sha256', $encoded, $secret);
        }

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => $encoded,
            'timeout' => 10,
        ]);

        $expiration = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

        if (is_wp_error($response)) {
            $message = sprintf(
                'Site Add-on Watchdog webhook request to %s failed: %s',
                $url,
                $response->get_error_message()
            );

            $this->logWebhookFailure($message);
            set_transient(self::PREFIX . '_webhook_error', $message, $expiration);

            return $message;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            $bodyMessage = trim((string) wp_remote_retrieve_body($response));
            $message     = sprintf(
                'Site Add-on Watchdog webhook request to %s failed with status %d',
                $url,
                $statusCode
            );

            if ($bodyMessage !== '') {
                $message .= sprintf(': %s', $bodyMessage);
            }

            $this->logWebhookFailure($message);
            set_transient(self::PREFIX . '_webhook_error', $message, $expiration);

            return $message;
        }

        delete_transient(self::PREFIX . '_webhook_error');

        return true;
    }

    private function sendEmailJob(array $payload): bool|string
    {
        $recipients = isset($payload['recipients']) && is_array($payload['recipients'])
            ? array_values($payload['recipients'])
            : [];

        $recipients = array_values(array_filter(
            array_map(static fn ($recipient): string => (string) $recipient, $recipients),
            static fn ($recipient): bool => $recipient !== ''
        ));

        if ($recipients === []) {
            return __('Email recipients missing.', 'site-add-on-watchdog');
        }

        $subject = isset($payload['subject']) ? (string) $payload['subject'] : '';
        $body    = isset($payload['body']) ? (string) $payload['body'] : '';
        $headers = $payload['headers'] ?? ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($recipients, $subject, $body, $headers);

        return $sent ? true : __('Email delivery failed.', 'site-add-on-watchdog');
    }

    private function sendQueuedJob(array $job): bool|string
    {
        $channel = $job['channel'] ?? '';
        $payload = $job['payload'] ?? [];

        if ($channel === 'email') {
            return $this->sendEmailJob(is_array($payload) ? $payload : []);
        }

        if ($channel === 'webhook') {
            return $this->dispatchWebhookJob(is_array($payload) ? $payload : []);
        }

        return __('Unknown notification channel.', 'site-add-on-watchdog');
    }

    private function logWebhookFailure(string $message): void
    {
        if (function_exists('wp_debug_log')) {
            wp_debug_log($message);

            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($message);
        }
    }

    /**
     * @param Risk[] $risks
     */
    private function formatPlainTextMessage(array $risks): string
    {
        if (empty($risks)) {
            return implode("\n", [
                __('No plugin risks detected on your site at this time.', 'site-add-on-watchdog'),
                '',
                sprintf(
                    /* translators: %s is the URL to the Plugins page in the WordPress admin. */
                    __('Review plugins here: %s', 'site-add-on-watchdog'),
                    esc_url(admin_url('plugins.php'))
                ),
            ]);
        }

        $lines = [
            __('Potential plugin risks detected on your site:', 'site-add-on-watchdog'),
            '',
        ];

        foreach ($risks as $risk) {
            $lines[] = sprintf(
                '%s',
                $risk->pluginName
            );
            $lines[] = sprintf(
                /* translators: %s is the currently installed plugin version. */
                __('Current version: %s', 'site-add-on-watchdog'),
                $risk->localVersion ?? __('Unknown', 'site-add-on-watchdog')
            );
            $lines[] = sprintf(
                /* translators: %s is the latest plugin version available in the directory. */
                __('Available version: %s', 'site-add-on-watchdog'),
                $risk->remoteVersion ?? __('N/A', 'site-add-on-watchdog')
            );
            foreach ($risk->reasons as $reason) {
                $lines[] = sprintf('- %s', $reason);
            }
            $lines[] = '';
        }

        $lines[] = sprintf(
            /* translators: %s is the URL to the Updates page in the WordPress admin. */
            __('Update plugins here: %s', 'site-add-on-watchdog'),
            esc_url(admin_url('update-core.php'))
        );

        return implode("\n", $lines);
    }

    /**
     * @param Risk[] $risks
     */
    private function formatSlackMessage(array $risks, string $plainTextReport): array
    {
        $hasRisks  = ! empty($risks);
        $adminUrl  = admin_url('admin.php?page=site-add-on-watchdog');
        $updateUrl = admin_url('update-core.php');
        $blocks    = [
            [
                'type' => 'header',
                'text' => [
                    'type'  => 'plain_text',
                    'text'  => __('Site Add-on Watchdog Risk Alert', 'site-add-on-watchdog'),
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $hasRisks
                        ? __('Potential plugin risks detected on your site:', 'site-add-on-watchdog')
                        : __('No plugin risks detected on your site at this time.', 'site-add-on-watchdog'),
                ],
            ],
        ];

        if ($hasRisks) {
            $blocks[] = ['type' => 'divider'];
        }

        foreach ($risks as $risk) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $this->formatSlackRiskSection($risk),
                ],
            ];
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        '<%s|%s>',
                        $adminUrl,
                        __('Open the Watchdog dashboard', 'site-add-on-watchdog')
                    ),
                ],
            ],
        ];

        $blocks[] = [
            'type'     => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type'  => 'plain_text',
                        'text'  => __('Review updates', 'site-add-on-watchdog'),
                        'emoji' => true,
                    ],
                    'url'  => $updateUrl,
                    'style' => 'primary',
                ],
                [
                    'type' => 'button',
                    'text' => [
                        'type'  => 'plain_text',
                        'text'  => __('View dashboard', 'site-add-on-watchdog'),
                        'emoji' => true,
                    ],
                    'url'  => $adminUrl,
                ],
            ],
        ];

        return [
            'username'    => 'Site Add-on Watchdog',
            'text'        => $plainTextReport,
            'blocks'      => $blocks,
            'attachments' => [
                [
                    'color' => '#2271b1',
                    'text'  => __('Stay ahead of plugin risks with Site Add-on Watchdog.', 'site-add-on-watchdog'),
                ],
            ],
        ];
    }

    private function formatSlackRiskSection(Risk $risk): string
    {
        $lines   = [];
        $lines[] = sprintf('*%s*', $risk->pluginName);
        $lines[] = sprintf(
            '• %s %s',
            __('Current version', 'site-add-on-watchdog'),
            $risk->localVersion ?? __('Unknown', 'site-add-on-watchdog')
        );
        $lines[] = sprintf(
            '• %s %s',
            __('Directory version', 'site-add-on-watchdog'),
            $risk->remoteVersion ?? __('N/A', 'site-add-on-watchdog')
        );

        foreach ($risk->reasons as $reason) {
            $lines[] = '• ' . $reason;
        }

        if (! empty($risk->details['vulnerabilities'])) {
            foreach ($risk->details['vulnerabilities'] as $vulnerability) {
                $summary = [];
                if (! empty($vulnerability['severity_label'])) {
                    $severity      = (string) $vulnerability['severity'];
                    $severityLabel = (string) $vulnerability['severity_label'];
                    $summary[]     = $this->formatSlackSeverity($severity, $severityLabel);
                }
                if (! empty($vulnerability['title'])) {
                    $summary[] = (string) $vulnerability['title'];
                }
                if (! empty($vulnerability['cve'])) {
                    $summary[] = (string) $vulnerability['cve'];
                }
                if (! empty($vulnerability['fixed_in'])) {
                    $summary[] = sprintf(
                        /* translators: %s is a plugin version number */
                        __('Fixed in %s', 'site-add-on-watchdog'),
                        $vulnerability['fixed_in']
                    );
                }

                if (! empty($summary)) {
                    $lines[] = '• ' . implode(' - ', $summary);
                }
            }
        }

        return implode("\n", $lines);
    }

    private function formatSlackSeverity(string $severity, string $label): string
    {
        $emojiMap = [
            'severe' => '🚨',
            'high'   => '🔴',
            'medium' => '🟠',
            'low'    => '🟢',
        ];

        $emoji = $emojiMap[strtolower($severity)] ?? '⚪';

        return $emoji . ' ' . $label;
    }

    private function formatDiscordMessage(array $risks, string $plainTextReport): array
    {
        return [
            'username' => 'Site Add-on Watchdog',
            'content'  => $plainTextReport,
        ];
    }

    /**
     * @param Risk[] $risks
     */
    private function formatTeamsMessage(array $risks): array
    {
        $hasRisks   = ! empty($risks);
        $adminUrl   = admin_url('admin.php?page=site-add-on-watchdog');
        $updateUrl  = admin_url('update-core.php');
        $riskBlocks = [];
        $introText  = __(
            'Review the cards below for plugin, version, and vulnerability details.',
            'site-add-on-watchdog'
        );
        $noRiskText = __('Everything looks good after the latest scan.', 'site-add-on-watchdog');

        foreach ($risks as $risk) {
            $riskBlocks[] = [
                'activityTitle' => $risk->pluginName,
                'facts'         => $this->formatTeamsRiskFacts($risk),
                'markdown'      => true,
            ];
        }

        $sections = [
            [
                'activityTitle' => $hasRisks
                    ? __('Potential plugin risks detected on your site:', 'site-add-on-watchdog')
                    : __('No plugin risks detected on your site at this time.', 'site-add-on-watchdog'),
                'markdown'      => true,
                'text'          => $hasRisks ? $introText : $noRiskText,
            ],
        ];

        if ($hasRisks) {
            $sections = array_merge($sections, $riskBlocks);
        }

        return [
            '@type'    => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary'  => __('Site Add-on Watchdog Risk Alert', 'site-add-on-watchdog'),
            'themeColor' => '2271B1',
            'title'      => __('Site Add-on Watchdog Risk Alert', 'site-add-on-watchdog'),
            'sections'   => $sections,
            'potentialAction' => [
                [
                    '@type'  => 'OpenUri',
                    'name'   => __('Review updates', 'site-add-on-watchdog'),
                    'targets' => [
                        [
                            'os'  => 'default',
                            'uri' => $updateUrl,
                        ],
                    ],
                ],
                [
                    '@type'  => 'OpenUri',
                    'name'   => __('Open Watchdog dashboard', 'site-add-on-watchdog'),
                    'targets' => [
                        [
                            'os'  => 'default',
                            'uri' => $adminUrl,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{name:string, value:string}>
     */
    private function formatTeamsRiskFacts(Risk $risk): array
    {
        $facts   = [];
        $facts[] = [
            'name'  => __('Current version', 'site-add-on-watchdog'),
            'value' => (string) ($risk->localVersion ?? __('Unknown', 'site-add-on-watchdog')),
        ];
        $facts[] = [
            'name'  => __('Directory version', 'site-add-on-watchdog'),
            'value' => (string) ($risk->remoteVersion ?? __('N/A', 'site-add-on-watchdog')),
        ];

        if (! empty($risk->reasons)) {
            $facts[] = [
                'name'  => __('Reasons', 'site-add-on-watchdog'),
                'value' => implode("\n", $risk->reasons),
            ];
        }

        if (! empty($risk->details['vulnerabilities'])) {
            $labels = [];
            foreach ($risk->details['vulnerabilities'] as $vulnerability) {
                $summary = [];
                if (! empty($vulnerability['severity_label'])) {
                    $summary[] = '[' . $vulnerability['severity_label'] . ']';
                }
                if (! empty($vulnerability['title'])) {
                    $summary[] = (string) $vulnerability['title'];
                }
                if (! empty($vulnerability['cve'])) {
                    $summary[] = (string) $vulnerability['cve'];
                }
                if (! empty($vulnerability['fixed_in'])) {
                    $summary[] = sprintf(
                        /* translators: %s is a plugin version number */
                        __('Fixed in %s', 'site-add-on-watchdog'),
                        $vulnerability['fixed_in']
                    );
                }

                if (! empty($summary)) {
                    $labels[] = implode(' - ', $summary);
                }
            }

            if (! empty($labels)) {
                $facts[] = [
                    'name'  => __('Vulnerabilities', 'site-add-on-watchdog'),
                    'value' => implode("\n", $labels),
                ];
            }
        }

        return $facts;
    }

    /**
     * @param Risk[] $risks
     */
    private function formatEmailMessage(array $risks): string
    {
        $brandColor  = '#1d2327';
        $accentColor = '#2271b1';
        $background  = '#f6f7f7';
        $containerCss = implode(' ', [
            'margin:0 auto;',
            'max-width:680px;',
            'width:100%;',
            'font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;',
            'color:#1d2327;',
        ]);

        if (empty($risks)) {
            $pluginsUrl = esc_url(admin_url('plugins.php'));
            return sprintf(
                '<div style="background:%1$s; padding:22px 24px; border-radius:10px; border:1px solid #dcdcde;">'
                . '<h1 style="margin:0 0 10px 0; font-size:22px; color:%2$s;">%3$s</h1>'
                . '<p style="font-size:14px; line-height:1.7; margin:0 0 10px 0;">%4$s</p>'
                . '<p style="font-size:14px; line-height:1.7; margin:0 0 16px 0;">%5$s</p>'
                . '<a href="%6$s" style="display:inline-block; padding:10px 16px; background:%7$s;'
                . ' color:#ffffff; text-decoration:none; border-radius:6px; font-weight:600;">%8$s</a>'
                . '</div>'
                . '<p style="font-size:12px; color:#4b5563; margin:12px 0 0 0;">%9$s</p>',
                esc_attr($background),
                esc_attr($brandColor),
                esc_html__('Site Add-on Watchdog', 'site-add-on-watchdog'),
                esc_html__('Latest scan completed — no risks detected.', 'site-add-on-watchdog'),
                esc_html__('No plugin risks detected on your site at this time.', 'site-add-on-watchdog'),
                $pluginsUrl,
                esc_attr($accentColor),
                esc_html__('Review plugins', 'site-add-on-watchdog'),
                esc_html__('You are receiving this update from Site Add-on Watchdog.', 'site-add-on-watchdog')
            );
        }
        $cards = '';

        foreach ($risks as $risk) {
            $reasons = '';
            foreach ($risk->reasons as $reason) {
                $reasons .= sprintf('<li style="margin-bottom:6px; line-height:1.5;">%s</li>', esc_html($reason));
            }

            if (! empty($risk->details['vulnerabilities'])) {
                foreach ($risk->details['vulnerabilities'] as $vulnerability) {
                    $title = isset($vulnerability['title']) ? (string) $vulnerability['title'] : '';
                    $cve   = isset($vulnerability['cve']) ? (string) $vulnerability['cve'] : '';
                    $fixed = isset($vulnerability['fixed_in']) ? (string) $vulnerability['fixed_in'] : '';
                    $badge = $this->formatSeverityBadge($vulnerability);

                    $label = trim($title . ($cve !== '' ? ' - ' . $cve : ''));
                    if ($fixed !== '') {
                        /* translators: %s is a plugin version number. */
                        $label .= ' ' . sprintf(__('(Fixed in %s)', 'site-add-on-watchdog'), $fixed);
                    }

                    if ($label !== '') {
                        $content = $badge;
                        if ($content !== '' && $label !== '') {
                            $content .= ' ';
                        }
                        $content .= esc_html($label);

                        $reasons .= sprintf('<li style="margin-bottom:6px; line-height:1.5;">%s</li>', $content);
                    }
                }
            }

            $cards .= sprintf(
                '<tr><td style="padding:10px 12px;">'
                . '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0"'
                . ' style="border:1px solid #e6e6e6; border-radius:10px; overflow:hidden;">'
                . '<tr>'
                . '<td style="background:%1$s; color:#ffffff; padding:14px 16px;'
                . ' font-weight:700; font-size:16px;">%2$s</td>'
                . '</tr>'
                . '<tr>'
                . '<td style="padding:14px 16px; background:#ffffff;">'
                . '<p style="margin:0 0 6px 0; font-size:13px; color:#4b5563;">'
                . '%3$s: <strong style="color:#1d2327;">%4$s</strong>'
                . '<span style="color:#4b5563;"> | </span>'
                . '%5$s: <strong style="color:#1d2327;">%6$s</strong>'
                . '</p>'
                . '<ul style="margin:10px 0 0 18px; padding:0; color:#1d2327;">%7$s</ul>'
                . '</td>'
                . '</tr>'
                . '</table>'
                . '</td></tr>',
                esc_attr($accentColor),
                esc_html($risk->pluginName),
                esc_html__('Current Version', 'site-add-on-watchdog'),
                esc_html($risk->localVersion ?? __('Unknown', 'site-add-on-watchdog')),
                esc_html__('Directory Version', 'site-add-on-watchdog'),
                esc_html($risk->remoteVersion ?? __('N/A', 'site-add-on-watchdog')),
                $reasons
            );
        }

        $updateUrl = esc_url(admin_url('update-core.php'));

        return sprintf(
            '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0"'
            . ' style="background:%1$s; padding:24px 0;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="%2$s">'
            . '<tr>'
            . '<td style="background:%3$s; color:#ffffff; padding:22px 26px;'
            . ' border-radius:10px 10px 0 0;">'
            . '<h1 style="margin:0; font-size:22px;">%4$s</h1>'
            . '<p style="margin:6px 0 0; font-size:14px;">%5$s</p>'
            . '</td>'
            . '</tr>'
            . '<tr>'
            . '<td style="background:#ffffff; border-left:1px solid #dcdcde;'
            . ' border-right:1px solid #dcdcde;">'
            . '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0">%6$s</table>'
            . '</td>'
            . '</tr>'
            . '<tr>'
            . '<td style="background:#ffffff; border:1px solid #dcdcde; border-top:0;'
            . ' padding:16px 26px 22px 26px;">'
            . '<p style="margin:0 0 14px 0; font-size:14px; line-height:1.6;">%7$s</p>'
            . '<a href="%8$s" style="display:inline-block; padding:10px 16px; background:%9$s; color:#ffffff;'
            . ' text-decoration:none; border-radius:6px; font-weight:600;">%10$s</a>'
            . '</td>'
            . '</tr>'
            . '<tr>'
            . '<td style="text-align:center; font-size:12px; color:#4b5563; padding:14px 10px;">%11$s</td>'
            . '</tr>'
            . '</table>'
            . '</td></tr></table>',
            esc_attr($background),
            esc_attr($containerCss),
            esc_attr($brandColor),
            esc_html__('Site Add-on Watchdog', 'site-add-on-watchdog'),
            esc_html__('Potential plugin risks detected on your site', 'site-add-on-watchdog'),
            $cards,
            esc_html__(
                'These plugins need security or maintenance updates. Update them as soon as possible.',
                'site-add-on-watchdog'
            ),
            $updateUrl,
            esc_attr($accentColor),
            esc_html__('Review updates', 'site-add-on-watchdog'),
            esc_html__('You are receiving this update from Site Add-on Watchdog.', 'site-add-on-watchdog')
        );
    }

    private function formatSeverityBadge(array $vulnerability): string
    {
        if (empty($vulnerability['severity']) || empty($vulnerability['severity_label'])) {
            return '';
        }

        $severity = (string) $vulnerability['severity'];
        $label    = (string) $vulnerability['severity_label'];
        $style    = $this->getEmailSeverityStyle($severity);

        return sprintf(
            '<span style="%s">%s</span>',
            esc_attr($style),
            esc_html($label)
        );
    }

    private function getEmailSeverityStyle(string $severity): string
    {
        $baseStyle = 'display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; '
            . 'font-weight:600; text-transform:uppercase; letter-spacing:0.04em;';

        $palette = [
            'low'    => ['background' => '#e7f7ed', 'color' => '#1c5f3a'],
            'medium' => ['background' => '#fff4d6', 'color' => '#7a5a00'],
            'high'   => ['background' => '#fde4df', 'color' => '#922424'],
            'severe' => ['background' => '#fbe0e6', 'color' => '#80102a'],
        ];

        $colors = $palette[$severity] ?? $palette['low'];

        return sprintf(
            '%s background:%s; color:%s;',
            $baseStyle,
            $colors['background'],
            $colors['color']
        );
    }

    /**
     * @return string[]
     */
    private function parseRecipients(string $recipients): array
    {
        return array_filter(array_map('trim', explode(',', $recipients)));
    }

    /**
     * @return string[]
     */
    private function getAdministratorEmails(): array
    {
        $users = get_users([
            'role'   => 'administrator',
            'fields' => ['user_email'],
        ]);

        $emails = [];
        foreach ($users as $user) {
            if (is_object($user) && isset($user->user_email)) {
                $emails[] = trim((string) $user->user_email);
                continue;
            }

            if (is_array($user) && isset($user['user_email'])) {
                $emails[] = trim((string) $user['user_email']);
            }
        }

        $sanitized = [];
        foreach (array_filter($emails) as $email) {
            $clean = sanitize_email($email);
            if ($clean === '' || ! is_email($clean)) {
                continue;
            }

            $sanitized[] = $clean;
        }

        return $sanitized;
    }

    /**
     * @param string[] $emails
     * @return string[]
     */
    private function uniqueEmails(array $emails): array
    {
        $unique = [];
        $seen   = [];

        foreach ($emails as $email) {
            $sanitized = sanitize_email($email);
            if ($sanitized === '' || ! is_email($sanitized)) {
                continue;
            }

            $normalized = strtolower($sanitized);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $unique[]          = $sanitized;
        }

        return $unique;
    }
}
