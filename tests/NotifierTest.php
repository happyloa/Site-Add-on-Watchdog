<?php

use Brain\Monkey\Functions;
use Watchdog\Models\Risk;
use Watchdog\Notifier;
use Watchdog\Repository\SettingsRepository;

class NotifierTest extends TestCase
{
    public function testAdministratorsAreIncludedWhenNoCustomRecipientsAreConfigured(): void
    {
        $settings = [
            'notifications' => [
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
                ],
                'wpscan_api_key' => '',
            ],
        ];

        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn($settings);

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

        Functions\when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        Functions\when('esc_url')->alias(static fn ($url) => $url);
        Functions\when('esc_html')->alias(static fn ($text) => $text);
        Functions\when('esc_attr')->alias(static fn ($text) => $text);
        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('esc_html__')->alias(static fn ($text) => $text);
        Functions\when('sanitize_email')->alias(static fn ($email) => strtolower(trim((string) $email)));
        Functions\when('is_email')->alias(static fn ($email) => $email !== '' && str_contains($email, '@'));

        Functions\expect('wp_mail')
            ->once()
            ->withArgs(function ($recipients, $subject, $body, $headers) {
                self::assertSame(['admin@example.com', 'second@example.com'], $recipients);
                self::assertSame('Plugin Watchdog Risk Alert', $subject);
                self::assertIsString($body);
                self::assertStringContainsString('<table', $body);
                self::assertStringContainsString('https://example.com/wp-admin/update-core.php', $body);
                self::assertSame(['Content-Type: text/html; charset=UTF-8'], $headers);

                return true;
            });

        $notifier = new Notifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', null, ['Example reason']),
        ]);
    }

    public function testConfiguredRecipientsAreMergedAndDeduplicatedWithAdministrators(): void
    {
        $settings = [
            'notifications' => [
                'frequency' => 'daily',
                'email'     => [
                    'enabled'    => true,
                    'recipients' => 'Admin@example.com, custom@example.com',
                ],
                'discord'   => [
                    'enabled' => false,
                    'webhook' => '',
                ],
                'webhook'   => [
                    'enabled' => false,
                    'url'     => '',
                ],
                'wpscan_api_key' => '',
            ],
        ];

        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn($settings);

        Functions\expect('get_users')
            ->once()
            ->with([
                'role'   => 'administrator',
                'fields' => ['user_email'],
            ])
            ->andReturn([
                ['user_email' => 'admin@example.com'],
                ['user_email' => 'other@example.com'],
            ]);

        Functions\when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        Functions\when('esc_url')->alias(static fn ($url) => $url);
        Functions\when('esc_html')->alias(static fn ($text) => $text);
        Functions\when('esc_attr')->alias(static fn ($text) => $text);
        Functions\when('__')->alias(static fn ($text) => $text);
        Functions\when('esc_html__')->alias(static fn ($text) => $text);
        Functions\when('sanitize_email')->alias(static fn ($email) => strtolower(trim((string) $email)));
        Functions\when('is_email')->alias(static fn ($email) => $email !== '' && str_contains($email, '@'));

        Functions\expect('wp_mail')
            ->once()
            ->withArgs(function ($recipients, $subject, $body, $headers) {
                self::assertSame([
                    'admin@example.com',
                    'custom@example.com',
                    'other@example.com',
                ], $recipients);
                self::assertSame('Plugin Watchdog Risk Alert', $subject);
                self::assertIsString($body);
                self::assertStringContainsString('Current Version', $body);
                self::assertSame(['Content-Type: text/html; charset=UTF-8'], $headers);

                return true;
            });

        $notifier = new Notifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', null, ['Example reason']),
        ]);
    }
}
