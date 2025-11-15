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
                    __('Plugin Watchdog Risk Alert', 'wp-plugin-watchdog'),
                    $emailReport,
                    ['Content-Type: text/html; charset=UTF-8']
                );
            }
        }

        if (! empty($discordSettings['enabled']) && ! empty($discordSettings['webhook'])) {
            $this->dispatchWebhook($discordSettings['webhook'], [
                'username'  => 'WP Plugin Watchdog',
                'content'   => $plainTextReport,
            ]);
        }

        if (! empty($webhookSettings['enabled']) && ! empty($webhookSettings['url'])) {
            $this->dispatchWebhook($webhookSettings['url'], [
                'message' => $plainTextReport,
                'risks'   => array_map(static fn (Risk $risk): array => $risk->toArray(), $risks),
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

            error_log($message);
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

            error_log($message);
            set_transient('wp_watchdog_webhook_error', $message, $expiration);

            return;
        }

        delete_transient('wp_watchdog_webhook_error');
    }

    /**
     * @param Risk[] $risks
     */
    private function formatPlainTextMessage(array $risks): string
    {
        $lines = [
            __('Potential plugin risks detected on your site:', 'wp-plugin-watchdog'),
            '',
        ];

        foreach ($risks as $risk) {
            $lines[] = sprintf(
                '%s',
                $risk->pluginName
            );
            $lines[] = sprintf(
                __('Current version: %s', 'wp-plugin-watchdog'),
                $risk->localVersion ?? __('Unknown', 'wp-plugin-watchdog')
            );
            $lines[] = sprintf(
                __('Available version: %s', 'wp-plugin-watchdog'),
                $risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog')
            );
            foreach ($risk->reasons as $reason) {
                $lines[] = sprintf('- %s', $reason);
            }
            $lines[] = '';
        }

        $lines[] = sprintf(
            __('Update plugins here: %s', 'wp-plugin-watchdog'),
            esc_url(admin_url('update-core.php'))
        );

        return implode("\n", $lines);
    }

    /**
     * @param Risk[] $risks
     */
    private function formatEmailMessage(array $risks): string
    {
        $rows = '';

        foreach ($risks as $risk) {
            $reasons = '';
            foreach ($risk->reasons as $reason) {
                $reasons .= sprintf(
                    '<li style="margin-bottom:4px;">%s</li>',
                    esc_html($reason)
                );
            }

            if (! empty($risk->details['vulnerabilities'])) {
                foreach ($risk->details['vulnerabilities'] as $vulnerability) {
                    $title = isset($vulnerability['title']) ? (string) $vulnerability['title'] : '';
                    $cve   = isset($vulnerability['cve']) ? (string) $vulnerability['cve'] : '';
                    $fixed = isset($vulnerability['fixed_in']) ? (string) $vulnerability['fixed_in'] : '';
                    $badge = $this->formatSeverityBadge($vulnerability);

                    $label = trim($title . ($cve !== '' ? ' - ' . $cve : ''));
                    if ($fixed !== '') {
                        $label .= ' ' . sprintf(__('(Fixed in %s)', 'wp-plugin-watchdog'), $fixed);
                    }

                    if ($label !== '') {
                        $content = $badge;
                        if ($content !== '' && $label !== '') {
                            $content .= ' ';
                        }
                        $content .= esc_html($label);

                        $reasons .= sprintf(
                            '<li style="margin-bottom:4px;">%s</li>',
                            $content
                        );
                    }
                }
            }

            $rows .= sprintf(
                '<tr>
                    <td style="padding:12px 16px; border-bottom:1px solid #e6e6e6;">
                        <strong>%1$s</strong>
                        <ul style="margin:8px 0 0 18px; padding:0; list-style:disc; color:#333;">%2$s</ul>
                    </td>
                    <td style="padding:12px 16px; border-bottom:1px solid #e6e6e6; color:#333;">%3$s</td>
                    <td style="padding:12px 16px; border-bottom:1px solid #e6e6e6; color:#333;">%4$s</td>
                </tr>',
                esc_html($risk->pluginName),
                $reasons,
                esc_html($risk->localVersion ?? __('Unknown', 'wp-plugin-watchdog')),
                esc_html($risk->remoteVersion ?? __('N/A', 'wp-plugin-watchdog'))
            );
        }

        $containerStyle = 'font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", ';
        $containerStyle .= 'Roboto, sans-serif; color:#1d2327;';
        $tableStyle = 'border-collapse:collapse; width:100%; max-width:640px; background:#ffffff; ';
        $tableStyle .= 'border:1px solid #dcdcde;';
        $linkStyle = 'color:#2271b1;';
        $introText = esc_html__(
            'These plugins are flagged for security or maintenance updates.'
            . ' Review the details below and update as soon as possible.',
            'wp-plugin-watchdog'
        );
        $updateUrl = esc_url(admin_url('update-core.php'));

        return sprintf(
            '<div style="%1$s">
                <h2 style="font-size:20px; font-weight:600;">%2$s</h2>
                <p style="font-size:14px; line-height:1.6;">%3$s</p>
                <table style="%4$s">
                    <thead>
                        <tr style="background:#f6f7f7; text-align:left; color:#1d2327;">
                            <th style="padding:12px 16px; border-bottom:1px solid #dcdcde;">%5$s</th>
                            <th style="padding:12px 16px; border-bottom:1px solid #dcdcde;">%6$s</th>
                            <th style="padding:12px 16px; border-bottom:1px solid #dcdcde;">%7$s</th>
                        </tr>
                    </thead>
                    <tbody>%8$s</tbody>
                </table>
                <p style="font-size:14px; line-height:1.6;">%9$s <a style="%10$s" href="%11$s">%11$s</a></p>
            </div>',
            esc_attr($containerStyle),
            esc_html__('Plugin updates required on your site', 'wp-plugin-watchdog'),
            $introText,
            esc_attr($tableStyle),
            esc_html__('Plugin', 'wp-plugin-watchdog'),
            esc_html__('Current Version', 'wp-plugin-watchdog'),
            esc_html__('Available Version', 'wp-plugin-watchdog'),
            $rows,
            esc_html__('Update plugins here:', 'wp-plugin-watchdog'),
            esc_attr($linkStyle),
            $updateUrl
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
