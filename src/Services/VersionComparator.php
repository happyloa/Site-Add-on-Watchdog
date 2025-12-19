<?php

namespace Watchdog\Services;

class VersionComparator
{
    /**
     * Determines if the remote version is at least two minor versions ahead of the local one.
     */
    public function isTwoMinorVersionsBehind(string $localVersion, string $remoteVersion): bool
    {
        $minorGap = $this->minorVersionsBehind($localVersion, $remoteVersion);

        return $minorGap !== null && $minorGap >= 2;
    }

    public function isStandardVersion(string $version): bool
    {
        $trimmed = trim($version);
        if ($trimmed === '') {
            return false;
        }

        return preg_match('/^\d+(?:\.\d+){0,2}$/', $trimmed) === 1;
    }

    public function minorVersionsBehind(string $localVersion, string $remoteVersion): ?int
    {
        if (! $this->isStandardVersion($localVersion) || ! $this->isStandardVersion($remoteVersion)) {
            return null;
        }

        $localParts  = $this->normaliseVersion($localVersion);
        $remoteParts = $this->normaliseVersion($remoteVersion);

        if ($remoteParts['major'] !== $localParts['major']) {
            return null;
        }

        $gap = $remoteParts['minor'] - $localParts['minor'];

        return $gap > 0 ? $gap : 0;
    }

    private function normaliseVersion(string $version): array
    {
        $parts = array_map('intval', array_pad(explode('.', preg_replace('/[^0-9.]/', '', $version)), 3, 0));

        return [
            'major' => $parts[0] ?? 0,
            'minor' => $parts[1] ?? 0,
            'patch' => $parts[2] ?? 0,
        ];
    }
}
