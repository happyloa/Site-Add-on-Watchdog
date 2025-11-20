<?php

namespace Watchdog\Cli;

use Watchdog\Plugin;

class NotificationQueueCommand
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * @param array<int, string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $result = $this->plugin->flushNotificationQueue();

        $processed = (int) ($result['processed'] ?? 0);
        $succeeded = (int) ($result['succeeded'] ?? 0);

        if (class_exists('\WP_CLI')) {
            \WP_CLI::success(sprintf(
                'Processed %d notification jobs (%d succeeded).',
                $processed,
                $succeeded
            ));
        }
    }
}
