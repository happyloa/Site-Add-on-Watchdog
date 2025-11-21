<?php

use Brain\Monkey\Functions;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;

class SettingsRepositoryTest extends TestCase
{
    public function testPrefillsAdministratorsWhenOptionIsMissing(): void
    {
        Functions\when('get_option')->alias(static function ($option, $default = false) {
            if ($option === 'wp_watchdog_settings') {
                return false;
            }

            if ($option === 'admin_email') {
                return 'owner@example.com';
            }

            return $default;
        });

        Functions\expect('get_users')
            ->once()
            ->with([
                'role'   => 'administrator',
                'fields' => ['user_email'],
            ])
            ->andReturn([
                (object) ['user_email' => 'admin@example.com'],
                ['user_email' => 'second@example.com'],
            ]);

        $repository = new SettingsRepository();
        $settings   = $repository->get();

        self::assertSame('admin@example.com, second@example.com', $settings['notifications']['email']['recipients']);
    }

    public function testFallsBackToAdminEmailWhenNoAdministratorsFound(): void
    {
        Functions\when('get_option')->alias(static function ($option, $default = false) {
            if ($option === 'wp_watchdog_settings') {
                return false;
            }

            if ($option === 'admin_email') {
                return 'owner@example.com';
            }

            return $default;
        });

        Functions\expect('get_users')
            ->once()
            ->with([
                'role'   => 'administrator',
                'fields' => ['user_email'],
            ])
            ->andReturn([]);

        $repository = new SettingsRepository();
        $settings   = $repository->get();

        self::assertSame('owner@example.com', $settings['notifications']['email']['recipients']);
    }

    public function testReturnsTestingFrequencyFromStoredSettings(): void
    {
        Functions\when('get_option')->alias(static function ($option, $default = false) {
            if ($option === 'wp_watchdog_settings') {
                return [
                    'notifications' => [
                        'frequency' => 'testing',
                        'email'     => [
                            'enabled'    => true,
                            'recipients' => 'stored@example.com',
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
                        'wpscan_api_key' => '',
                    ],
                    'last_notification' => '',
                ];
            }

            return $default;
        });

        $repository = new SettingsRepository();
        $settings   = $repository->get();

        self::assertSame('testing', $settings['notifications']['frequency']);
        self::assertSame(0, $settings['notifications']['testing_expires_at']);
    }

    public function testSavesTestingFrequency(): void
    {
        Functions\when('get_option')->alias(static function ($option, $default = false) {
            if ($option === 'wp_watchdog_settings') {
                return [
                    'notifications' => [
                        'frequency' => 'daily',
                        'email'     => [
                            'enabled'    => true,
                            'recipients' => 'stored@example.com',
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
                        'wpscan_api_key' => '',
                    ],
                    'last_notification' => '',
                ];
            }

            if ($option === 'admin_email') {
                return 'owner@example.com';
            }

            return $default;
        });

        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);

        $updated = null;
        Functions\when('update_option')->alias(static function ($option, $value) use (&$updated) {
            if ($option === 'wp_watchdog_settings') {
                $updated = $value;

                return true;
            }

            return false;
        });

        $repository = new SettingsRepository();
        $startTime  = time();
        $repository->save([
            'notifications' => [
                'frequency' => 'testing',
                'email'     => [
                    'enabled'    => true,
                    'recipients' => 'one@example.com',
                ],
                'discord'   => [
                    'enabled' => false,
                    'webhook' => '',
                ],
                'slack'     => [
                    'enabled' => true,
                    'webhook' => 'https://example.com/slack',
                ],
                'teams'     => [
                    'enabled' => true,
                    'webhook' => 'https://example.com/teams',
                ],
                'webhook'   => [
                    'enabled' => false,
                    'url'     => '',
                    'secret'  => '',
                ],
                'wpscan_api_key' => '',
            ],
        ]);

        $endTime = time();

        self::assertIsArray($updated);
        self::assertSame('testing', $updated['notifications']['frequency']);
        self::assertTrue($updated['notifications']['slack']['enabled']);
        self::assertSame('https://example.com/slack', $updated['notifications']['slack']['webhook']);
        self::assertTrue($updated['notifications']['teams']['enabled']);
        self::assertSame('https://example.com/teams', $updated['notifications']['teams']['webhook']);
        $expectedMin = $startTime + (3 * 3600);
        $expectedMax = $endTime + (3 * 3600);
        self::assertGreaterThanOrEqual($expectedMin, $updated['notifications']['testing_expires_at']);
        self::assertLessThanOrEqual($expectedMax, $updated['notifications']['testing_expires_at']);
        self::assertSame(RiskRepository::DEFAULT_HISTORY_RETENTION, $updated['history']['retention']);
    }

    public function testKeepsExistingTestingExpirationWhenAlreadyInTestingMode(): void
    {
        Functions\when('get_option')->alias(static function ($option, $default = false) {
            if ($option === 'wp_watchdog_settings') {
                return [
                    'notifications' => [
                        'frequency'          => 'testing',
                        'testing_expires_at' => 12345,
                        'email'              => [
                            'enabled'    => true,
                            'recipients' => 'stored@example.com',
                        ],
                        'discord'            => [
                            'enabled' => false,
                            'webhook' => '',
                        ],
                        'slack'              => [
                            'enabled' => false,
                            'webhook' => '',
                        ],
                        'teams'              => [
                            'enabled' => false,
                            'webhook' => '',
                        ],
                        'webhook'            => [
                            'enabled' => false,
                            'url'     => '',
                            'secret'  => '',
                        ],
                        'wpscan_api_key'     => '',
                    ],
                    'last_notification' => '',
                ];
            }

            if ($option === 'admin_email') {
                return 'owner@example.com';
            }

            return $default;
        });

        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);

        $updated = null;
        Functions\when('update_option')->alias(static function ($option, $value) use (&$updated) {
            if ($option === 'wp_watchdog_settings') {
                $updated = $value;

                return true;
            }

            return false;
        });

        $repository = new SettingsRepository();
        $repository->save([
            'notifications' => [
                'frequency' => 'testing',
                'email'     => [
                    'enabled'    => true,
                    'recipients' => 'updated@example.com',
                ],
            ],
        ]);

        self::assertIsArray($updated);
        self::assertSame(12345, $updated['notifications']['testing_expires_at']);
    }

    public function testClearsTestingExpirationWhenSwitchingToNonTestingMode(): void
    {
        Functions\when('get_option')->alias(static function ($option, $default = false) {
            if ($option === 'wp_watchdog_settings') {
                return [
                    'notifications' => [
                        'frequency'          => 'testing',
                        'testing_expires_at' => 22222,
                        'email'              => [
                            'enabled'    => true,
                            'recipients' => 'stored@example.com',
                        ],
                        'discord'            => [
                            'enabled' => false,
                            'webhook' => '',
                        ],
                        'slack'              => [
                            'enabled' => false,
                            'webhook' => '',
                        ],
                        'teams'              => [
                            'enabled' => false,
                            'webhook' => '',
                        ],
                        'webhook'            => [
                            'enabled' => false,
                            'url'     => '',
                            'secret'  => '',
                        ],
                        'wpscan_api_key'     => '',
                    ],
                    'last_notification' => '',
                ];
            }

            if ($option === 'admin_email') {
                return 'owner@example.com';
            }

            return $default;
        });

        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);

        $updated = null;
        Functions\when('update_option')->alias(static function ($option, $value) use (&$updated) {
            if ($option === 'wp_watchdog_settings') {
                $updated = $value;

                return true;
            }

            return false;
        });

        $repository = new SettingsRepository();
        $repository->save([
            'notifications' => [
                'frequency' => 'daily',
            ],
        ]);

        self::assertIsArray($updated);
        self::assertSame(0, $updated['notifications']['testing_expires_at']);
    }

    public function testUpdateNotificationFrequencyAppliesValues(): void
    {
        Functions\when('get_option')->alias(static function ($option, $default = false) {
            if ($option === 'wp_watchdog_settings') {
                return [
                    'notifications' => [
                        'frequency'          => 'testing',
                        'testing_expires_at' => 33333,
                        'email'              => [
                            'enabled'    => true,
                            'recipients' => 'stored@example.com',
                        ],
                        'discord'            => [
                            'enabled' => false,
                            'webhook' => '',
                        ],
                        'slack'              => [
                            'enabled' => false,
                            'webhook' => '',
                        ],
                        'teams'              => [
                            'enabled' => false,
                            'webhook' => '',
                        ],
                        'webhook'            => [
                            'enabled' => false,
                            'url'     => '',
                            'secret'  => '',
                        ],
                        'wpscan_api_key'     => '',
                    ],
                    'history' => [
                        'retention' => RiskRepository::DEFAULT_HISTORY_RETENTION,
                    ],
                    'last_notification' => '',
                ];
            }

            if ($option === 'admin_email') {
                return 'owner@example.com';
            }

            return $default;
        });

        $captured = null;
        Functions\when('update_option')->alias(static function ($option, $value) use (&$captured) {
            if ($option === 'wp_watchdog_settings') {
                $captured = $value;

                return true;
            }

            return false;
        });

        $repository = new SettingsRepository();
        $repository->updateNotificationFrequency('daily', 0);

        self::assertIsArray($captured);
        self::assertSame('daily', $captured['notifications']['frequency']);
        self::assertSame(0, $captured['notifications']['testing_expires_at']);
    }

    public function testSavesHistoryRetentionSetting(): void
    {
        Functions\when('get_option')->alias(static function ($option, $default = false) {
            if ($option === 'wp_watchdog_settings') {
                return [
                    'notifications' => [
                        'frequency' => 'daily',
                        'email'     => [
                            'enabled'    => true,
                            'recipients' => 'stored@example.com',
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
                        'wpscan_api_key' => '',
                    ],
                    'history' => [
                        'retention' => RiskRepository::DEFAULT_HISTORY_RETENTION,
                    ],
                    'last_notification' => '',
                ];
            }

            if ($option === 'admin_email') {
                return 'owner@example.com';
            }

            return $default;
        });

        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);

        $updated = null;
        Functions\when('update_option')->alias(static function ($option, $value) use (&$updated) {
            if ($option === 'wp_watchdog_settings') {
                $updated = $value;

                return true;
            }

            return false;
        });

        $repository = new SettingsRepository();
        $current    = $repository->get();

        $repository->save([
            'notifications' => $current['notifications'],
            'history'       => [
                'retention' => '45',
            ],
        ]);

        self::assertIsArray($updated);
        self::assertSame(15, $updated['history']['retention']);
    }

    public function testSaveManualNotificationTimePersistsTimestamp(): void
    {
        Functions\when('get_option')->alias(static function ($option, $default = false) {
            if ($option === 'wp_watchdog_settings') {
                return [
                    'notifications' => [
                        'frequency' => 'daily',
                        'email'     => [
                            'enabled'    => true,
                            'recipients' => 'stored@example.com',
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
                        'wpscan_api_key' => '',
                        'last_manual_notification_at' => 0,
                    ],
                    'history' => [
                        'retention' => RiskRepository::DEFAULT_HISTORY_RETENTION,
                    ],
                    'last_notification' => '',
                ];
            }

            if ($option === 'admin_email') {
                return 'owner@example.com';
            }

            return $default;
        });

        $captured = null;
        Functions\when('update_option')->alias(static function ($option, $value) use (&$captured) {
            if ($option === 'wp_watchdog_settings') {
                $captured = $value;

                return true;
            }

            return false;
        });

        $repository = new SettingsRepository();
        $repository->saveManualNotificationTime(1_700_000_000);

        self::assertIsArray($captured);
        self::assertSame(1_700_000_000, $captured['notifications']['last_manual_notification_at']);
    }
}
