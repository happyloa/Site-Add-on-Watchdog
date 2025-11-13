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
}
