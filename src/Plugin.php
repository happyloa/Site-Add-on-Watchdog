<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;

class Plugin
{
    private bool $hooksRegistered = false;

    public function __construct(
        private readonly Scanner $scanner,
        private readonly RiskRepository $riskRepository,
        private readonly SettingsRepository $settingsRepository,
        private readonly Notifier $notifier
    ) {
    }

    public function register(): void
    {
        if ($this->hooksRegistered) {
            return;
        }

        add_action('wp_watchdog_daily_scan', [$this, 'runScan']);
        add_action('plugins_loaded', [$this, 'schedule']);

        $this->hooksRegistered = true;
    }

    public function schedule(): void
    {
        if (! wp_next_scheduled('wp_watchdog_daily_scan')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wp_watchdog_daily_scan');
        }
    }

    public function deactivate(): void
    {
        $timestamp = wp_next_scheduled('wp_watchdog_daily_scan');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wp_watchdog_daily_scan');
        }
    }

    /**
     * Executes the scan and persists results.
     */
    public function runScan(): void
    {
        $risks = $this->scanner->scan();
        $this->riskRepository->save($risks);

        $hash = md5(wp_json_encode(array_map(static fn (Risk $risk): array => $risk->toArray(), $risks)));
        $settings = $this->settingsRepository->get();

        if ($hash !== ($settings['last_notification'] ?? '')) {
            if (! empty($risks)) {
                $this->notifier->notify($risks);
            }
            $this->settingsRepository->saveNotificationHash($hash);
        }
    }
}
