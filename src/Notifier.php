<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\SettingsRepository;

class Notifier
{
    public function __construct(private readonly SettingsRepository $settingsRepository)
    {
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
                wp_mail(
                    $recipients,
                    __('Plugin Watchdog Risk Alert', 'wp-plugin-watchdog-main'),
                    $emailReport,
                    ['Content-Type: text/html; charset=UTF-8']
                );
            }
        }

        if (! empty($discordSettings['enabled']) && ! empty($discordSettings['webhook'])) {
            $this->dispatchWebhook($discordSettings['webhook'], $this->formatDiscordMessage($risks, $plainTextReport));
        }

        if (! empty($slackSettings['enabled']) && ! empty($slackSettings['webhook'])) {
            $this->dispatchWebhook($slackSettings['webhook'], $this->formatSlackMessage($risks, $plainTextReport));
        }

        if (! empty($teamsSettings['enabled']) && ! empty($teamsSettings['webhook'])) {
            $this->dispatchWebhook($teamsSettings['webhook'], $this->formatTeamsMessage($risks));
        }

        if (! empty($webhookSettings['enabled']) && ! empty($webhookSettings['url'])) {
            $this->dispatchWebhook($webhookSettings['url'], [
                'message' => $plainTextReport,
                'risks'   => array_map(static fn (Risk $risk): array => $risk->toArray(), $risks),
                'links'   => [
                    'dashboard' => admin_url('admin.php?page=wp-plugin-watchdog'),
                    'updates'   => admin_url('update-core.php'),
                ],
                'meta'    => [
                    'count'      => count($risks),
                    'generated'  => time(),
                    'source'     => 'WP Plugin Watchdog',
                ],
            ], $webhookSettings['secret'] ?? null);
        }
    }

    private function dispatchWebhook(string $url, array $body, ?string $secret = null): void
    {
        $payload = wp_json_encode($body);
        if (! is_string($payload)) {
            $payload = '';
        }
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($secret !== null && $secret !== '') {
            $headers['X-Watchdog-Signature'] = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        }

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => $payload,
            'timeout' => 10,
        ]);

        $expiration = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

        if (is_wp_error($response)) {
            $message = sprintf(
                'WP Plugin Watchdog webhook request to %s failed: %s',
                $url,
                $response->get_error_message()
            );

            $this->logWebhookFailure($message);
            set_transient('wp_watchdog_webhook_error', $message, $expiration);

            return;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            $bodyMessage = trim((string) wp_remote_retrieve_body($response));
            $message     = sprintf(
                'WP Plugin Watchdog webhook request to %s failed with status %d',
                $url,
                $statusCode
            );

            if ($bodyMessage !== '') {
                $message .= ': ' . $bodyMessage;
            }

            $this->logWebhookFailure($message);
            set_transient('wp_watchdog_webhook_error', $message, $expiration);

            return;
        }

        delete_transient('wp_watchdog_webhook_error');
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
                __('No plugin risks detected on your site at this time.', 'wp-plugin-watchdog-main'),
                '',
                sprintf(
                    /* translators: %s is the URL to the Plugins page in the WordPress admin. */
                    __('Review plugins here: %s', 'wp-plugin-watchdog-main'),
                    esc_url(admin_url('plugins.php'))
                ),
            ]);
        }

        $lines = [
            __('Potential plugin risks detected on your site:', 'wp-plugin-watchdog-main'),
            '',
        ];

        foreach ($risks as $risk) {
            $lines[] = sprintf(
                '%s',
                $risk->pluginName
            );
            $lines[] = sprintf(
                /* translators: %s is the currently installed plugin version. */
                __('Current version: %s', 'wp-plugin-watchdog-main'),
                $risk->localVersion ?? __('Unknown', 'wp-plugin-watchdog-main')
            );
            $lines[] = sprintf(
                /* translators: %s is the latest plugin version available in the directory. */
                __('Available version: %s', 'wp-plugin-watchdog-main'),
                $risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog-main')
            );
            foreach ($risk->reasons as $reason) {
                $lines[] = sprintf('- %s', $reason);
            }
            $lines[] = '';
        }

        $lines[] = sprintf(
            /* translators: %s is the URL to the Updates page in the WordPress admin. */
            __('Update plugins here: %s', 'wp-plugin-watchdog-main'),
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
        $adminUrl  = admin_url('admin.php?page=wp-plugin-watchdog');
        $updateUrl = admin_url('update-core.php');
        $blocks    = [
            [
                'type' => 'header',
                'text' => [
                    'type'  => 'plain_text',
                    'text'  => __('WP Plugin Watchdog Risk Alert', 'wp-plugin-watchdog-main'),
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $hasRisks
                        ? __('Potential plugin risks detected on your site:', 'wp-plugin-watchdog-main')
                        : __('No plugin risks detected on your site at this time.', 'wp-plugin-watchdog-main'),
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
                        __('Open the Watchdog dashboard', 'wp-plugin-watchdog-main')
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
                        'text'  => __('Review updates', 'wp-plugin-watchdog-main'),
                        'emoji' => true,
                    ],
                    'url'  => $updateUrl,
                    'style' => 'primary',
                ],
                [
                    'type' => 'button',
                    'text' => [
                        'type'  => 'plain_text',
                        'text'  => __('View dashboard', 'wp-plugin-watchdog-main'),
                        'emoji' => true,
                    ],
                    'url'  => $adminUrl,
                ],
            ],
        ];

        return [
            'username'    => 'WP Plugin Watchdog',
            'text'        => $plainTextReport,
            'blocks'      => $blocks,
            'attachments' => [
                [
                    'color' => '#2271b1',
                    'text'  => __('Stay ahead of plugin risks with WP Plugin Watchdog.', 'wp-plugin-watchdog-main'),
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
            __('Current version', 'wp-plugin-watchdog-main'),
            $risk->localVersion ?? __('Unknown', 'wp-plugin-watchdog-main')
        );
        $lines[] = sprintf(
            '• %s %s',
            __('Directory version', 'wp-plugin-watchdog-main'),
            $risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog-main')
        );

        foreach ($risk->reasons as $reason) {
            $lines[] = '• ' . $reason;
        }

        if (! empty($risk->details['vulnerabilities'])) {
            foreach ($risk->details['vulnerabilities'] as $vulnerability) {
                $summary = [];
                if (! empty($vulnerability['severity_label'])) {
                    $summary[] = $this->formatSlackSeverity((string) $vulnerability['severity'], (string) $vulnerability['severity_label']);
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
                        __('Fixed in %s', 'wp-plugin-watchdog-main'),
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
        $hasRisks = ! empty($risks);
        $adminUrl = admin_url('admin.php?page=wp-plugin-watchdog');
        $updateUrl = admin_url('update-core.php');
        $color    = hexdec('2271b1');

        $fields = [];
        foreach ($risks as $risk) {
            $reasons = $risk->reasons;
            if (! empty($risk->details['vulnerabilities'])) {
                foreach ($risk->details['vulnerabilities'] as $vulnerability) {
                    $label = [];
                    if (! empty($vulnerability['severity_label'])) {
                        $label[] = '[' . $vulnerability['severity_label'] . ']';
                    }
                    if (! empty($vulnerability['title'])) {
                        $label[] = (string) $vulnerability['title'];
                    }
                    if (! empty($vulnerability['cve'])) {
                        $label[] = (string) $vulnerability['cve'];
                    }
                    if (! empty($vulnerability['fixed_in'])) {
                        $label[] = sprintf(
                            /* translators: %s is a plugin version number */
                            __('Fixed in %s', 'wp-plugin-watchdog-main'),
                            $vulnerability['fixed_in']
                        );
                    }

                    if (! empty($label)) {
                        $reasons[] = implode(' - ', $label);
                    }
                }
            }

            $fields[] = [
                'name'   => $risk->pluginName,
                'value'  => sprintf(
                    "*%s:* %s\n*%s:* %s\n%s",
                    __('Current', 'wp-plugin-watchdog-main'),
                    $risk->localVersion ?? __('Unknown', 'wp-plugin-watchdog-main'),
                    __('Directory', 'wp-plugin-watchdog-main'),
                    $risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog-main'),
                    implode("\n", array_map(static fn ($reason) => '• ' . $reason, $reasons))
                ),
                'inline' => false,
            ];
        }

        return [
            'username' => 'WP Plugin Watchdog',
            'content'  => $plainTextReport,
            'embeds'   => [
                [
                    'title'       => __('WP Plugin Watchdog Risk Alert', 'wp-plugin-watchdog-main'),
                    'description' => $hasRisks
                        ? __('Potential plugin risks detected on your site.', 'wp-plugin-watchdog-main')
                        : __('No plugin risks detected on your site at this time.', 'wp-plugin-watchdog-main'),
                    'color'       => $color,
                    'url'         => $adminUrl,
                    'fields'      => $fields,
                    'footer'      => [
                        'text' => sprintf(
                            '%s • %s',
                            __('Review updates', 'wp-plugin-watchdog-main'),
                            $updateUrl
                        ),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param Risk[] $risks
     */
    private function formatTeamsMessage(array $risks): array
    {
        $hasRisks   = ! empty($risks);
        $adminUrl   = admin_url('admin.php?page=wp-plugin-watchdog');
        $updateUrl  = admin_url('update-core.php');
        $riskBlocks = [];

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
                    ? __('Potential plugin risks detected on your site:', 'wp-plugin-watchdog-main')
                    : __('No plugin risks detected on your site at this time.', 'wp-plugin-watchdog-main'),
                'markdown'      => true,
                'text'          => $hasRisks
                    ? __('Review the cards below for plugin, version, and vulnerability details.', 'wp-plugin-watchdog-main')
                    : __('Everything looks good after the latest scan.', 'wp-plugin-watchdog-main'),
            ],
        ];

        if ($hasRisks) {
            $sections = array_merge($sections, $riskBlocks);
        }

        return [
            '@type'    => 'MessageCard',
            '@context' => 'https://schema.org/extensions',
            'summary'  => __('WP Plugin Watchdog Risk Alert', 'wp-plugin-watchdog-main'),
            'themeColor' => '2271B1',
            'title'      => __('WP Plugin Watchdog Risk Alert', 'wp-plugin-watchdog-main'),
            'sections'   => $sections,
            'potentialAction' => [
                [
                    '@type'  => 'OpenUri',
                    'name'   => __('Review updates', 'wp-plugin-watchdog-main'),
                    'targets' => [
                        [
                            'os'  => 'default',
                            'uri' => $updateUrl,
                        ],
                    ],
                ],
                [
                    '@type'  => 'OpenUri',
                    'name'   => __('Open Watchdog dashboard', 'wp-plugin-watchdog-main'),
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
            'name'  => __('Current version', 'wp-plugin-watchdog-main'),
            'value' => (string) ($risk->localVersion ?? __('Unknown', 'wp-plugin-watchdog-main')),
        ];
        $facts[] = [
            'name'  => __('Directory version', 'wp-plugin-watchdog-main'),
            'value' => (string) ($risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog-main')),
        ];

        if (! empty($risk->reasons)) {
            $facts[] = [
                'name'  => __('Reasons', 'wp-plugin-watchdog-main'),
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
                        __('Fixed in %s', 'wp-plugin-watchdog-main'),
                        $vulnerability['fixed_in']
                    );
                }

                if (! empty($summary)) {
                    $labels[] = implode(' - ', $summary);
                }
            }

            if (! empty($labels)) {
                $facts[] = [
                    'name'  => __('Vulnerabilities', 'wp-plugin-watchdog-main'),
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
        $brandColor   = '#1d2327';
        $accentColor  = '#2271b1';
        $background   = '#f6f7f7';
        $containerCss = 'margin:0 auto; max-width:680px; width:100%; font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color:#1d2327;';

        if (empty($risks)) {
            $pluginsUrl = esc_url(admin_url('plugins.php'));
            return sprintf(
                '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="background:%1$s; padding:24px 0;">' .
                '<tr><td align="center">
                    <table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="%2$s">
                        <tr>
                            <td style="background:%3$s; color:#ffffff; padding:22px 26px; border-radius:10px 10px 0 0;">
                                <h1 style="margin:0; font-size:22px;">%4$s</h1>
                                <p style="margin:6px 0 0; font-size:14px;">%5$s</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="background:#ffffff; padding:24px 26px; border:1px solid #dcdcde; border-top:0;">
                                <p style="font-size:14px; line-height:1.7; margin:0 0 12px 0;">%6$s</p>
                                <p style="font-size:14px; line-height:1.7; margin:0 0 18px 0;">%7$s</p>
                                <a href="%8$s" style="display:inline-block; padding:10px 16px; background:%9$s; color:#ffffff; text-decoration:none; border-radius:6px; font-weight:600;">%10$s</a>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align:center; font-size:12px; color:#4b5563; padding:14px 10px;">%11$s</td>
                        </tr>
                    </table>
                </td></tr></table>',
                esc_attr($background),
                esc_attr($containerCss),
                esc_attr($brandColor),
                esc_html__('WP Plugin Watchdog', 'wp-plugin-watchdog-main'),
                esc_html__('Latest scan completed — no risks detected.', 'wp-plugin-watchdog-main'),
                esc_html__('The latest scan did not find any plugins that require attention.', 'wp-plugin-watchdog-main'),
                esc_html__('You can still review your plugins at any time from the admin area.', 'wp-plugin-watchdog-main'),
                $pluginsUrl,
                esc_attr($accentColor),
                esc_html__('Review plugins', 'wp-plugin-watchdog-main'),
                esc_html__('You are receiving this update from WP Plugin Watchdog.', 'wp-plugin-watchdog-main')
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
                        $label .= ' ' . sprintf(__('(Fixed in %s)', 'wp-plugin-watchdog-main'), $fixed);
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
                '<tr><td style="padding:10px 12px;">
                    <table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="border:1px solid #e6e6e6; border-radius:10px; overflow:hidden;">
                        <tr>
                            <td style="background:%1$s; color:#ffffff; padding:14px 16px; font-weight:700; font-size:16px;">%2$s</td>
                        </tr>
                        <tr>
                            <td style="padding:14px 16px; background:#ffffff;">
                                <p style="margin:0 0 6px 0; font-size:13px; color:#4b5563;">
                                    %3$s <strong style="color:#1d2327;">%4$s</strong>
                                    <span style="color:#4b5563;"> | </span>
                                    %5$s <strong style="color:#1d2327;">%6$s</strong>
                                </p>
                                <ul style="margin:10px 0 0 18px; padding:0; color:#1d2327;">%7$s</ul>
                            </td>
                        </tr>
                    </table>
                </td></tr>',
                esc_attr($accentColor),
                esc_html($risk->pluginName),
                esc_html__('Current', 'wp-plugin-watchdog-main'),
                esc_html($risk->localVersion ?? __('Unknown', 'wp-plugin-watchdog-main')),
                esc_html__('Directory', 'wp-plugin-watchdog-main'),
                esc_html($risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog-main')),
                $reasons
            );
        }

        $updateUrl = esc_url(admin_url('update-core.php'));

        return sprintf(
            '<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="background:%1$s; padding:24px 0;">' .
            '<tr><td align="center">
                <table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="%2$s">
                    <tr>
                        <td style="background:%3$s; color:#ffffff; padding:22px 26px; border-radius:10px 10px 0 0;">
                            <h1 style="margin:0; font-size:22px;">%4$s</h1>
                            <p style="margin:6px 0 0; font-size:14px;">%5$s</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff; border-left:1px solid #dcdcde; border-right:1px solid #dcdcde;">
                            <table role="presentation" width="100%%" cellspacing="0" cellpadding="0">%6$s</table>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff; border:1px solid #dcdcde; border-top:0; padding:16px 26px 22px 26px;">
                            <p style="margin:0 0 14px 0; font-size:14px; line-height:1.6;">%7$s</p>
                            <a href="%8$s" style="display:inline-block; padding:10px 16px; background:%9$s; color:#ffffff; text-decoration:none; border-radius:6px; font-weight:600;">%10$s</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="text-align:center; font-size:12px; color:#4b5563; padding:14px 10px;">%11$s</td>
                    </tr>
                </table>
            </td></tr></table>',
            esc_attr($background),
            esc_attr($containerCss),
            esc_attr($brandColor),
            esc_html__('WP Plugin Watchdog', 'wp-plugin-watchdog-main'),
            esc_html__('Potential plugin risks detected on your site', 'wp-plugin-watchdog-main'),
            $cards,
            esc_html__('These plugins are flagged for security or maintenance updates. Review the details below and update as soon as possible.', 'wp-plugin-watchdog-main'),
            $updateUrl,
            esc_attr($accentColor),
            esc_html__('Review updates', 'wp-plugin-watchdog-main'),
            esc_html__('You are receiving this update from WP Plugin Watchdog.', 'wp-plugin-watchdog-main')
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
