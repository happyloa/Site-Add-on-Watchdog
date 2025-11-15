<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;

class Plugin
{
    private const CRON_HOOK = 'wp_watchdog_scheduled_scan';
    private const LEGACY_CRON_HOOK = 'wp_watchdog_daily_scan';

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

        add_action(self::CRON_HOOK, [$this, 'runScan']);
        add_filter('cron_schedules', [$this, 'registerCronSchedules']);
        add_action('plugins_loaded', [$this, 'schedule']);

        $this->hooksRegistered = true;
    }

    public function schedule(): void
    {
        $settings  = $this->settingsRepository->get();
        $frequency = $settings['notifications']['frequency'] ?? 'daily';
        $allowed   = ['daily', 'weekly', 'testing', 'manual'];
        if (! in_array($frequency, $allowed, true)) {
            $frequency = 'daily';
        }

        $this->clearScheduledHook(self::LEGACY_CRON_HOOK);

        $timestamp       = wp_next_scheduled(self::CRON_HOOK);
        $currentSchedule = $timestamp ? wp_get_schedule(self::CRON_HOOK) : false;

        if ($frequency === 'manual') {
            $this->clearScheduledHook(self::CRON_HOOK);

            return;
        }

        if ($timestamp && $currentSchedule === $frequency) {
            return;
        }

        $this->clearScheduledHook(self::CRON_HOOK);

        wp_schedule_event(time() + HOUR_IN_SECONDS, $frequency, self::CRON_HOOK);
    }

    public function deactivate(): void
    {
        $this->clearScheduledHook(self::CRON_HOOK);
        $this->clearScheduledHook(self::LEGACY_CRON_HOOK);
    }

    /**
     * Executes the scan and persists results.
     *
     * @param bool $notify Whether notifications should be dispatched.
     */
    public function runScan(bool $notify = true): void
    {
        $risks = $this->scanner->scan();
        $this->riskRepository->save($risks);

        $hash = md5(wp_json_encode(array_map(static fn (Risk $risk): array => $risk->toArray(), $risks)));
        $settings = $this->settingsRepository->get();

        if ($hash !== ($settings['last_notification'] ?? '')) {
            if ($notify && ! empty($risks)) {
                $this->notifier->notify($risks);
            }
            if ($notify) {
                $this->settingsRepository->saveNotificationHash($hash);
            }
        }
    }

    /**
     * @param array<string, mixed> $schedules
     */
    public function registerCronSchedules(array $schedules): array
    {
        if (! isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Once Weekly', 'wp-plugin-watchdog'),
            ];
        }

        if (! isset($schedules['testing'])) {
            $schedules['testing'] = [
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display'  => __('Every 10 Minutes (testing)', 'wp-plugin-watchdog'),
            ];
        }

        return $schedules;
    }

    private function clearScheduledHook(string $hook): void
    {
        $timestamp = wp_next_scheduled($hook);
        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
            $timestamp = wp_next_scheduled($hook);
        }
    }
}
