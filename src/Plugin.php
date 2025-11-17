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

        if (
            $frequency === 'testing'
            && ($settings['notifications']['testing_expires_at'] ?? 0) > 0
            && time() >= (int) $settings['notifications']['testing_expires_at']
        ) {
            $frequency = 'daily';
            $this->settingsRepository->updateNotificationFrequency('daily');
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

        wp_schedule_event(time() + $this->scheduleDelayForFrequency($frequency), $frequency, self::CRON_HOOK);
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
        $settings = $this->settingsRepository->get();
        $retention = (int) ($settings['history']['retention'] ?? RiskRepository::DEFAULT_HISTORY_RETENTION);
        if ($retention < 1) {
            $retention = RiskRepository::DEFAULT_HISTORY_RETENTION;
        }

        $runAt = time();
        $this->riskRepository->save($risks, $runAt, $retention);

        $hash          = md5(wp_json_encode(array_map(static fn (Risk $risk): array => $risk->toArray(), $risks)));
        $lastHash      = $settings['last_notification'] ?? '';
        $isTestingMode = ($settings['notifications']['frequency'] ?? 'daily') === 'testing';

        $shouldNotify = $notify && ($isTestingMode || (! empty($risks) && $hash !== $lastHash));

        if ($shouldNotify) {
            $this->notifier->notify($risks);
            $this->settingsRepository->saveNotificationHash($hash);

            return;
        }

        if ($notify && $hash !== $lastHash) {
            $this->settingsRepository->saveNotificationHash($hash);
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

    private function scheduleDelayForFrequency(string $frequency): int
    {
        if ($frequency === 'testing') {
            $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;

            return 10 * $minute;
        }

        return defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
    }
}
