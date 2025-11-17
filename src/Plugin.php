<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;

class Plugin
{
    private const CRON_HOOK = 'wp_watchdog_scheduled_scan';
    private const LEGACY_CRON_HOOK = 'wp_watchdog_daily_scan';
    private const CRON_STATUS_OPTION = 'wp_watchdog_cron_status';

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
        add_action('admin_notices', [$this, 'renderCronDiagnostics']);

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
        $interval        = $this->cronIntervalForFrequency($frequency);
        $isOverdue       = $timestamp !== false
            && $interval > 0
            && $this->isEventOverdue((int) $timestamp, $interval);

        if ($frequency === 'manual') {
            $this->clearScheduledHook(self::CRON_HOOK);
            $this->recordCronStatus($this->isCronDisabled(), false);

            return;
        }

        if ($isOverdue) {
            $this->handleOverdueEvent($frequency, $interval);

            return;
        }

        $this->recordCronStatus($this->isCronDisabled(), false);

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

    public function renderCronDiagnostics(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        $status = get_option(self::CRON_STATUS_OPTION);
        if (! is_array($status)) {
            return;
        }

        if (! empty($status['cron_disabled'])) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__(
                    'WP-Cron appears disabled. Configure a system cron job to trigger wp-cron.php for Plugin Watchdog.',
                    'wp-plugin-watchdog'
                )
                . '</p></div>';

            return;
        }

        if (($status['overdue_streak'] ?? 0) >= 2) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__(
                    'Plugin Watchdog scans are overdue. Ensure system cron calls wp-cron.php regularly.',
                    'wp-plugin-watchdog'
                )
                . '</p></div>';
        }
    }

    private function isEventOverdue(int $timestamp, int $interval): bool
    {
        $grace = max(60, (int) floor($interval * 0.25));

        return $timestamp <= (time() - ($interval + $grace));
    }

    private function handleOverdueEvent(string $frequency, int $interval): void
    {
        $cronDisabled = $this->isCronDisabled();
        $now          = time();

        $this->recordCronStatus($cronDisabled, true);

        if (! $cronDisabled) {
            if (function_exists('spawn_cron')) {
                spawn_cron($now);
            } else {
                $cronUrl = site_url('wp-cron.php');
                wp_remote_post($cronUrl, [
                    'timeout'   => 0.01,
                    'blocking'  => false,
                    'sslverify' => false,
                ]);
            }
        }

        if ($this->hasFutureEventScheduled($now)) {
            return;
        }

        $catchUpDelay = $this->catchUpDelay($frequency, $interval);
        wp_schedule_single_event($now + $catchUpDelay, self::CRON_HOOK);
    }

    private function isCronDisabled(): bool
    {
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    private function recordCronStatus(bool $cronDisabled, bool $overdue): void
    {
        $status = get_option(self::CRON_STATUS_OPTION);
        if (! is_array($status)) {
            $status = [
                'overdue_streak' => 0,
                'cron_disabled'  => false,
            ];
        }

        $status['cron_disabled'] = $cronDisabled;
        $status['last_checked']  = time();

        if ($overdue) {
            $status['overdue_streak'] = min(10, (int) ($status['overdue_streak'] ?? 0) + 1);
        } else {
            $status['overdue_streak'] = 0;
        }

        update_option(self::CRON_STATUS_OPTION, $status, false);

        if ($cronDisabled) {
            error_log('[Plugin Watchdog] WP-Cron appears disabled. Configure system cron to trigger wp-cron.php.');
        } elseif ($status['overdue_streak'] >= 2) {
            error_log('[Plugin Watchdog] Scheduled scans are overdue. Ensure cron can reach wp-cron.php.');
        }
    }

    private function cronIntervalForFrequency(string $frequency): int
    {
        $schedules = wp_get_schedules();
        if (isset($schedules[$frequency]['interval'])) {
            return (int) $schedules[$frequency]['interval'];
        }

        if ($frequency === 'testing') {
            return 10 * (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60);
        }

        if ($frequency === 'weekly') {
            return defined('WEEK_IN_SECONDS') ? WEEK_IN_SECONDS : 7 * 24 * 3600;
        }

        return defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
    }

    private function catchUpDelay(string $frequency, int $interval): int
    {
        if ($frequency === 'testing') {
            return 60;
        }

        return min(300, max(60, (int) floor($interval / 6)));
    }

    private function hasFutureEventScheduled(int $now): bool
    {
        $crons = _get_cron_array();
        if (! is_array($crons)) {
            return false;
        }

        foreach ($crons as $timestamp => $hooks) {
            if ($timestamp <= $now) {
                continue;
            }

            if (isset($hooks[self::CRON_HOOK])) {
                return true;
            }
        }

        return false;
    }
}
