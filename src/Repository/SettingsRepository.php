<?php

namespace Watchdog\Repository;

class SettingsRepository
{
    private const OPTION = 'wp_watchdog_settings';

    public function get(): array
    {
        $defaults = [
            'email_enabled'     => true,
            'email_recipients'  => '',
            'discord_enabled'   => false,
            'discord_webhook'   => '',
            'webhook_enabled'   => false,
            'webhook_url'       => '',
            'wpscan_api_key'    => '',
            'last_notification' => '',
        ];

        $stored = get_option(self::OPTION);
        if ($stored === false) {
            $defaults['email_recipients'] = $this->buildAdministratorEmailList();

            return $defaults;
        }

        if (! is_array($stored)) {
            $stored = [];
        }

        $settings = array_merge($defaults, $stored);
        if ($settings['email_recipients'] === '') {
            $settings['email_recipients'] = $this->buildAdministratorEmailList();
        }

        return $settings;
    }

    public function save(array $settings): void
    {
        $current = $this->get();

        $filtered = [
            'email_enabled'     => ! empty($settings['email_enabled']),
            'email_recipients'  => sanitize_text_field($settings['email_recipients'] ?? ''),
            'discord_enabled'   => ! empty($settings['discord_enabled']),
            'discord_webhook'   => esc_url_raw($settings['discord_webhook'] ?? ''),
            'webhook_enabled'   => ! empty($settings['webhook_enabled']),
            'webhook_url'       => esc_url_raw($settings['webhook_url'] ?? ''),
            'wpscan_api_key'    => sanitize_text_field($settings['wpscan_api_key'] ?? ''),
            'last_notification' => $current['last_notification'] ?? '',
        ];

        update_option(self::OPTION, $filtered, false);
    }

    public function saveNotificationHash(string $hash): void
    {
        $settings = $this->get();
        $settings['last_notification'] = $hash;
        update_option(self::OPTION, $settings, false);
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
