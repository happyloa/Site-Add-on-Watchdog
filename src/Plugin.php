<?php

namespace Watchdog;

use Watchdog\Models\Risk;
use Watchdog\Repository\RiskRepository;
use Watchdog\Repository\SettingsRepository;
use Watchdog\TestingMode;
use Watchdog\Version;
use WP_REST_Request;

class Plugin
{
    private const PREFIX = Version::PREFIX;
    private const CRON_HOOK = self::PREFIX . '_scheduled_scan';
    private const QUEUE_CRON_HOOK = self::PREFIX . '_notification_queue';
    private const QUEUE_CRON_SCHEDULE = self::PREFIX . '_notification_queue';
    private const LEGACY_CRON_HOOK = 'wp_watchdog_daily_scan';
    private const LEGACY_QUEUE_CRON_HOOK = 'wp_watchdog_notification_queue';
    private const LEGACY_QUEUE_CRON_SCHEDULE = 'watchdog_notification_queue';
    private const CRON_STATUS_OPTION = self::PREFIX . '_cron_status';
    private const LEGACY_CRON_STATUS_OPTION = 'wp_watchdog_cron_status';
    private const UPDATE_CHECK_OPTION = self::PREFIX . '_update_check_scan_at';
    private const LEGACY_UPDATE_CHECK_OPTION = 'wp_watchdog_update_check_scan_at';

    private const MANUAL_NOTIFICATION_INTERVAL = 60;

    private const REST_NAMESPACE = 'site-add-on-watchdog/v1';

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
        add_action(self::CRON_HOOK, [$this, 'flushNotificationQueue'], 11);
        add_filter('cron_schedules', [$this, 'registerCronSchedules']);
        add_action('plugins_loaded', [$this, 'schedule']);
        add_action(self::QUEUE_CRON_HOOK, [$this, 'flushNotificationQueue']);
        add_action('admin_notices', [$this, 'renderCronDiagnostics']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        $this->cleanupUpdateCheckState();

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
        $this->clearScheduledHook(self::LEGACY_QUEUE_CRON_HOOK);

        $this->scheduleNotificationQueueProcessor();

        $timestamp       = wp_next_scheduled(self::CRON_HOOK);
        $currentSchedule = $timestamp ? wp_get_schedule(self::CRON_HOOK) : false;
        $interval        = $this->cronIntervalForFrequency($frequency);
        $nextRunAt       = $this->nextRunTimestamp($frequency, $settings);

        if ($frequency === 'testing' && $timestamp) {
            $nextRunAt = (int) $timestamp;
        }

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

        if (
            $timestamp
            && $currentSchedule === $frequency
            && ! $this->scheduleNeedsRealignment((int) $timestamp, $nextRunAt, $interval)
        ) {
            return;
        }

        $this->clearScheduledHook(self::CRON_HOOK);

        wp_schedule_event($nextRunAt, $frequency, self::CRON_HOOK);
    }

    public function deactivate(): void
    {
        $this->clearScheduledHook(self::CRON_HOOK);
        $this->clearScheduledHook(self::QUEUE_CRON_HOOK);
        $this->clearScheduledHook(self::LEGACY_CRON_HOOK);
        $this->clearScheduledHook(self::LEGACY_QUEUE_CRON_HOOK);
        delete_option(self::UPDATE_CHECK_OPTION);
        delete_option(self::LEGACY_UPDATE_CHECK_OPTION);
    }

    /**
     * Executes the scan and persists results.
     *
     * @param bool $notify Whether notifications should be dispatched.
     *
     * @return bool Whether notifications were dispatched.
     */
    public function runScan(bool $notify = true, string $context = 'automatic'): bool
    {
        $risks = $this->scanner->scan();
        $settings = $this->settingsRepository->get();
        $retention = (int) ($settings['history']['retention'] ?? RiskRepository::DEFAULT_HISTORY_RETENTION);
        if ($retention < 1) {
            $retention = RiskRepository::DEFAULT_HISTORY_RETENTION;
        }

        $runAt = time();
        $this->riskRepository->save($risks, $runAt, $retention);

        $hash          = $this->hashRisks($risks);
        $lastHash      = $settings['last_notification'] ?? '';
        $isTestingMode = ($settings['notifications']['frequency'] ?? 'daily') === 'testing';

        $shouldNotify = $notify && ($isTestingMode || (! empty($risks) && $hash !== $lastHash));
        $manualThrottle = $context === 'manual'
            && $this->isManualNotificationThrottled($settings, $runAt);

        if ($shouldNotify && $manualThrottle) {
            $shouldNotify = false;
        }

        if ($shouldNotify) {
            $this->notifier->notify($risks);
            $this->settingsRepository->saveNotificationHash($hash);

            if ($context === 'manual') {
                $this->settingsRepository->saveManualNotificationTime($runAt);
            }

            return true;
        }

        if ($notify && $hash !== $lastHash && ! $manualThrottle) {
            $this->settingsRepository->saveNotificationHash($hash);
        }

        return false;
    }

    /**
     * @return 'sent'|'unchanged'|'throttled'
     */
    public function sendNotifications(bool $force = false, bool $respectThrottle = true): string
    {
        $risks     = $this->riskRepository->all();
        $settings  = $this->settingsRepository->get();
        $now       = time();
        $hash      = $this->hashRisks($risks);
        $lastHash  = $settings['last_notification'] ?? '';
        $frequency = $settings['notifications']['frequency'] ?? 'daily';

        if ($respectThrottle && $this->isManualNotificationThrottled($settings, $now)) {
            return 'throttled';
        }

        if (! $force && $frequency !== 'testing' && $hash === $lastHash) {
            return 'unchanged';
        }

        $this->notifier->notify($risks);
        $this->settingsRepository->saveManualNotificationTime($now);
        $this->settingsRepository->saveNotificationHash($hash);

        return 'sent';
    }

    /**
     * @param array<string, mixed> $schedules
     */
    public function registerCronSchedules(array $schedules): array
    {
        if (! isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Once Weekly', 'Site-Add-on-Watchdog-main'),
            ];
        }

        if (! isset($schedules[self::QUEUE_CRON_SCHEDULE])) {
            $schedules[self::QUEUE_CRON_SCHEDULE] = [
                'interval' => $this->queueProcessorInterval(),
                'display'  => __('Every 5 Minutes (Watchdog queue)', 'Site-Add-on-Watchdog-main'),
            ];
        }

        if (! isset($schedules[self::LEGACY_QUEUE_CRON_SCHEDULE])) {
            $schedules[self::LEGACY_QUEUE_CRON_SCHEDULE] = [
                'interval' => $this->queueProcessorInterval(),
                'display'  => __('Every 5 Minutes (Watchdog queue)', 'Site-Add-on-Watchdog-main'),
            ];
        }

        if (! isset($schedules['testing'])) {
            $schedules['testing'] = [
                'interval' => TestingMode::intervalInSeconds(),
                'display'  => sprintf(
                    /* translators: %d: interval minutes */
                    __('Every %d Minutes (testing)', 'Site-Add-on-Watchdog-main'),
                    TestingMode::INTERVAL_MINUTES
                ),
            ];
        }

        return $schedules;
    }

    public function registerRestRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/cron', [
            'methods'             => ['GET', 'POST'],
            'callback'            => [$this, 'handleRestCronRequest'],
            'permission_callback' => [$this, 'authorizeCronRequest'],
            'args'                => [
                'force' => [
                    'type' => 'boolean',
                ],
                'notify_only' => [
                    'type' => 'boolean',
                ],
            ],
        ]);
    }

    private function clearScheduledHook(string $hook): void
    {
        $timestamp = wp_next_scheduled($hook);
        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
            $timestamp = wp_next_scheduled($hook);
        }
    }

    private function cleanupUpdateCheckState(): void
    {
        delete_option(self::UPDATE_CHECK_OPTION);
        delete_option(self::LEGACY_UPDATE_CHECK_OPTION);
    }

    private function scheduleNotificationQueueProcessor(): void
    {
        $this->clearScheduledHook(self::LEGACY_QUEUE_CRON_HOOK);

        $timestamp = wp_next_scheduled(self::QUEUE_CRON_HOOK);
        $currentSchedule = $timestamp ? wp_get_schedule(self::QUEUE_CRON_HOOK) : false;

        if ($timestamp && $currentSchedule === self::QUEUE_CRON_SCHEDULE) {
            return;
        }

        $this->clearScheduledHook(self::QUEUE_CRON_HOOK);

        $delay = $this->queueProcessorInterval();
        $nextRunAt = time() + $delay;

        wp_schedule_event($nextRunAt, self::QUEUE_CRON_SCHEDULE, self::QUEUE_CRON_HOOK);
    }

    private function scheduleDelayForFrequency(string $frequency): int
    {
        if ($frequency === 'testing') {
            return TestingMode::intervalInSeconds();
        }

        return defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
    }

    private function queueProcessorInterval(): int
    {
        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;

        return 5 * $minute;
    }

    public function renderCronDiagnostics(): void
    {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }

        $status = $this->getOptionWithLegacy(self::CRON_STATUS_OPTION, self::LEGACY_CRON_STATUS_OPTION, []);
        if (! is_array($status)) {
            return;
        }

        if (! empty($status['cron_disabled'])) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__(
                    'WP-Cron appears disabled. Set a system cron job to trigger wp-cron.php for Site Add-on Watchdog.',
                    'Site-Add-on-Watchdog-main'
                )
                . '</p></div>';

            return;
        }

        if (($status['overdue_streak'] ?? 0) >= 2) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__(
                    'Site Add-on Watchdog scans are overdue. Ensure system cron calls wp-cron.php regularly.',
                    'Site-Add-on-Watchdog-main'
                )
                . '</p></div>';
        }
    }

    public function getCronStatus(): array
    {
        $status = $this->getOptionWithLegacy(self::CRON_STATUS_OPTION, self::LEGACY_CRON_STATUS_OPTION, []);
        if (! is_array($status)) {
            $status = [];
        }

        return [
            'cron_disabled'  => ! empty($status['cron_disabled']),
            'overdue_streak' => (int) ($status['overdue_streak'] ?? 0),
            'last_checked'   => (int) ($status['last_checked'] ?? 0),
        ];
    }

    public function getCronSecret(): string
    {
        $settings = $this->settingsRepository->get();

        return (string) ($settings['notifications']['cron_secret'] ?? '');
    }

    public function flushNotificationQueue(): array
    {
        return $this->notifier->processQueue();
    }

    public function getCronEndpointUrl(): string
    {
        $base = rest_url(self::REST_NAMESPACE . '/cron');

        return add_query_arg('key', rawurlencode($this->getCronSecret()), $base);
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
        $status = $this->getOptionWithLegacy(self::CRON_STATUS_OPTION, self::LEGACY_CRON_STATUS_OPTION, []);
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
            $this->logCronWarning('[Site Add-on Watchdog] WP-Cron appears disabled. '
                . 'Configure system cron to trigger wp-cron.php.');
        } elseif ($status['overdue_streak'] >= 2) {
            $this->logCronWarning('[Site Add-on Watchdog] Scheduled scans are overdue. '
                . 'Ensure cron can reach wp-cron.php.');
        }
    }

    private function logCronWarning(string $message): void
    {
        if (function_exists('wp_debug_log')) {
            wp_debug_log($message);
        }
    }

    private function cronIntervalForFrequency(string $frequency): int
    {
        $schedules = wp_get_schedules();
        if (isset($schedules[$frequency]['interval'])) {
            return (int) $schedules[$frequency]['interval'];
        }

        if ($frequency === 'testing') {
            return TestingMode::intervalInSeconds();
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

    private function scheduleNeedsRealignment(int $scheduledAt, int $desiredAt, int $interval): bool
    {
        if ($interval <= 0) {
            return false;
        }

        $tolerance = max(60, (int) floor($interval * 0.05));

        return abs($scheduledAt - $desiredAt) > $tolerance;
    }

    private function nextRunTimestamp(string $frequency, array $settings): int
    {
        if ($frequency === 'weekly') {
            $weekday = (int) ($settings['notifications']['weekly_day'] ?? 1);
            $time    = (string) ($settings['notifications']['weekly_time'] ?? '08:00');

            return $this->nextWeeklyRunTimestamp($weekday, $time);
        }

        if ($frequency === 'testing') {
            return time() + $this->scheduleDelayForFrequency($frequency);
        }

        $time = (string) ($settings['notifications']['daily_time'] ?? '08:00');

        return $this->nextDailyRunTimestamp($time);
    }

    private function nextDailyRunTimestamp(string $time): int
    {
        $timezone   = $this->getTimezone();
        $now        = new \DateTime('now', $timezone);
        $targetTime = $this->applyTimeToDate(new \DateTime('now', $timezone), $time);

        if ($targetTime->getTimestamp() <= $now->getTimestamp()) {
            $targetTime->modify('+1 day');
            $targetTime = $this->applyTimeToDate($targetTime, $time);
        }

        return $targetTime->getTimestamp();
    }

    private function nextWeeklyRunTimestamp(int $weekday, string $time): int
    {
        $weekday    = max(1, min(7, $weekday));
        $timezone   = $this->getTimezone();
        $now        = new \DateTime('now', $timezone);
        $targetTime = $this->applyTimeToDate(new \DateTime('now', $timezone), $time);
        $currentDay = (int) $targetTime->format('N');

        $daysUntilTarget = $weekday - $currentDay;
        if ($daysUntilTarget < 0 || ($daysUntilTarget === 0 && $targetTime->getTimestamp() <= $now->getTimestamp())) {
            $daysUntilTarget += 7;
        }

        if ($daysUntilTarget !== 0) {
            $targetTime->modify('+' . $daysUntilTarget . ' days');
            $targetTime = $this->applyTimeToDate($targetTime, $time);
        }

        return $targetTime->getTimestamp();
    }

    private function applyTimeToDate(\DateTime $dateTime, string $time): \DateTime
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            $matches = [null, '08', '00'];
        }

        $hour   = max(0, min(23, (int) $matches[1]));
        $minute = max(0, min(59, (int) $matches[2]));

        $dateTime->setTime($hour, $minute);

        return $dateTime;
    }

    private function getTimezone(): \DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $timezoneString = (string) get_option('timezone_string');
        if ($timezoneString !== '') {
            try {
                return new \DateTimeZone($timezoneString);
            } catch (\Exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            }
        }

        $gmtOffset = get_option('gmt_offset');
        if (is_numeric($gmtOffset)) {
            $secondsInHour = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
            $secondsOffset = (int) round((float) $gmtOffset * $secondsInHour);
            $timezoneName  = timezone_name_from_abbr('', $secondsOffset, 0);
            if ($timezoneName !== false) {
                try {
                    return new \DateTimeZone($timezoneName);
                } catch (\Exception) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                }
            }
        }

        return new \DateTimeZone('UTC');
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

    private function isManualNotificationThrottled(array $settings, int $now): bool
    {
        $lastManualNotification = (int) ($settings['notifications']['last_manual_notification_at'] ?? 0);

        if ($lastManualNotification <= 0) {
            return false;
        }

        return ($now - $lastManualNotification) < self::MANUAL_NOTIFICATION_INTERVAL;
    }

    public function handleRestCronRequest(WP_REST_Request $request)
    {
        $notifyOnly = (bool) $request->get_param('notify_only');
        $force      = (bool) $request->get_param('force');

        if ($notifyOnly) {
            $result = $this->sendNotifications($force, false);

            $queueResult = $this->flushNotificationQueue();

            return [
                'status'  => $result,
                'message' => __('Notifications processed.', 'Site-Add-on-Watchdog-main'),
                'queue'   => $queueResult,
            ];
        }

        $this->runScan(true, $force ? 'rest-force' : 'rest');

        $queueResult = $this->flushNotificationQueue();

        return [
            'status'  => 'ok',
            'message' => __('Scan triggered successfully.', 'Site-Add-on-Watchdog-main'),
            'queue'   => $queueResult,
        ];
    }

    private function getOptionWithLegacy(string $option, string $legacy, mixed $default): mixed
    {
        $value = get_option($option, $default);
        if ($value !== $default) {
            return $value;
        }

        $legacyValue = get_option($legacy, $default);
        if ($legacyValue !== $default) {
            update_option($option, $legacyValue, false);

            return $legacyValue;
        }

        return $value;
    }

    public function validateCronRequest(WP_REST_Request $request): bool
    {
        $provided = (string) ($request->get_param('key') ?? '');
        $stored   = $this->getCronSecret();

        if ($stored === '') {
            return false;
        }

        return hash_equals($stored, $provided);
    }

    public function authorizeCronRequest(WP_REST_Request $request): bool|\WP_Error
    {
        if ($this->validateCronRequest($request)) {
            return true;
        }

        $stored = $this->getCronSecret();
        if ($stored === '') {
            return new \WP_Error(
                'watchdog_cron_secret_missing',
                __('Cron secret is missing. Please resave your settings.', 'Site-Add-on-Watchdog-main'),
                ['status' => 403]
            );
        }

        return new \WP_Error(
            'watchdog_cron_secret_invalid',
            __('Invalid cron secret.', 'Site-Add-on-Watchdog-main'),
            ['status' => 403]
        );
    }

    /**
     * @param Risk[] $risks
     */
    private function hashRisks(array $risks): string
    {
        return md5(wp_json_encode(array_map(static fn (Risk $risk): array => $risk->toArray(), $risks)));
    }
}
