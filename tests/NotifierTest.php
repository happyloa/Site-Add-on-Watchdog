<?php

use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;
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
                ],
                'wpscan_api_key' => '',
            ],
        ];

        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn($settings);

        expect('get_users')
            ->once()
            ->with([
                'role'   => 'administrator',
                'fields' => ['user_email'],
            ])
            ->andReturn([
                (object) ['user_email' => 'admin@example.com'],
                ['user_email' => 'second@example.com'],
            ]);

        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('sanitize_email')->alias(static fn ($email) => strtolower(trim((string) $email)));
        when('is_email')->alias(static fn ($email) => $email !== '' && str_contains($email, '@'));

        expect('wp_mail')
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
                ],
                'wpscan_api_key' => '',
            ],
        ];

        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn($settings);

        expect('get_users')
            ->once()
            ->with([
                'role'   => 'administrator',
                'fields' => ['user_email'],
            ])
            ->andReturn([
                ['user_email' => 'admin@example.com'],
                ['user_email' => 'other@example.com'],
            ]);

        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('sanitize_email')->alias(static fn ($email) => strtolower(trim((string) $email)));
        when('is_email')->alias(static fn ($email) => $email !== '' && str_contains($email, '@'));

        expect('wp_mail')
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

    public function testWebhookSecretAddsSignatureAndClearsErrorsOnSuccess(): void
    {
        $settings = [
            'notifications' => [
                'frequency' => 'daily',
                'email'     => [
                    'enabled'    => false,
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
                    'enabled' => true,
                    'url'     => 'https://example.com/hook',
                    'secret'  => 'super-secret',
                ],
                'wpscan_api_key' => '',
            ],
        ];

        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn($settings);

        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));
        when('is_wp_error')->alias(static fn () => false);
        when('wp_remote_retrieve_response_code')->alias(static fn ($response) => $response['response']['code'] ?? 0);
        when('wp_remote_retrieve_body')->alias(static fn () => '');

        expect('wp_remote_post')
            ->once()
            ->withArgs(function ($url, $args) {
                self::assertSame('https://example.com/hook', $url);
                self::assertArrayHasKey('headers', $args);
                self::assertArrayHasKey('body', $args);
                self::assertSame('application/json', $args['headers']['Content-Type']);
                self::assertArrayHasKey('X-Watchdog-Signature', $args['headers']);

                $expectedSignature = 'sha256=' . hash_hmac('sha256', $args['body'], 'super-secret');
                self::assertSame($expectedSignature, $args['headers']['X-Watchdog-Signature']);

                return true;
            })
            ->andReturn([
                'response' => ['code' => 204],
                'body'     => '',
            ]);

        expect('delete_transient')
            ->once()
            ->with('wp_watchdog_webhook_error');

        expect('set_transient')->never();

        $notifier = new Notifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', null, ['Example reason']),
        ]);
    }

    public function testWebhookDispatchLogsWpError(): void
    {
        $settings = [
            'notifications' => [
                'frequency' => 'daily',
                'email'     => [
                    'enabled'    => false,
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
                    'enabled' => true,
                    'url'     => 'https://example.com/hook',
                    'secret'  => '',
                ],
                'wpscan_api_key' => '',
            ],
        ];

        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn($settings);

        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));

        $error = new class('Something went wrong') {
            public function __construct(private string $message)
            {
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        };

        when('is_wp_error')->alias(static fn ($value) => $value === $error);

        expect('wp_remote_post')
            ->once()
            ->andReturn($error);

        expect('set_transient')
            ->once()
            ->with('wp_watchdog_webhook_error', 'WP Plugin Watchdog webhook request to https://example.com/hook failed: Something went wrong', 86400);

        expect('delete_transient')->never();

        $notifier = new Notifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', null, ['Example reason']),
        ]);
    }

    public function testWebhookDispatchLogsNon2xxResponses(): void
    {
        $settings = [
            'notifications' => [
                'frequency' => 'daily',
                'email'     => [
                    'enabled'    => false,
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
                    'enabled' => true,
                    'url'     => 'https://example.com/hook',
                    'secret'  => '',
                ],
                'wpscan_api_key' => '',
            ],
        ];

        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn($settings);

        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));
        when('is_wp_error')->alias(static fn () => false);
        when('wp_remote_retrieve_response_code')->alias(static fn () => 500);
        when('wp_remote_retrieve_body')->alias(static fn () => 'Server exploded');

        expect('wp_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 500],
                'body'     => 'Server exploded',
            ]);

        $message = 'WP Plugin Watchdog webhook request to https://example.com/hook failed with status 500: Server exploded';

        expect('set_transient')
            ->once()
            ->with('wp_watchdog_webhook_error', $message, 86400);

        expect('delete_transient')->never();

        $notifier = new Notifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', null, ['Example reason']),
        ]);
    }

    public function testSlackWebhookPayloadIsSentWhenEnabled(): void
    {
        $settings = [
            'notifications' => [
                'frequency' => 'daily',
                'email'     => [
                    'enabled'    => false,
                    'recipients' => '',
                ],
                'discord'   => [
                    'enabled' => false,
                    'webhook' => '',
                ],
                'slack'     => [
                    'enabled' => true,
                    'webhook' => 'https://hooks.slack.com/services/example',
                ],
                'teams'     => [
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

        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));
        when('is_wp_error')->alias(static fn () => false);
        when('wp_remote_retrieve_response_code')->alias(static fn ($response) => $response['response']['code'] ?? 0);
        when('wp_remote_retrieve_body')->alias(static fn () => '');

        expect('wp_remote_post')
            ->once()
            ->withArgs(function ($url, $args) {
                self::assertSame('https://hooks.slack.com/services/example', $url);
                self::assertSame('application/json', $args['headers']['Content-Type']);

                $payload = json_decode($args['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('WP Plugin Watchdog', $payload['username']);
                self::assertArrayHasKey('blocks', $payload);
                self::assertGreaterThanOrEqual(2, count($payload['blocks']));
                self::assertSame('Review updates', $payload['blocks'][count($payload['blocks']) - 1]['elements'][0]['text']['text']);

                return true;
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => '',
            ]);

        expect('delete_transient')
            ->once()
            ->with('wp_watchdog_webhook_error');

        expect('set_transient')->never();

        $notifier = new Notifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', '2.0.0', ['Example reason']),
        ]);
    }

    public function testTeamsWebhookPayloadIsSentWhenEnabled(): void
    {
        $settings = [
            'notifications' => [
                'frequency' => 'daily',
                'email'     => [
                    'enabled'    => false,
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
                    'enabled' => true,
                    'webhook' => 'https://example.com/teams',
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

        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));
        when('is_wp_error')->alias(static fn () => false);
        when('wp_remote_retrieve_response_code')->alias(static fn ($response) => $response['response']['code'] ?? 0);
        when('wp_remote_retrieve_body')->alias(static fn () => '');

        expect('wp_remote_post')
            ->once()
            ->withArgs(function ($url, $args) {
                self::assertSame('https://example.com/teams', $url);
                self::assertSame('application/json', $args['headers']['Content-Type']);

                $payload = json_decode($args['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('MessageCard', $payload['@type']);
                self::assertSame('WP Plugin Watchdog Risk Alert', $payload['title']);
                self::assertArrayHasKey('sections', $payload);
                self::assertNotEmpty($payload['sections'][0]['text']);

                return true;
            })
            ->andReturn([
                'response' => ['code' => 200],
                'body'     => '',
            ]);

        expect('delete_transient')
            ->once()
            ->with('wp_watchdog_webhook_error');

        expect('set_transient')->never();

        $notifier = new Notifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', '2.0.0', ['Example reason']),
        ]);
    }
}
