<?php

namespace Watchdog\Repository;

use Watchdog\Models\Risk;
use Watchdog\Version;

class RiskRepository
{
    private const PREFIX = Version::PREFIX;
    private const RISKS_OPTION = self::PREFIX . '_risks';
    private const IGNORE_OPTION = self::PREFIX . '_ignore';
    private const HISTORY_OPTION = self::PREFIX . '_risk_history';

    private const LEGACY_RISKS_OPTION = 'wp_watchdog_risks';
    private const LEGACY_IGNORE_OPTION = 'wp_watchdog_ignore';
    private const LEGACY_HISTORY_OPTION = 'wp_watchdog_risk_history';

    public const DEFAULT_HISTORY_RETENTION = 5;

    /**
     * @return Risk[]
     */
    public function all(): array
    {
        $stored = $this->getOptionWithLegacy(self::RISKS_OPTION, self::LEGACY_RISKS_OPTION, []);
        if (! is_array($stored)) {
            return [];
        }

        $normalized = [];

        foreach ($stored as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! isset($entry['plugin_slug'], $entry['plugin_name'], $entry['local_version'])) {
                continue;
            }

            $normalized[] = [
                'plugin_slug'   => (string) $entry['plugin_slug'],
                'plugin_name'   => (string) $entry['plugin_name'],
                'local_version' => (string) $entry['local_version'],
                'remote_version' => isset($entry['remote_version']) && $entry['remote_version'] !== ''
                    ? (string) $entry['remote_version']
                    : null,
                'reasons' => isset($entry['reasons']) && is_array($entry['reasons'])
                    ? array_values(array_map(static fn ($reason): string => (string) $reason, $entry['reasons']))
                    : [],
                'details' => $this->normalizeDetails($entry['details'] ?? []),
            ];
        }

        if ($normalized === []) {
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
            $normalized
        ));
    }

    /**
     * @param Risk[] $risks
     */
    public function save(array $risks, ?int $runAt = null, ?int $retention = null): void
    {
        $normalized = array_map(static fn (Risk $risk): array => $risk->toArray(), $risks);

        update_option(self::RISKS_OPTION, $normalized, false);

        $runAt    = $runAt ?? time();
        $history  = $this->loadHistory();
        $history  = array_filter(
            $history,
            static fn (array $entry): bool => $entry['run_at'] !== $runAt
        );
        $history[] = [
            'run_at' => $runAt,
            'risks'  => $normalized,
        ];

        usort($history, static fn (array $a, array $b): int => $a['run_at'] <=> $b['run_at']);

        $limit = $this->sanitizeRetention($retention);
        if (count($history) > $limit) {
            $history = array_slice($history, -$limit);
        }

        update_option(self::HISTORY_OPTION, array_values($history), false);
    }

    public function history(int $limit): array
    {
        $history = $this->loadHistory();

        usort($history, static fn (array $a, array $b): int => $b['run_at'] <=> $a['run_at']);

        if ($limit > 0) {
            $history = array_slice($history, 0, $limit);
        }

        return array_map(
            static function (array $entry): array {
                $entry['risk_count'] = count($entry['risks']);

                return $entry;
            },
            $history
        );
    }

    public function historyEntry(int $runAt): ?array
    {
        foreach ($this->loadHistory() as $entry) {
            if ($entry['run_at'] === $runAt) {
                $entry['risk_count'] = count($entry['risks']);

                return $entry;
            }
        }

        return null;
    }

    /**
     * @param Risk[] $risks
     * @return array{added:Risk[], changed:Risk[]}
     */
    public function diffWithLatest(array $risks): array
    {
        $history = $this->history(2);
        $latest  = $history[0]['risks'] ?? [];

        $currentMap  = $this->buildSignatureMapFromRisks($risks);
        $previousMap = $this->buildSignatureMapFromStored($latest);

        if (
            $previousMap !== []
            && $this->signatureMapsMatch($previousMap, $currentMap)
            && isset($history[1]['risks'])
        ) {
            $previousMap = $this->buildSignatureMapFromStored($history[1]['risks']);
        }

        $added   = [];
        $changed = [];

        foreach ($risks as $risk) {
            $normalized = $this->normalizeRiskArray($risk->toArray());
            if ($normalized['plugin_slug'] === '') {
                continue;
            }

            if (! isset($previousMap[$normalized['plugin_slug']])) {
                $added[] = $risk;
                continue;
            }

            $signature = $this->buildRiskSignature($normalized);
            if ($signature !== $previousMap[$normalized['plugin_slug']]) {
                $changed[] = $risk;
            }
        }

        return [
            'added'   => $added,
            'changed' => $changed,
        ];
    }

    /**
     * @return string[]
     */
    public function ignored(): array
    {
        $ignored = $this->getOptionWithLegacy(self::IGNORE_OPTION, self::LEGACY_IGNORE_OPTION, []);
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

    private function loadHistory(): array
    {
        $stored = $this->getOptionWithLegacy(self::HISTORY_OPTION, self::LEGACY_HISTORY_OPTION, []);
        if (! is_array($stored)) {
            return [];
        }

        $history = [];

        foreach ($stored as $entry) {
            $normalized = $this->normalizeHistoryEntry($entry);
            if ($normalized !== null) {
                $history[] = $normalized;
            }
        }

        return $history;
    }

    private function normalizeHistoryEntry(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $runAt = isset($entry['run_at']) ? (int) $entry['run_at'] : 0;
        if ($runAt <= 0) {
            return null;
        }

        $risks = [];
        if (isset($entry['risks']) && is_array($entry['risks'])) {
            foreach ($entry['risks'] as $risk) {
                if (! is_array($risk)) {
                    continue;
                }

                $risks[] = [
                    'plugin_slug'   => isset($risk['plugin_slug']) ? (string) $risk['plugin_slug'] : '',
                    'plugin_name'   => isset($risk['plugin_name']) ? (string) $risk['plugin_name'] : '',
                    'local_version' => isset($risk['local_version']) ? (string) $risk['local_version'] : '',
                    'remote_version' => isset($risk['remote_version']) && $risk['remote_version'] !== ''
                        ? (string) $risk['remote_version']
                        : null,
                    'reasons' => isset($risk['reasons']) && is_array($risk['reasons'])
                        ? array_values(array_map(static fn ($reason): string => (string) $reason, $risk['reasons']))
                        : [],
                    'details' => $this->normalizeDetails($risk['details'] ?? []),
                ];
            }
        }

        return [
            'run_at' => $runAt,
            'risks'  => $risks,
        ];
    }

    private function normalizeDetails(mixed $details): array
    {
        if (! is_array($details)) {
            $details = [];
        }

        if (! isset($details['vulnerabilities'])) {
            return $details;
        }

        if (! is_array($details['vulnerabilities'])) {
            $details['vulnerabilities'] = [];
        } else {
            $details['vulnerabilities'] = array_values(
                array_filter($details['vulnerabilities'], static fn ($item): bool => is_array($item))
            );
        }

        return $details;
    }

    /**
     * @return array{plugin_slug:string, local_version:string, remote_version:?string, reasons:string[], details:array}
     */
    private function normalizeRiskArray(array $risk): array
    {
        return [
            'plugin_slug'   => isset($risk['plugin_slug']) ? (string) $risk['plugin_slug'] : '',
            'local_version' => isset($risk['local_version']) ? (string) $risk['local_version'] : '',
            'remote_version' => isset($risk['remote_version']) && $risk['remote_version'] !== ''
                ? (string) $risk['remote_version']
                : null,
            'reasons' => isset($risk['reasons']) && is_array($risk['reasons'])
                ? array_values(array_map(static fn ($reason): string => (string) $reason, $risk['reasons']))
                : [],
            'details' => $this->normalizeDetails($risk['details'] ?? []),
        ];
    }

    /**
     * @param Risk[] $risks
     * @return array<string, string>
     */
    private function buildSignatureMapFromRisks(array $risks): array
    {
        $map = [];
        foreach ($risks as $risk) {
            $normalized = $this->normalizeRiskArray($risk->toArray());
            if ($normalized['plugin_slug'] === '') {
                continue;
            }

            $map[$normalized['plugin_slug']] = $this->buildRiskSignature($normalized);
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function buildSignatureMapFromStored(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $map = [];
        foreach ($stored as $risk) {
            if (! is_array($risk)) {
                continue;
            }

            $normalized = $this->normalizeRiskArray($risk);
            if ($normalized['plugin_slug'] === '') {
                continue;
            }

            $map[$normalized['plugin_slug']] = $this->buildRiskSignature($normalized);
        }

        return $map;
    }

    /**
     * @param array<string, string> $left
     * @param array<string, string> $right
     */
    private function signatureMapsMatch(array $left, array $right): bool
    {
        ksort($left);
        ksort($right);

        return $left === $right;
    }

    private function buildRiskSignature(array $risk): string
    {
        $payload = [
            'local_version' => $risk['local_version'],
            'remote_version' => $risk['remote_version'],
            'reasons' => $this->normalizeComparisonValue($risk['reasons']),
            'details' => $this->normalizeComparisonValue($risk['details']),
        ];

        $encoded = wp_json_encode($payload);
        if (! is_string($encoded)) {
            return '';
        }

        return $encoded;
    }

    private function normalizeComparisonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->isAssociativeArray($value)) {
            ksort($value);
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeComparisonValue($item);
            }

            return $normalized;
        }

        $normalized = array_map(fn ($item) => $this->normalizeComparisonValue($item), $value);
        usort($normalized, static function ($left, $right): int {
            $leftValue = wp_json_encode($left);
            $rightValue = wp_json_encode($right);

            if (! is_string($leftValue)) {
                $leftValue = '';
            }

            if (! is_string($rightValue)) {
                $rightValue = '';
            }

            return strcmp($leftValue, $rightValue);
        });

        return array_values($normalized);
    }

    private function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function getOptionWithLegacy(string $option, string $legacyOption, mixed $default): mixed
    {
        $value = get_option($option, $default);
        if ($value !== $default) {
            return $value;
        }

        $legacyValue = get_option($legacyOption, $default);
        if ($legacyValue !== $default) {
            update_option($option, $legacyValue, false);

            return $legacyValue;
        }

        return $value;
    }

    private function sanitizeRetention(?int $retention): int
    {
        $retention = (int) ($retention ?? self::DEFAULT_HISTORY_RETENTION);

        if ($retention < 1) {
            return self::DEFAULT_HISTORY_RETENTION;
        }

        return $retention;
    }
}
