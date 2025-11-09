<?php

namespace Watchdog\Repository;

class SettingsRepository
{
    private const OPTION = 'wp_watchdog_settings';

    public function get(): array
    {
        $defaults = [
            'email_enabled'        => true,
            'email_recipients'     => get_option('admin_email'),
            'discord_enabled'      => false,
            'discord_webhook'      => '',
            'webhook_enabled'      => false,
            'webhook_url'          => '',
            'wpscan_api_key'       => '',
            'last_notification'    => '',
        ];

        $settings = get_option(self::OPTION, []);
        if (! is_array($settings)) {
            $settings = [];
        }

        return array_merge($defaults, $settings);
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
}
