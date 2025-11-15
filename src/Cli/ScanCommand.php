<?php

namespace Watchdog\Cli;

use Watchdog\Plugin;

class ScanCommand
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
        $notify = true;

        if (array_key_exists('notify', $assocArgs)) {
            $value = $assocArgs['notify'];

            if (is_bool($value)) {
                $notify = $value;
            } elseif ($value !== null) {
                $filtered = filter_var(
                    (string) $value,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                );

                if ($filtered !== null) {
                    $notify = $filtered;
                }
            }
        }

        $this->plugin->runScan($notify);
    }
}
