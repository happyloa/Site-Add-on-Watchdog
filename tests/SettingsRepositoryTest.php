<?php

use Brain\Monkey\Functions;
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

        self::assertIsArray($updated);
        self::assertSame('testing', $updated['notifications']['frequency']);
        self::assertTrue($updated['notifications']['slack']['enabled']);
        self::assertSame('https://example.com/slack', $updated['notifications']['slack']['webhook']);
        self::assertTrue($updated['notifications']['teams']['enabled']);
        self::assertSame('https://example.com/teams', $updated['notifications']['teams']['webhook']);
    }
}
