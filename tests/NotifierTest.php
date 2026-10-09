<?php

use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;
use Watchdog\Models\Risk;
use Watchdog\Notifier;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Services\NotificationQueue;

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
                self::assertSame('Site Add-on Watchdog Risk Alert', $subject);
                self::assertIsString($body);
                self::assertStringContainsString('<table', $body);
                self::assertStringContainsString('https://example.com/wp-admin/update-core.php', $body);
                self::assertSame(['Content-Type: text/html; charset=UTF-8'], $headers);

                return true;
            });

        $notifier = $this->makeNotifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', null, ['Example reason']),
        ]);
    }

    public function testNotifierRendersNoRiskPayload(): void
    {
        $settings = [
            'notifications' => [
                'frequency' => 'testing',
                'email'     => [
                    'enabled'    => true,
                    'recipients' => 'user@example.com',
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
                ],
                'wpscan_api_key' => '',
            ],
        ];

        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn($settings);

        when('get_users')->alias(static fn () => []);
        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('sanitize_email')->alias(static fn ($email) => strtolower(trim((string) $email)));
        when('is_email')->alias(static fn ($email) => $email !== '' && str_contains($email, '@'));
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));
        when('is_wp_error')->alias(static fn () => false);
        when('wp_remote_retrieve_response_code')->alias(static fn ($response) => $response['response']['code'] ?? 0);
        when('wp_remote_retrieve_body')->alias(static fn () => '');

        expect('wp_mail')
            ->once()
            ->withArgs(function ($recipients, $subject, $body, $headers) {
                self::assertSame(['user@example.com'], $recipients);
                self::assertSame('Site Add-on Watchdog Risk Alert', $subject);
                self::assertStringContainsString('No plugin risks detected on your site', $body);
                self::assertStringNotContainsString('<table', $body);

                return $headers === ['Content-Type: text/html; charset=UTF-8'];
            });

        expect('wp_safe_remote_post')
            ->once()
            ->withArgs(function ($url, $args) {
                self::assertSame('https://example.com/hook', $url);

                $payload = json_decode($args['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertStringContainsString('No plugin risks detected on your site at this time.', $payload['message']);
                self::assertSame([], $payload['risks']);

                return $args['headers']['Content-Type'] === 'application/json';
            })
            ->andReturn([
                'response' => ['code' => 204],
                'body'     => '',
            ]);

        expect('delete_transient')
            ->once()
            ->with('siteadwa_webhook_error');

        $notifier = $this->makeNotifier($repository);
        $notifier->notify([]);
    }

    public function testEmailFailureRecordsFailedNotification(): void
    {
        $settings = [
            'notifications' => [
                'frequency' => 'daily',
                'email'     => [
                    'enabled'    => true,
                    'recipients' => 'user@example.com',
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

        when('get_users')->alias(static fn () => []);
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
            ->andReturn(false);

        $queue = $this->createMock(NotificationQueue::class);
        $jobs  = [];

        $queue->expects($this->once())
            ->method('enqueue')
            ->with($this->callback(static function ($queued) use (&$jobs): bool {
                $jobs = $queued;

                return is_array($queued) && ! empty($queued);
            }));

        $queue->expects($this->once())
            ->method('process')
            ->with($this->callback(static fn () => true))
            ->willReturnCallback(static function (callable $callback) use (&$jobs): array {
                $processed = 0;
                $succeeded = 0;

                foreach ($jobs as $job) {
                    $processed++;
                    $result = $callback($job);

                    if ($result === true) {
                        $succeeded++;
                    }
                }

                return [
                    'processed' => $processed,
                    'succeeded' => $succeeded,
                ];
            });

        $queue->expects($this->once())
            ->method('recordFailure')
            ->with(
                $this->callback(function ($job): bool {
                    self::assertSame('email', $job['channel']);
                    self::assertSame('Email alert', $job['description']);
                    self::assertSame(['user@example.com'], $job['payload']['recipients']);
                    self::assertSame(
                        'Email delivery failed. Check your site mail or SMTP configuration.',
                        $job['last_error']
                    );

                    return true;
                }),
                $this->isType('int')
            );

        $notifier = new Notifier($repository, $queue);
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
                self::assertSame('Site Add-on Watchdog Risk Alert', $subject);
                self::assertIsString($body);
                self::assertStringContainsString('Current Version', $body);
                self::assertSame(['Content-Type: text/html; charset=UTF-8'], $headers);

                return true;
            });

        $notifier = $this->makeNotifier($repository);
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

        expect('wp_safe_remote_post')
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
            ->with('siteadwa_webhook_error');

        expect('set_transient')->never();

        $notifier = $this->makeNotifier($repository);
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

        expect('wp_safe_remote_post')
            ->once()
            ->andReturn($error);

        expect('set_transient')
            ->once()
            ->with('siteadwa_webhook_error', 'Webhook request to https://example.com failed: Something went wrong', 86400);

        expect('delete_transient')->never();

        $notifier = $this->makeNotifier($repository);
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

        expect('wp_safe_remote_post')
            ->once()
            ->andReturn([
                'response' => ['code' => 500],
                'body'     => 'Server exploded',
            ]);

        $message = 'Webhook request to https://example.com failed with status 500: Server exploded';

        expect('set_transient')
            ->once()
            ->with('siteadwa_webhook_error', $message, 86400);

        expect('delete_transient')->never();

        $notifier = $this->makeNotifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', null, ['Example reason']),
        ]);
    }

    public function testDiscordWebhookPayloadIsSentWhenEnabled(): void
    {
        $settings = [
            'notifications' => [
                'frequency' => 'daily',
                'email'     => [
                    'enabled'    => false,
                    'recipients' => '',
                ],
                'discord'   => [
                    'enabled' => true,
                    'webhook' => 'https://discord.com/api/webhooks/example',
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

        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_THROW_ON_ERROR));
        when('is_wp_error')->alias(static fn () => false);
        when('wp_remote_retrieve_response_code')->alias(
            static fn ($response) => $response['response']['code'] ?? 0
        );
        when('wp_remote_retrieve_body')->alias(static fn () => '');

        expect('wp_safe_remote_post')
            ->once()
            ->withArgs(function ($url, $args) {
                self::assertSame('https://discord.com/api/webhooks/example', $url);
                self::assertSame('application/json', $args['headers']['Content-Type']);

                $payload = json_decode($args['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertLessThanOrEqual(2000, strlen($payload['content']));
                self::assertSame(['parse' => []], $payload['allowed_mentions']);
                self::assertSame('Site Add-on Watchdog', $payload['username']);
                self::assertStringContainsString('Plugin Name', $payload['content']);

                return true;
            })
            ->andReturn([
                'response' => ['code' => 204],
                'body'     => '',
            ]);

        expect('delete_transient')
            ->once()
            ->with('siteadwa_webhook_error');

        expect('set_transient')->never();

        $notifier = $this->makeNotifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', '2.0.0', ['Example reason']),
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

        expect('wp_safe_remote_post')
            ->once()
            ->withArgs(function ($url, $args) {
                self::assertSame('https://hooks.slack.com/services/example', $url);
                self::assertSame('application/json', $args['headers']['Content-Type']);

                $payload = json_decode($args['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('username', $payload);
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
            ->with('siteadwa_webhook_error');

        expect('set_transient')->never();

        $notifier = $this->makeNotifier($repository);
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

        expect('wp_safe_remote_post')
            ->once()
            ->withArgs(function ($url, $args) {
                self::assertSame('https://example.com/teams', $url);
                self::assertSame('application/json', $args['headers']['Content-Type']);

                $payload = json_decode($args['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('MessageCard', $payload['@type']);
                self::assertSame('Site Add-on Watchdog Risk Alert', $payload['title']);
                self::assertArrayHasKey('text', $payload);
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
            ->with('siteadwa_webhook_error');

        expect('set_transient')->never();

        $notifier = $this->makeNotifier($repository);
        $notifier->notify([
            new Risk('plugin-slug', 'Plugin Name', '1.0.0', '2.0.0', ['Example reason']),
        ]);
    }

    public function testSingleDisabledChannelCanBeTestedWithoutDispatchingOthers(): void
    {
        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn([
            'notifications' => [
                'email' => [
                    'enabled' => false,
                    'recipients' => 'test@example.com',
                ],
                'discord' => [
                    'enabled' => true,
                    'webhook' => 'https://discord.com/api/webhooks/unused',
                ],
            ],
        ]);

        when('get_users')->justReturn([]);
        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('sanitize_email')->alias(static fn ($email) => strtolower(trim((string) $email)));
        when('sanitize_key')->alias(static fn ($key) => strtolower((string) $key));
        when('is_email')->alias(static fn ($email) => $email !== '' && str_contains($email, '@'));

        expect('wp_mail')
            ->once()
            ->withArgs(static fn ($recipients) => $recipients === ['test@example.com'])
            ->andReturn(true);
        expect('wp_safe_remote_post')->never();

        $queue = $this->createMock(NotificationQueue::class);
        $queue->expects(self::never())->method('enqueue');
        $queue->expects(self::never())->method('recordFailure');

        $notifier = new Notifier($repository, $queue);

        self::assertSame('sent', $notifier->testChannel('email'));
    }

    /**
     * @dataProvider emailFailureProvider
     */
    public function testEmailTransportFailuresAreCapturedAndHooksAreRemoved(
        string $failureMode,
        string $providerMessage,
        string $expectedDetail
    ): void {
        $repository = $this->createMock(SettingsRepository::class);
        $repository->method('get')->willReturn([
            'notifications' => ['email' => ['enabled' => true, 'recipients' => 'test@example.com']],
        ]);
        when('get_users')->justReturn([]);
        when('admin_url')->alias(static fn ($path = '') => 'https://example.com/wp-admin/' . ltrim($path, '/'));
        when('esc_url')->alias(static fn ($url) => $url);
        when('esc_html')->alias(static fn ($text) => $text);
        when('esc_attr')->alias(static fn ($text) => $text);
        when('__')->alias(static fn ($text) => $text);
        when('esc_html__')->alias(static fn ($text) => $text);
        when('sanitize_email')->alias(static fn ($email) => trim((string) $email));
        when('sanitize_key')->alias(static fn ($key) => strtolower((string) $key));
        when('is_email')->alias(static fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        $mailErrorCallback = null;
        \Brain\Monkey\Actions\expectAdded('wp_mail_failed')->twice()->whenHappen(
            static function (callable $callback) use (&$mailErrorCallback): void {
                $mailErrorCallback = $callback;
            }
        );
        $attempt = 0;
        when('wp_mail')->alias(static function () use (
            &$attempt,
            &$mailErrorCallback,
            $failureMode,
            $providerMessage
        ): bool {
            if (++$attempt > 1) {
                return true;
            }

            if ($failureMode === 'exception') {
                throw new \RuntimeException($providerMessage);
            }
            if ($failureMode === 'hook') {
                $mailErrorCallback(new class($providerMessage) {
                    public function __construct(private string $message)
                    {
                    }

                    public function get_error_message(): string
                    {
                        return $this->message;
                    }
                });
            }

            return false;
        });

        $queue = $this->createMock(NotificationQueue::class);
        $queue->expects(self::never())->method('enqueue');
        $queue->expects(self::once())->method('recordFailure')->with(
            self::callback(static function (array $job) use ($expectedDetail): bool {
                self::assertSame('email', $job['channel']);
                self::assertSame(['test@example.com'], $job['payload']['recipients']);
                $message = 'Email delivery failed. Check your site mail or SMTP configuration.';
                self::assertSame($message . $expectedDetail, $job['last_error']);

                return true;
            }),
            self::isType('int')
        );

        $notifier = new Notifier($repository, $queue);
        self::assertSame('failed', $notifier->testChannel('email'));
        self::assertFalse(has_action('wp_mail_failed'));
        self::assertSame('sent', $notifier->testChannel('email'));
        self::assertFalse(has_action('wp_mail_failed'));
    }

    public function emailFailureProvider(): array
    {
        return [
            'WordPress failure hook' => ['hook', 'SMTP Error: Could not authenticate.', ' SMTP Error: Could not authenticate.'],
            'mail plugin exception' => ['exception', 'SMTP connection refused', ' SMTP connection refused'],
            'short-circuited mail' => ['false', '', ''],
            'redacted provider error' => [
                'hook',
                "<b>SMTP error</b>\nhttps://user:password@example.com/send?token=private token=secret123 password=hunter2",
                ' SMTP error https://example.com token=[redacted] password=[redacted]',
            ],
            'quoted and bearer credentials' => [
                'exception',
                'SMTP error password="two secret words" Authorization: Bearer private-token',
                ' SMTP error password=[redacted] Authorization=[redacted]',
            ],
        ];
    }

    private function makeNotifier(SettingsRepository $repository): Notifier
    {
        $queue = $this->createMock(NotificationQueue::class);
        $jobs  = [];

        $queue->expects($this->once())
            ->method('enqueue')
            ->with($this->callback(static function ($queued) use (&$jobs): bool {
                $jobs = $queued;

                return is_array($queued) && ! empty($queued);
            }));

        $queue->expects($this->once())
            ->method('process')
            ->with($this->callback(static fn () => true))
            ->willReturnCallback(static function (callable $callback) use (&$jobs): array {
                $processed = 0;
                $succeeded = 0;

                foreach ($jobs as $job) {
                    $processed++;
                    $result = $callback($job);

                    if ($result === true) {
                        $succeeded++;
                    }
                }

                return [
                    'processed' => $processed,
                    'succeeded' => $succeeded,
                ];
            });

        return new Notifier($repository, $queue);
    }
}
