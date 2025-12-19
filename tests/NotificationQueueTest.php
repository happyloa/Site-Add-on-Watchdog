<?php

use Brain\Monkey\Functions;
use Watchdog\Services\NotificationQueue;
use Watchdog\Version;

class NotificationQueueTest extends TestCase
{
    public function testPermanentFailureRemovesJobAndRecordsMetadata(): void
    {
        $options = [];
        $now     = time();

        $queueOption  = Version::PREFIX . '_notification_queue';
        $failedOption = Version::PREFIX . '_failed_notification';

        $options[$queueOption] = [[
            'channel'         => 'email',
            'description'     => 'Example notification',
            'payload'         => ['foo' => 'bar'],
            'attempts'        => 4,
            'next_attempt_at' => $now - 10,
        ]];

        Functions\when('get_option')->alias(static function (string $key, $default = []) use (&$options) {
            return $options[$key] ?? $default;
        });

        Functions\when('update_option')->alias(static function (string $key, $value) use (&$options) {
            $options[$key] = $value;

            return true;
        });

        $queue  = new NotificationQueue();
        $result = $queue->process(static fn () => 'delivery failed');

        self::assertSame(['processed' => 1, 'succeeded' => 0], $result);
        self::assertSame([], $options[$queueOption]);
        self::assertArrayHasKey($failedOption, $options);
        self::assertSame('email', $options[$failedOption]['channel']);
        self::assertSame('Example notification', $options[$failedOption]['description']);
        self::assertSame(['foo' => 'bar'], $options[$failedOption]['payload']);
        self::assertSame('delivery failed', $options[$failedOption]['last_error']);
        self::assertSame(5, $options[$failedOption]['attempts']);
        self::assertGreaterThanOrEqual($now, $options[$failedOption]['failed_at']);
        self::assertLessThanOrEqual($now + 2, $options[$failedOption]['failed_at']);
    }
}
