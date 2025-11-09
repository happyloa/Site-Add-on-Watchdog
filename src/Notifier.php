<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\SettingsRepository;

class Notifier
{
    public function __construct(private readonly SettingsRepository $settingsRepository)
    {
    }

    /**
     * @param Risk[] $risks
     */
    public function notify(array $risks): void
    {
        $settings = $this->settingsRepository->get();
        $payload  = $this->formatMessage($risks);

        if ($settings['email_enabled'] && ! empty($settings['email_recipients'])) {
            wp_mail(
                $this->parseRecipients($settings['email_recipients']),
                __('Plugin Watchdog Risk Alert', 'wp-plugin-watchdog'),
                $payload
            );
        }

        if ($settings['discord_enabled'] && ! empty($settings['discord_webhook'])) {
            $this->dispatchWebhook($settings['discord_webhook'], [
                'username'  => 'WP Plugin Watchdog',
                'content'   => $payload,
            ]);
        }

        if ($settings['webhook_enabled'] && ! empty($settings['webhook_url'])) {
            $this->dispatchWebhook($settings['webhook_url'], [
                'message' => $payload,
                'risks'   => array_map(static fn (Risk $risk): array => $risk->toArray(), $risks),
            ]);
        }
    }

    private function dispatchWebhook(string $url, array $body): void
    {
        wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 10,
        ]);
    }

    /**
     * @param Risk[] $risks
     */
    private function formatMessage(array $risks): string
    {
        $lines = [
            __('Potential plugin risks detected on your site:', 'wp-plugin-watchdog'),
            '',
        ];

        foreach ($risks as $risk) {
            $lines[] = sprintf('%s (%s)', $risk->pluginName, $risk->localVersion);
            if ($risk->remoteVersion) {
                $lines[] = sprintf(__('Directory version: %s', 'wp-plugin-watchdog'), $risk->remoteVersion);
            }
            foreach ($risk->reasons as $reason) {
                $lines[] = sprintf('- %s', $reason);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function parseRecipients(string $recipients): array
    {
        return array_filter(array_map('trim', explode(',', $recipients)));
    }
}
