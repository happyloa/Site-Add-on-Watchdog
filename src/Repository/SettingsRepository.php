<?php

namespace Watchdog\Repository;

class SettingsRepository
{
    private const OPTION = 'wp_watchdog_settings';

    public function get(): array
    {
        $defaults = [
            'notifications'     => [
                'frequency' => 'daily',
                'email'     => [
                    'enabled'    => true,
                    'recipients' => '',
                ],
                'discord'   => [
                    'enabled' => false,
                    'webhook' => '',
                ],
                'webhook'   => [
                    'enabled' => false,
                    'url'     => '',
                    'secret'  => '',
                ],
                'wpscan_api_key' => '',
            ],
            'last_notification' => '',
        ];

        $stored = get_option(self::OPTION);
        if (! is_array($stored)) {
            if ($stored === false) {
                $defaults['notifications']['email']['recipients'] = $this->buildAdministratorEmailList();

                return $defaults;
            }

            $stored = [];
        }

        $normalized = $this->normalizeStoredSettings($stored);
        $settings   = array_replace_recursive($defaults, $normalized);

        $settings['notifications']['frequency'] = $this->sanitizeFrequency($settings['notifications']['frequency']);

        if ($settings['notifications']['email']['recipients'] === '') {
            $settings['notifications']['email']['recipients'] = $this->buildAdministratorEmailList();
        }

        return $settings;
    }

    public function save(array $settings): void
    {
        $current       = $this->get();
        $notifications = $settings['notifications'] ?? [];

        if (! is_array($notifications)) {
            $notifications = [];
        }

        $email = $notifications['email'] ?? [];
        if (! is_array($email)) {
            $email = [];
        }

        $discord = $notifications['discord'] ?? [];
        if (! is_array($discord)) {
            $discord = [];
        }

        $webhook = $notifications['webhook'] ?? [];
        if (! is_array($webhook)) {
            $webhook = [];
        }

        $filtered = [
            'notifications'     => [
                'frequency' => $this->sanitizeFrequency($notifications['frequency'] ?? ''),
                'email'     => [
                    'enabled'    => ! empty($email['enabled']),
                    'recipients' => sanitize_text_field($email['recipients'] ?? ''),
                ],
                'discord'   => [
                    'enabled' => ! empty($discord['enabled']),
                    'webhook' => esc_url_raw($discord['webhook'] ?? ''),
                ],
                'webhook'   => [
                    'enabled' => ! empty($webhook['enabled']),
                    'url'     => esc_url_raw($webhook['url'] ?? ''),
                    'secret'  => sanitize_text_field($webhook['secret'] ?? ''),
                ],
                'wpscan_api_key' => sanitize_text_field($notifications['wpscan_api_key'] ?? ''),
            ],
            'last_notification' => $current['last_notification'] ?? '',
        ];

        update_option(self::OPTION, $filtered, false);
    }

    public function saveNotificationHash(string $hash): void
    {
        $settings                       = $this->get();
        $settings['last_notification'] = $hash;
        update_option(self::OPTION, $settings, false);
    }

    private function normalizeStoredSettings(array $stored): array
    {
        $notifications = [];

        if (isset($stored['notifications']) && is_array($stored['notifications'])) {
            $notifications = $stored['notifications'];
        }

        $email = $notifications['email'] ?? [];
        if (! is_array($email)) {
            $email = [];
        }

        $discord = $notifications['discord'] ?? [];
        if (! is_array($discord)) {
            $discord = [];
        }

        $webhook = $notifications['webhook'] ?? [];
        if (! is_array($webhook)) {
            $webhook = [];
        }

        $legacy = [
            'email'   => [
                'enabled'    => $stored['email_enabled'] ?? null,
                'recipients' => $stored['email_recipients'] ?? null,
            ],
            'discord' => [
                'enabled' => $stored['discord_enabled'] ?? null,
                'webhook' => $stored['discord_webhook'] ?? null,
            ],
            'webhook' => [
                'enabled' => $stored['webhook_enabled'] ?? null,
                'url'     => $stored['webhook_url'] ?? null,
                'secret'  => $stored['webhook_secret'] ?? null,
            ],
            'frequency'      => $stored['notification_frequency'] ?? null,
            'wpscan_api_key' => $stored['wpscan_api_key'] ?? null,
        ];

        $normalizedNotifications = [
            'frequency' => $notifications['frequency'] ?? $legacy['frequency'],
            'email'     => [
                'enabled'    => $email['enabled'] ?? $legacy['email']['enabled'],
                'recipients' => $email['recipients'] ?? $legacy['email']['recipients'],
            ],
            'discord'   => [
                'enabled' => $discord['enabled'] ?? $legacy['discord']['enabled'],
                'webhook' => $discord['webhook'] ?? $legacy['discord']['webhook'],
            ],
            'webhook'   => [
                'enabled' => $webhook['enabled'] ?? $legacy['webhook']['enabled'],
                'url'     => $webhook['url'] ?? $legacy['webhook']['url'],
                'secret'  => $webhook['secret'] ?? $legacy['webhook']['secret'],
            ],
            'wpscan_api_key' => $notifications['wpscan_api_key'] ?? $legacy['wpscan_api_key'],
        ];

        return [
            'notifications'     => $normalizedNotifications,
            'last_notification' => $stored['last_notification'] ?? '',
        ];
    }

    private function sanitizeFrequency(mixed $frequency): string
    {
        $allowed = ['daily', 'weekly', 'testing', 'manual'];
        if (! is_string($frequency)) {
            $frequency = '';
        }

        if (! in_array($frequency, $allowed, true)) {
            return 'daily';
        }

        return $frequency;
    }

    private function buildAdministratorEmailList(): string
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

        if (empty($emails)) {
            $adminEmail = get_option('admin_email');
            if (is_string($adminEmail) && $adminEmail !== '') {
                $emails[] = trim($adminEmail);
            }
        }

        $unique = [];
        $seen   = [];

        foreach (array_filter($emails) as $email) {
            $normalized = strtolower($email);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $unique[]          = $email;
        }

        return implode(', ', $unique);
    }
}
