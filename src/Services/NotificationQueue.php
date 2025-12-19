<?php

namespace Watchdog\Services;

use Watchdog\Version;

class NotificationQueue
{
    private const PREFIX = Version::PREFIX;
    private const QUEUE_OPTION  = self::PREFIX . '_notification_queue';
    private const FAILED_OPTION = self::PREFIX . '_failed_notification';

    private const LEGACY_QUEUE_OPTION  = 'wp_watchdog_notification_queue';
    private const LEGACY_FAILED_OPTION = 'wp_watchdog_failed_notification';

    private const BASE_DELAY = 300;
    private const MAX_DELAY  = 21600;
    private const MAX_ATTEMPTS = 5;

    /**
     * @param array<int, array<string, mixed>> $jobs
     */
    public function enqueue(array $jobs): void
    {
        $queue = $this->loadQueue();
        $now   = time();

        foreach ($jobs as $job) {
            $normalized = $this->normalizeJob($job, $now);
            if ($normalized !== null) {
                $queue[] = $normalized;
            }
        }

        $this->persistQueue($queue);
    }

    /**
     * @param callable(array): (bool|string) $sender
     *
     * @return array{processed:int,succeeded:int}
     */
    public function process(callable $sender): array
    {
        $queue     = $this->loadQueue();
        $now       = time();
        $processed = 0;
        $succeeded = 0;

        foreach ($queue as $index => $job) {
            if ($job['next_attempt_at'] > $now) {
                continue;
            }

            $processed++;
            $result = $sender($job);

            if ($result === true) {
                unset($queue[$index]);
                $succeeded++;
                continue;
            }

            $failedJob = $this->markFailedJob($job, $result, $now);
            if (! $failedJob['should_retry']) {
                unset($queue[$index]);
                $this->recordFailure($failedJob, $now);
                continue;
            }

            $queue[$index] = $failedJob;
        }

        $this->persistQueue($queue);

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
        ];
    }

    public function getLastFailed(): ?array
    {
        $stored = $this->getOptionWithLegacy(self::FAILED_OPTION, self::LEGACY_FAILED_OPTION, []);
        if (! is_array($stored)) {
            return null;
        }

        $failed = [
            'channel'      => isset($stored['channel']) ? (string) $stored['channel'] : '',
            'description'  => isset($stored['description']) ? (string) $stored['description'] : '',
            'payload'      => isset($stored['payload']) && is_array($stored['payload']) ? $stored['payload'] : [],
            'last_error'   => isset($stored['last_error']) ? (string) $stored['last_error'] : '',
            'failed_at'    => isset($stored['failed_at']) ? (int) $stored['failed_at'] : time(),
            'attempts'     => isset($stored['attempts']) ? (int) $stored['attempts'] : 0,
        ];

        if ($failed['channel'] === '') {
            return null;
        }

        return $failed;
    }

    public function requeueLastFailed(): bool
    {
        $failed = $this->getLastFailed();
        if ($failed === null) {
            return false;
        }

        $job = [
            'channel'      => $failed['channel'],
            'description'  => $failed['description'],
            'payload'      => $failed['payload'],
            'attempts'     => 0,
            'next_attempt_at' => time(),
        ];

        $queue      = $this->loadQueue();
        $normalized = $this->normalizeJob($job, time());
        if ($normalized === null) {
            return false;
        }

        $queue[] = $normalized;

        $this->persistQueue($queue);
        delete_option(self::FAILED_OPTION);

        return true;
    }

    /**
     * @return array{length:int,next_attempt_at:?int}
     */
    public function getQueueStatus(): array
    {
        $queue = $this->loadQueue();
        $nextAttemptAt = null;

        foreach ($queue as $job) {
            $jobNextAttemptAt = isset($job['next_attempt_at']) ? (int) $job['next_attempt_at'] : null;
            if ($jobNextAttemptAt === null) {
                continue;
            }

            if ($nextAttemptAt === null || $jobNextAttemptAt < $nextAttemptAt) {
                $nextAttemptAt = $jobNextAttemptAt;
            }
        }

        return [
            'length' => count($queue),
            'next_attempt_at' => $nextAttemptAt,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadQueue(): array
    {
        $stored = $this->getOptionWithLegacy(self::QUEUE_OPTION, self::LEGACY_QUEUE_OPTION, []);
        if (! is_array($stored)) {
            return [];
        }

        $queue = [];

        foreach ($stored as $job) {
            if (! is_array($job)) {
                continue;
            }

            $normalized = $this->normalizeJob($job, time(), true);
            if ($normalized !== null) {
                $queue[] = $normalized;
            }
        }

        return array_values($queue);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function normalizeJob(array $job, int $now, bool $trusted = false): ?array
    {
        $channel = isset($job['channel']) ? (string) $job['channel'] : '';
        $payload = isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : [];
        $description = isset($job['description']) ? (string) $job['description'] : '';

        if ($channel === '' || $payload === []) {
            return null;
        }

        $attempts = isset($job['attempts']) ? (int) $job['attempts'] : 0;
        $attempts = max(0, $attempts);
        if ($attempts > self::MAX_ATTEMPTS) {
            $attempts = self::MAX_ATTEMPTS;
        }

        $nextAttempt = isset($job['next_attempt_at']) ? (int) $job['next_attempt_at'] : $now;
        if ($nextAttempt <= 0) {
            $nextAttempt = $now;
        }

        $id = isset($job['id']) && $trusted ? (string) $job['id'] : $this->generateId();

        return [
            'id'              => $id,
            'channel'         => $channel,
            'description'     => $description,
            'payload'         => $payload,
            'attempts'        => $attempts,
            'next_attempt_at' => $nextAttempt,
            'last_error'      => isset($job['last_error']) ? (string) $job['last_error'] : '',
        ];
    }

    private function markFailedJob(array $job, string $reason, int $now): array
    {
        $job['attempts'] = min(self::MAX_ATTEMPTS, (int) ($job['attempts'] ?? 0) + 1);
        $job['last_error'] = $reason;
        // Flag to avoid rescheduling when the job has permanently failed.
        $job['should_retry'] = $job['attempts'] < self::MAX_ATTEMPTS;

        $delay = $this->calculateDelay($job['attempts']);
        $job['next_attempt_at'] = $now + $delay;

        return $job;
    }

    public function recordFailure(array $job, int $failedAt): void
    {
        $payload = [
            'channel'     => (string) ($job['channel'] ?? ''),
            'description' => (string) ($job['description'] ?? ''),
            'payload'     => isset($job['payload']) && is_array($job['payload']) ? $job['payload'] : [],
            'last_error'  => (string) ($job['last_error'] ?? ''),
            'failed_at'   => $failedAt,
            'attempts'    => (int) ($job['attempts'] ?? 0),
        ];

        if ($payload['channel'] === '') {
            return;
        }

        update_option(self::FAILED_OPTION, $payload, false);
    }

    private function persistQueue(array $queue): void
    {
        update_option(self::QUEUE_OPTION, array_values($queue), false);
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

    private function calculateDelay(int $attempts): int
    {
        if ($attempts <= 0) {
            return self::BASE_DELAY;
        }

        $delay = self::BASE_DELAY * (2 ** ($attempts - 1));

        return (int) min(self::MAX_DELAY, $delay);
    }

    private function generateId(): string
    {
        return uniqid(self::PREFIX . '-', true);
    }
}
