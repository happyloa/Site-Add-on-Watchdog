<?php

/**
 * Run with WP-CLI in the isolated wp-env installation and its Mailpit container.
 * Mailpit captures SMTP locally and has no outbound relay configured.
 */

use Watchdog\Models\Risk;
use Watchdog\Notifier;
use Watchdog\Repository\SettingsRepository;
use Watchdog\Services\NotificationQueue;
use Watchdog\Version;

if (! defined('WP_CLI') || ! WP_CLI || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Run this check only in the local wp-env installation.');
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$readMessages = static function (): array {
    $response = file_get_contents('http://watchdog-mailpit-184:8025/api/v1/messages');

    return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
};

$originalOptions = [];
foreach (['_settings', '_notification_queue', '_failed_notification'] as $suffix) {
    $key = Version::PREFIX . $suffix;
    $originalOptions[$key] = get_option($key);
}

$smtpPort = 1025;
$testSender = static fn (): string => 'watchdog@watchdog.test';
$configureMail = static function ($mailer) use (&$smtpPort): void {
    $mailer->isSMTP();
    $mailer->Host = 'watchdog-mailpit-184';
    $mailer->Port = $smtpPort;
    $mailer->SMTPAuth = false;
    $mailer->SMTPAutoTLS = false;
    $mailer->SMTPSecure = '';
    $mailer->SMTPKeepAlive = false;
    $mailer->Timeout = 2;
    $mailer->setFrom('watchdog@watchdog.test', 'Watchdog integration test');
};
$webhookCalls = 0;
$captureWebhook = static function ($response, array $arguments, string $url) use (&$webhookCalls) {
    if ($url === 'https://example.com/watchdog-email-smoke') {
        $webhookCalls++;

        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '', 'headers' => []];
    }

    return $response;
};
$throwMailError = static function (): void {
    throw new RuntimeException('Integration test mail plugin exception');
};
$mailFailureHooks = $GLOBALS['wp_filter']['wp_mail_failed'] ?? null;
$originalHookCount = $mailFailureHooks ? count($mailFailureHooks->callbacks[10] ?? []) : 0;

add_action('phpmailer_init', $configureMail);
add_filter('wp_mail_from', $testSender);
add_filter('pre_http_request', $captureWebhook, 10, 3);

try {
    $repository = new SettingsRepository();
    $settings = $repository->get();
    foreach (['email', 'discord', 'slack', 'teams', 'webhook'] as $channel) {
        $settings['notifications'][$channel]['enabled'] = false;
    }
    $settings['notifications']['email'] = ['enabled' => true, 'recipients' => 'tester@watchdog.test'];
    $settings['notifications']['webhook'] = [
        'enabled' => true,
        'url' => 'https://example.com/watchdog-email-smoke',
        'secret' => '',
    ];
    update_option(Version::PREFIX . '_settings', $settings, false);
    delete_option(Version::PREFIX . '_notification_queue');
    delete_option(Version::PREFIX . '_failed_notification');
    $queue = new NotificationQueue();
    $notifier = new Notifier($repository, $queue);
    $initialCount = (int) $readMessages()['total'];

    $assert(
        $notifier->testChannel('email') === 'sent',
        'The email test was not accepted by SMTP: ' . ($queue->getLastFailed()['last_error'] ?? 'no error')
    );
    $messages = $readMessages();
    $assert((int) $messages['total'] === $initialCount + 1, 'Mailpit did not capture the test email.');
    $message = json_decode(file_get_contents(
        'http://watchdog-mailpit-184:8025/api/v1/message/' . $messages['messages'][0]['ID']
    ), true, 512, JSON_THROW_ON_ERROR);
    $addresses = array_column($message['To'], 'Address');
    $assert(in_array('tester@watchdog.test', $addresses, true), 'The configured recipient is missing.');
    foreach (get_users(['role' => 'administrator']) as $administrator) {
        $assert(in_array($administrator->user_email, $addresses, true), 'An administrator recipient is missing.');
    }
    $assert($message['Subject'] === 'Site Add-on Watchdog Risk Alert', 'The email subject is incorrect.');
    $assert(str_contains($message['HTML'], 'No plugin risks detected'), 'The test email body is incorrect.');
    $assert($webhookCalls === 0, 'Testing email also sent a webhook.');
    WP_CLI::log('PASS: real SMTP captured the HTML test email and all expected recipients.');

    $smtpPort = 1;
    $risk = new Risk('smoke-plugin', 'Smoke Plugin', '1.0.0', '2.0.0', ['Update available']);
    $notifier->notify([$risk]);
    $failed = $queue->getLastFailed();
    $assert($failed !== null && str_contains($failed['last_error'], 'SMTP'), 'The SMTP error was not captured.');
    $assert($queue->getQueueStatus()['length'] === 1, 'The failed email was not retained for retry.');
    $assert($webhookCalls === 1, 'An email failure prevented the webhook from running.');
    WP_CLI::log('PASS: SMTP refusal records its error, queues a retry, and allows webhook delivery.');

    $smtpPort = 1025;
    $queued = get_option(Version::PREFIX . '_notification_queue');
    $queued[0]['next_attempt_at'] = time() - 1;
    update_option(Version::PREFIX . '_notification_queue', $queued, false);
    $result = $notifier->processQueue();
    $assert($result === ['processed' => 1, 'succeeded' => 1], 'The email retry did not succeed.');
    $assert($queue->getQueueStatus()['length'] === 0, 'The delivered email remained in the queue.');
    $messages = $readMessages();
    $assert((int) $messages['total'] === $initialCount + 2, 'Mailpit did not capture the retried alert.');
    $alert = json_decode(file_get_contents(
        'http://watchdog-mailpit-184:8025/api/v1/message/' . $messages['messages'][0]['ID']
    ), true, 512, JSON_THROW_ON_ERROR);
    $assert(str_contains($alert['HTML'], 'Smoke Plugin') && str_contains($alert['HTML'], '<table'), 'The retried risk report is missing.');
    $assert($webhookCalls === 1, 'Retrying email duplicated the webhook.');
    WP_CLI::log('PASS: restoring SMTP delivers the queued alert and removes it from the queue.');

    add_filter('pre_wp_mail', $throwMailError);
    $assert($notifier->testChannel('email') === 'failed', 'A mail plugin exception escaped the notifier.');
    $assert(str_contains($queue->getLastFailed()['last_error'], 'Integration test mail plugin exception'), 'The mail plugin error was lost.');
    $notifier->notify([$risk]);
    $assert($webhookCalls === 2, 'A mail plugin exception prevented webhook delivery.');
    $assert($queue->getQueueStatus()['length'] === 1, 'A mail plugin exception prevented email retry.');
    remove_filter('pre_wp_mail', $throwMailError);
    $hooks = $GLOBALS['wp_filter']['wp_mail_failed'] ?? null;
    $assert(($hooks ? count($hooks->callbacks[10] ?? []) : 0) === $originalHookCount, 'A temporary mail error hook leaked.');
    WP_CLI::log('PASS: mail plugin exceptions are captured and temporary hooks are removed.');
} finally {
    remove_action('phpmailer_init', $configureMail);
    remove_filter('wp_mail_from', $testSender);
    remove_filter('pre_http_request', $captureWebhook);
    remove_filter('pre_wp_mail', $throwMailError);
    delete_transient(Version::PREFIX . '_webhook_error');
    foreach ($originalOptions as $key => $value) {
        if ($value === false) {
            delete_option($key);
        } else {
            update_option($key, $value, false);
        }
    }
}

WP_CLI::success('Email transport integration checks passed.');
