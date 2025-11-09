<?php

namespace Watchdog\Repository;

use Watchdog\Models\Risk;

class RiskRepository
{
    private const RISKS_OPTION = 'wp_watchdog_risks';
    private const IGNORE_OPTION = 'wp_watchdog_ignore';

    /**
     * @return Risk[]
     */
    public function all(): array
    {
        $stored = get_option(self::RISKS_OPTION, []);
        if (! is_array($stored)) {
            return [];
        }

        return array_values(array_map(
            static function (array $item): Risk {
                return new Risk(
                    $item['plugin_slug'],
                    $item['plugin_name'],
                    $item['local_version'],
                    $item['remote_version'] ?? null,
                    $item['reasons'] ?? [],
                    $item['details'] ?? []
                );
            },
            $stored
        ));
    }

    /**
     * @param Risk[] $risks
     */
    public function save(array $risks): void
    {
        update_option(self::RISKS_OPTION, array_map(static fn (Risk $risk): array => $risk->toArray(), $risks), false);
    }

    /**
     * @return string[]
     */
    public function ignored(): array
    {
        $ignored = get_option(self::IGNORE_OPTION, []);
        if (! is_array($ignored)) {
            return [];
        }

        return array_values(array_filter(array_map('sanitize_text_field', $ignored)));
    }

    public function addIgnore(string $slug): void
    {
        $ignored   = $this->ignored();
        $ignored[] = $slug;
        update_option(self::IGNORE_OPTION, array_values(array_unique($ignored)), false);
    }

    public function removeIgnore(string $slug): void
    {
        $ignored = array_filter($this->ignored(), static fn (string $item) => $item !== $slug);
        update_option(self::IGNORE_OPTION, array_values($ignored), false);
    }
}
