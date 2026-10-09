<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Notifications\MessageFormatter;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Services\NotificationQueue;
use Watchdog\Version;

class Notifier
{
    private const PREFIX = Version::PREFIX;
    private const CHANNELS = ['email', 'discord', 'slack', 'teams', 'webhook'];

    private readonly MessageFormatter $messageFormatter;

    public function __construct(
        private readonly SettingsRepository $settingsRepository,
        private readonly NotificationQueue $notificationQueue,
        ?MessageFormatter $messageFormatter = null
    ) {
        $this->messageFormatter = $messageFormatter ?? new MessageFormatter();
    }

    /**
     * @param Risk[] $risks
     */
    public function notify(array $risks): void
    {
        $jobs = $this->buildJobs($risks);

        if ($jobs === []) {
            return;
        }

        $this->notificationQueue->enqueue($jobs);
        $this->processQueue();
    }

    /**
     * Sends a no-risk test message through exactly one saved channel.
     *
     * @return 'sent'|'failed'|'not_configured'|'invalid_channel'
     */
    public function testChannel(string $channel): string
    {
        $channel = sanitize_key($channel);
        if (! in_array($channel, self::CHANNELS, true)) {
            return 'invalid_channel';
        }

        $jobs = $this->buildJobs([], $channel, false);
        if ($jobs === []) {
            return 'not_configured';
        }

        $job    = $jobs[0];
        $result = $this->sendQueuedJob($job);

        if ($result === true) {
            return 'sent';
        }

        if ($job['channel'] !== 'email') {
            $job['last_error'] = $result;
            $this->notificationQueue->recordFailure($job, time());
        }

        return 'failed';
    }

    /**
     * @param Risk[] $risks
     * @return array<int, array<string, mixed>>
     */
    private function buildJobs(
        array $risks,
        ?string $onlyChannel = null,
        bool $requireEnabled = true,
    ): array {
        $settings      = $this->settingsRepository->get();
        $notifications = isset($settings['notifications']) && is_array($settings['notifications'])
            ? $settings['notifications']
            : [];
        $plainTextReport = $this->messageFormatter->plainText($risks);
        $jobs            = [];

        $shouldBuild = static function (
            string $channel,
            array $channelSettings
        ) use (
            $onlyChannel,
            $requireEnabled
        ): bool {
            if ($onlyChannel !== null && $onlyChannel !== $channel) {
                return false;
            }

            return ! $requireEnabled || ! empty($channelSettings['enabled']);
        };

        $emailSettings = $this->channelSettings($notifications, 'email');
        if ($shouldBuild('email', $emailSettings)) {
            $configuredRecipients = ! empty($emailSettings['recipients'])
                ? $this->parseRecipients((string) $emailSettings['recipients'])
                : [];
            $recipients = $this->uniqueEmails(array_merge(
                $configuredRecipients,
                $this->getAdministratorEmails()
            ));

            if ($recipients !== []) {
                $jobs[] = [
                    'channel_key' => 'email',
                    'channel'     => 'email',
                    'description' => __('Email alert', 'site-add-on-watchdog'),
                    'payload'     => [
                        'recipients' => $recipients,
                        'subject'    => __('Site Add-on Watchdog Risk Alert', 'site-add-on-watchdog'),
                        'body'       => $this->messageFormatter->email($risks),
                        'headers'    => ['Content-Type: text/html; charset=UTF-8'],
                    ],
                ];
            }
        }

        $webhookDefinitions = [
            'discord' => [
                'field' => 'webhook',
                'description' => __('Discord webhook', 'site-add-on-watchdog'),
                'formatter' => fn (): array => $this->messageFormatter->discord($plainTextReport),
            ],
            'slack' => [
                'field' => 'webhook',
                'description' => __('Slack webhook', 'site-add-on-watchdog'),
                'formatter' => fn (): array => $this->messageFormatter->slack($risks, $plainTextReport),
            ],
            'teams' => [
                'field' => 'webhook',
                'description' => __('Microsoft Teams webhook', 'site-add-on-watchdog'),
                'formatter' => fn (): array => $this->messageFormatter->teams($risks, $plainTextReport),
            ],
            'webhook' => [
                'field' => 'url',
                'description' => __('Custom webhook', 'site-add-on-watchdog'),
                'formatter' => fn (): array => $this->messageFormatter->customWebhook($risks, $plainTextReport),
            ],
        ];

        foreach ($webhookDefinitions as $channel => $definition) {
            $channelSettings = $this->channelSettings($notifications, $channel);
            if (! $shouldBuild($channel, $channelSettings)) {
                continue;
            }

            $url = isset($channelSettings[$definition['field']])
                ? trim((string) $channelSettings[$definition['field']])
                : '';
            if ($url === '') {
                continue;
            }

            $jobs[] = [
                'channel_key' => $channel,
                'channel'     => 'webhook',
                'description' => $definition['description'],
                'payload'     => [
                    'url'    => $url,
                    'body'   => $definition['formatter'](),
                    'secret' => $channel === 'webhook' ? ($channelSettings['secret'] ?? null) : null,
                ],
            ];
        }

        return $jobs;
    }

    public function processQueue(): array
    {
        return $this->notificationQueue->process(function (array $job): bool|string {
            return $this->sendQueuedJob($job);
        });
    }

    public function getLastFailedNotification(): ?array
    {
        return $this->notificationQueue->getLastFailed();
    }

    public function requeueLastFailedNotification(): bool
    {
        return $this->notificationQueue->requeueLastFailed();
    }

    /**
     * @return array{length:int,next_attempt_at:?int}
     */
    public function getQueueStatus(): array
    {
        return $this->notificationQueue->getQueueStatus();
    }

    private function dispatchWebhookJob(array $payload): bool|string
    {
        $url = isset($payload['url']) ? (string) $payload['url'] : '';
        if ($url === '') {
            return __('Webhook URL missing.', 'site-add-on-watchdog');
        }

        $body = $payload['body'] ?? [];
        if (! is_array($body)) {
            return __('Webhook payload invalid.', 'site-add-on-watchdog');
        }

        $secret  = isset($payload['secret']) ? (string) $payload['secret'] : null;
        $encoded = wp_json_encode($body);
        if (! is_string($encoded)) {
            $encoded = '';
        }

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($secret !== null && $secret !== '') {
            $headers['X-Watchdog-Signature'] = 'sha256=' . hash_hmac('sha256', $encoded, $secret);
        }

        $response = wp_safe_remote_post($url, [
            'headers' => $headers,
            'body'    => $encoded,
            'timeout' => 10,
            'redirection' => 3,
            'user-agent' => 'Site Add-on Watchdog/' . Version::NUMBER . '; WordPress',
        ]);

        $expiration = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

        if (is_wp_error($response)) {
            $message = sprintf(
                /* translators: 1: redacted webhook host, 2: error message. */
                __('Webhook request to %1$s failed: %2$s', 'site-add-on-watchdog'),
                $this->redactWebhookUrl($url),
                $this->sanitizeErrorText($response->get_error_message())
            );

            $this->logWebhookFailure($message);
            set_transient(self::PREFIX . '_webhook_error', $message, $expiration);

            return $message;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            $bodyMessage = $this->sanitizeErrorText((string) wp_remote_retrieve_body($response));
            $message     = sprintf(
                /* translators: 1: redacted webhook host, 2: HTTP status code. */
                __('Webhook request to %1$s failed with status %2$d', 'site-add-on-watchdog'),
                $this->redactWebhookUrl($url),
                $statusCode
            );

            if ($bodyMessage !== '') {
                $message .= sprintf(': %s', $this->truncateText($bodyMessage, 300));
            }

            $this->logWebhookFailure($message);
            set_transient(self::PREFIX . '_webhook_error', $message, $expiration);

            return $message;
        }

        delete_transient(self::PREFIX . '_webhook_error');

        return true;
    }

    private function sendEmailJob(array $job): bool|string
    {
        $payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : [];
        $recipients = isset($payload['recipients']) && is_array($payload['recipients'])
            ? array_values($payload['recipients'])
            : [];

        $recipients = array_values(array_filter(
            array_map(static fn ($recipient): string => (string) $recipient, $recipients),
            static fn ($recipient): bool => $recipient !== ''
        ));

        if ($recipients === []) {
            return __('Email recipients missing.', 'site-add-on-watchdog');
        }

        $subject = isset($payload['subject']) ? (string) $payload['subject'] : '';
        $body    = isset($payload['body']) ? (string) $payload['body'] : '';
        $headers = $payload['headers'] ?? ['Content-Type: text/html; charset=UTF-8'];

        $mailError = '';
        $captureError = static function ($error) use (&$mailError): void {
            $mailError = $error->get_error_message();
        };

        add_action('wp_mail_failed', $captureError);
        try {
            $sent = wp_mail($recipients, $subject, $body, $headers);
        } catch (\Throwable $error) {
            $sent      = false;
            $mailError = $error->getMessage();
        } finally {
            remove_action('wp_mail_failed', $captureError);
        }

        if ($sent) {
            return true;
        }

        $message = __('Email delivery failed. Check your site mail or SMTP configuration.', 'site-add-on-watchdog');
        if ($mailError !== '') {
            $message .= ' ' . $this->sanitizeMailError($mailError);
        }
        $this->notificationQueue->recordFailure([
            'channel'     => 'email',
            'description' => isset($job['description']) ? (string) $job['description'] : '',
            'payload'     => $payload,
            'attempts'    => isset($job['attempts']) ? (int) $job['attempts'] : 0,
            'last_error'  => $message,
        ], time());

        return $message;
    }

    private function sanitizeMailError(string $message): string
    {
        $message = preg_replace_callback(
            '~https?://[^\s<>]+~i',
            fn (array $match): string => $this->redactWebhookUrl($match[0]),
            $message
        ) ?? '';
        $message = preg_replace(
            '/\b(password|passwd|token|secret|api[_-]?key|authorization)\s*[:=]\s*'
                . '(?:Bearer\s+\S+|"[^"]*"|\'[^\']*\'|\S+)/i',
            '$1=[redacted]',
            $message
        ) ?? '';

        return $this->sanitizeErrorText($message);
    }

    private function sendQueuedJob(array $job): bool|string
    {
        $channel = $job['channel'] ?? '';
        $payload = $job['payload'] ?? [];

        if ($channel === 'email') {
            return $this->sendEmailJob($job);
        }

        if ($channel === 'webhook') {
            return $this->dispatchWebhookJob(is_array($payload) ? $payload : []);
        }

        return __('Unknown notification channel.', 'site-add-on-watchdog');
    }

    private function logWebhookFailure(string $message): void
    {
        do_action('site_add_on_watchdog_diagnostic', 'notification_delivery_failed', [
            'message' => $message,
        ]);
    }

    private function redactWebhookUrl(string $url): string
    {
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host   = wp_parse_url($url, PHP_URL_HOST);
        $port   = wp_parse_url($url, PHP_URL_PORT);

        if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
            return __('configured endpoint', 'site-add-on-watchdog');
        }

        return strtolower($scheme) . '://' . strtolower($host) . (is_int($port) ? ':' . $port : '');
    }

    private function sanitizeErrorText(string $message): string
    {
        $message = trim(wp_strip_all_tags($message));
        $message = preg_replace('/[\r\n\t ]+/', ' ', $message) ?? '';

        return $this->truncateText($message, 300);
    }

    /**
     * @return string[]
     */
    private function parseRecipients(string $recipients): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[,;\s]+/', $recipients) ?: []
        )));
    }

    /**
     * @return string[]
     */
    private function getAdministratorEmails(): array
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

        $sanitized = [];
        foreach (array_filter($emails) as $email) {
            $clean = sanitize_email($email);
            if ($clean === '' || ! is_email($clean)) {
                continue;
            }

            $sanitized[] = $clean;
        }

        return $sanitized;
    }

    /**
     * @param string[] $emails
     * @return string[]
     */
    private function uniqueEmails(array $emails): array
    {
        $unique = [];
        $seen   = [];

        foreach ($emails as $email) {
            $sanitized = sanitize_email($email);
            if ($sanitized === '' || ! is_email($sanitized)) {
                continue;
            }

            $normalized = strtolower($sanitized);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $unique[]          = $sanitized;
        }

        return $unique;
    }

    private function truncateText(string $text, int $limit): string
    {
        if ($limit < 2) {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length <= $limit) {
            return $text;
        }

        $truncated = function_exists('mb_substr')
            ? mb_substr($text, 0, $limit - 1)
            : substr($text, 0, $limit - 1);

        return rtrim($truncated) . '…';
    }

    /**
     * @param array<string, mixed> $notifications
     * @return array<string, mixed>
     */
    private function channelSettings(array $notifications, string $channel): array
    {
        $settings = $notifications[$channel] ?? [];

        return is_array($settings) ? $settings : [];
    }
}
