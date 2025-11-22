<?php

namespace Watchdog;

class TestingMode
{
    public const INTERVAL_MINUTES = 20;
    public const DURATION_HOURS   = 3;

    public static function intervalInSeconds(): int
    {
        $minute = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;

        return self::INTERVAL_MINUTES * $minute;
    }

    public static function durationInSeconds(): int
    {
        return self::DURATION_HOURS * self::hourInSeconds();
    }

    private static function hourInSeconds(): int
    {
        return defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
    }
}
