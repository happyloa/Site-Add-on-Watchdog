<?php

namespace Watchdog\Repository;

use Watchdog\TestingMode;
use Watchdog\Version;

class SettingsRepository
{
    private const PREFIX = Version::PREFIX;
    private const OPTION = self::PREFIX . '_settings';
    private const LEGACY_OPTION = 'wp_watchdog_settings';

    public function get(): array
    {
        $defaults = [
            'notifications'     => [
                'frequency' => 'daily',
                'daily_time' => '08:00',
                'weekly_day' => 1,
                'weekly_time' => '08:00',
                'email'     => [
                    'enabled'    => true,
                    'recipients' => '',
                ],
                'discord'   => [
                    'enabled' => false,
                    'webhook' => '',
                ],
                'slack'     => [
                    'enabled' => false,
                    'webhook' => '',
                ],
                'teams'     => [
                    'enabled' => false,
                    'webhook' => '',
                ],
                'webhook'   => [
                    'enabled' => false,
                    'url'     => '',
                    'secret'  => '',
                ],
                'testing_expires_at' => 0,
                'wpscan_api_key' => '',
                'last_manual_notification_at' => 0,
                'cron_secret' => '',
            ],
            'last_notification' => '',
            'history'           => [
                'retention' => RiskRepository::DEFAULT_HISTORY_RETENTION,
            ],
        ];

        $stored = get_option(self::OPTION);
        $shouldPersistDefaults = false;
        if (! is_array($stored)) {
            if ($stored === false) {
                $shouldPersistDefaults = true;
            }

            $legacyStored = get_option(self::LEGACY_OPTION);
            if (is_array($legacyStored)) {
                $stored = $legacyStored;
                $shouldPersistDefaults = true;
            } else {
                $stored = [];
            }
        }

        $normalized = $this->normalizeStoredSettings($stored);
        $settings   = array_replace_recursive($defaults, $normalized);

        $settings['notifications']['frequency'] = $this->sanitizeFrequency($settings['notifications']['frequency']);
        $settings['notifications']['daily_time'] = $this->sanitizeTimeOfDay($settings['notifications']['daily_time']);
        $settings['notifications']['weekly_day'] = $this->sanitizeWeekday($settings['notifications']['weekly_day']);
        $settings['notifications']['weekly_time'] = $this->sanitizeTimeOfDay($settings['notifications']['weekly_time']);
        $settings['notifications']['testing_expires_at'] = $this->sanitizeTestingExpiration(
            $settings['notifications']['testing_expires_at'] ?? 0
        );
        $settings['notifications']['last_manual_notification_at'] = $this->sanitizeTimestamp(
            $settings['notifications']['last_manual_notification_at'] ?? 0
        );
        $settings['notifications']['cron_secret'] = $this->sanitizeSecret(
            $settings['notifications']['cron_secret'] ?? ''
        );

        if ($settings['notifications']['cron_secret'] === '') {
            $settings['notifications']['cron_secret'] = $this->generateSecret();
            $shouldPersistDefaults = true;
        }

        $recipientList = $settings['notifications']['email']['recipients'] ?? '';
        if (! is_string($recipientList) || trim($recipientList) === '') {
            $settings['notifications']['email']['recipients'] = $this->buildAdministratorEmailList();
        } else {
            $settings['notifications']['email']['recipients'] = trim($recipientList);
        }

        $settings['history']['retention'] = $this->sanitizeRetention($settings['history']['retention'] ?? null);

        if ($shouldPersistDefaults && $this->canPersist() && ! defined('PHPUNIT_COMPOSER_INSTALL')) {
            update_option(self::OPTION, $settings, false);
        }

        return $settings;
    }

    public function save(array $settings): void
    {
        $current           = $this->get();
        $notifications     = $settings['notifications'] ?? [];
        $previousFrequency = $this->sanitizeFrequency($current['notifications']['frequency'] ?? 'daily');
        $previousTestingExpiration = $current['notifications']['testing_expires_at'] ?? 0;

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

        $slack = $notifications['slack'] ?? [];
        if (! is_array($slack)) {
            $slack = [];
        }

        $teams = $notifications['teams'] ?? [];
        if (! is_array($teams)) {
            $teams = [];
        }

        $webhook = $notifications['webhook'] ?? [];
        if (! is_array($webhook)) {
            $webhook = [];
        }

        $history = $settings['history'] ?? [];
        if (! is_array($history)) {
            $history = [];
        }

        $filtered = [
            'notifications'     => [
                'frequency' => $this->sanitizeFrequency($notifications['frequency'] ?? ''),
                'daily_time' => $this->sanitizeTimeOfDay(
                    $notifications['daily_time'] ?? ($current['notifications']['daily_time'] ?? '08:00')
                ),
                'weekly_day' => $this->sanitizeWeekday(
                    $notifications['weekly_day'] ?? ($current['notifications']['weekly_day'] ?? 1)
                ),
                'weekly_time' => $this->sanitizeTimeOfDay(
                    $notifications['weekly_time'] ?? ($current['notifications']['weekly_time'] ?? '08:00')
                ),
                'email'     => [
                    'enabled'    => ! empty($email['enabled']),
                    'recipients' => sanitize_text_field($email['recipients'] ?? ''),
                ],
                'discord'   => [
                    'enabled' => ! empty($discord['enabled']),
                    'webhook' => esc_url_raw($discord['webhook'] ?? ''),
                ],
                'slack'     => [
                    'enabled' => ! empty($slack['enabled']),
                    'webhook' => esc_url_raw($slack['webhook'] ?? ''),
                ],
                'teams'     => [
                    'enabled' => ! empty($teams['enabled']),
                    'webhook' => esc_url_raw($teams['webhook'] ?? ''),
                ],
                'webhook'   => [
                    'enabled' => ! empty($webhook['enabled']),
                    'url'     => esc_url_raw($webhook['url'] ?? ''),
                    'secret'  => sanitize_text_field($webhook['secret'] ?? ''),
                ],
                'wpscan_api_key' => sanitize_text_field($notifications['wpscan_api_key'] ?? ''),
                'last_manual_notification_at' => $this->sanitizeTimestamp(
                    $notifications['last_manual_notification_at']
                        ?? ($current['notifications']['last_manual_notification_at'] ?? 0)
                ),
                'cron_secret' => $this->sanitizeSecret($notifications['cron_secret'] ?? ''),
            ],
            'last_notification' => $current['last_notification'] ?? '',
            'history'           => [
                'retention' => $this->sanitizeRetention(
                    $history['retention'] ?? ($current['history']['retention'] ?? null)
                ),
            ],
        ];

        $filtered['notifications']['testing_expires_at'] = $this->determineTestingExpiration(
            $previousFrequency,
            $filtered['notifications']['frequency'],
            $previousTestingExpiration
        );

        if ($filtered['notifications']['cron_secret'] === '') {
            $filtered['notifications']['cron_secret'] = $current['notifications']['cron_secret']
                ?? $this->generateSecret();
        }

        if ($this->canPersist()) {
            update_option(self::OPTION, $filtered, false);
        }
    }

    public function saveNotificationHash(string $hash): void
    {
        $settings                       = $this->get();
        $settings['last_notification'] = $hash;
        if ($this->canPersist()) {
            update_option(self::OPTION, $settings, false);
        }
    }

    public function updateNotificationFrequency(string $frequency, int $testingExpiresAt = 0): void
    {
        $settings = $this->get();
        $settings['notifications']['frequency'] = $this->sanitizeFrequency($frequency);
        $settings['notifications']['testing_expires_at'] = $this->sanitizeTestingExpiration($testingExpiresAt);

        if ($this->canPersist()) {
            update_option(self::OPTION, $settings, false);
        }
    }

    public function saveManualNotificationTime(int $timestamp): void
    {
        $settings = $this->get();
        $settings['notifications']['last_manual_notification_at'] = $this->sanitizeTimestamp($timestamp);

        if ($this->canPersist()) {
            update_option(self::OPTION, $settings, false);
        }
    }

    private function canPersist(): bool
    {
        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            return true;
        }

        return function_exists('update_option');
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

        $slack = $notifications['slack'] ?? [];
        if (! is_array($slack)) {
            $slack = [];
        }

        $teams = $notifications['teams'] ?? [];
        if (! is_array($teams)) {
            $teams = [];
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
            'slack'   => [
                'enabled' => $stored['slack_enabled'] ?? null,
                'webhook' => $stored['slack_webhook'] ?? null,
            ],
            'teams'   => [
                'enabled' => $stored['teams_enabled'] ?? null,
                'webhook' => $stored['teams_webhook'] ?? null,
            ],
            'webhook' => [
                'enabled' => $stored['webhook_enabled'] ?? null,
                'url'     => $stored['webhook_url'] ?? null,
                'secret'  => $stored['webhook_secret'] ?? null,
            ],
            'frequency'      => $stored['notification_frequency'] ?? null,
            'wpscan_api_key' => $stored['wpscan_api_key'] ?? null,
            'last_manual_notification_at' => $stored['last_manual_notification_at'] ?? null,
        ];

        $normalizedNotifications = [
            'frequency' => $notifications['frequency'] ?? $legacy['frequency'],
            'daily_time' => $notifications['daily_time'] ?? $legacy['daily_time'] ?? null,
            'weekly_day' => $notifications['weekly_day'] ?? $legacy['weekly_day'] ?? null,
            'weekly_time' => $notifications['weekly_time'] ?? $legacy['weekly_time'] ?? null,
            'email'     => [
                'enabled'    => $email['enabled'] ?? $legacy['email']['enabled'],
                'recipients' => $email['recipients'] ?? $legacy['email']['recipients'],
            ],
            'discord'   => [
                'enabled' => $discord['enabled'] ?? $legacy['discord']['enabled'],
                'webhook' => $discord['webhook'] ?? $legacy['discord']['webhook'],
            ],
            'slack'     => [
                'enabled' => $slack['enabled'] ?? $legacy['slack']['enabled'],
                'webhook' => $slack['webhook'] ?? $legacy['slack']['webhook'],
            ],
            'teams'     => [
                'enabled' => $teams['enabled'] ?? $legacy['teams']['enabled'],
                'webhook' => $teams['webhook'] ?? $legacy['teams']['webhook'],
            ],
            'webhook'   => [
                'enabled' => $webhook['enabled'] ?? $legacy['webhook']['enabled'],
                'url'     => $webhook['url'] ?? $legacy['webhook']['url'],
                'secret'  => $webhook['secret'] ?? $legacy['webhook']['secret'],
            ],
            'wpscan_api_key' => $notifications['wpscan_api_key'] ?? $legacy['wpscan_api_key'],
            'testing_expires_at' => $notifications['testing_expires_at'] ?? null,
            'last_manual_notification_at' => $notifications['last_manual_notification_at']
                ?? $legacy['last_manual_notification_at'],
        ];

        $historyRetention = null;
        if (isset($stored['history']) && is_array($stored['history'])) {
            $historyRetention = $stored['history']['retention'] ?? null;
        } elseif (isset($stored['history_retention'])) {
            $historyRetention = $stored['history_retention'];
        }

        return [
            'notifications'     => $normalizedNotifications,
            'last_notification' => $stored['last_notification'] ?? '',
            'history'           => [
                'retention' => $historyRetention,
            ],
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

    private function sanitizeTimeOfDay(mixed $value): string
    {
        if (! is_string($value)) {
            $value = '';
        }

        $normalized = trim($value);

        if (! preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $normalized)) {
            return '08:00';
        }

        [$hour, $minute] = array_map('intval', explode(':', $normalized));

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function sanitizeWeekday(mixed $value): int
    {
        if (is_string($value) && $value !== '' && is_numeric($value)) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            $value = 1;
        }

        if ($value < 1 || $value > 7) {
            return 1;
        }

        return $value;
    }

    private function determineTestingExpiration(
        string $previousFrequency,
        string $newFrequency,
        mixed $previousExpiration
    ): int {
        if ($newFrequency !== 'testing') {
            return 0;
        }

        $previous = $this->sanitizeTestingExpiration($previousExpiration);

        if ($previousFrequency === 'testing' && $previous > 0) {
            return $previous;
        }

        return time() + $this->testingModeDuration();
    }

    private function testingModeDuration(): int
    {
        return TestingMode::durationInSeconds();
    }

    private function sanitizeTestingExpiration(mixed $value): int
    {
        if (is_string($value) && $value !== '') {
            if (is_numeric($value)) {
                $value = (int) $value;
            } else {
                return 0;
            }
        }

        if (! is_int($value)) {
            return 0;
        }

        return max(0, $value);
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

    private function sanitizeRetention(mixed $retention): int
    {
        if (is_string($retention) && $retention !== '') {
            $retention = (int) $retention;
        }

        if (! is_int($retention)) {
            $retention = RiskRepository::DEFAULT_HISTORY_RETENTION;
        }

        if ($retention < 1) {
            $retention = 1;
        }

        if ($retention > 15) {
            return 15;
        }

        return $retention;
    }

    private function sanitizeTimestamp(mixed $value): int
    {
        if (is_string($value) && $value !== '') {
            if (is_numeric($value)) {
                $value = (int) $value;
            } else {
                return 0;
            }
        }

        if (! is_int($value)) {
            return 0;
        }

        return max(0, $value);
    }

    private function sanitizeSecret(string $secret): string
    {
        $secret = (string) $secret;

        return trim(strip_tags($secret));
    }

    private function generateSecret(): string
    {
        if (function_exists('wp_generate_password')) {
            return wp_generate_password(32, false, false);
        }

        return bin2hex(random_bytes(16));
    }
}
