<?php

namespace Watchdog\Services;

class VersionComparator
{
    /**
     * Determines if the remote version is at least two minor versions ahead of the local one.
     */
    public function isTwoMinorVersionsBehind(string $localVersion, string $remoteVersion): bool
    {
        $localParts  = $this->normaliseVersion($localVersion);
        $remoteParts = $this->normaliseVersion($remoteVersion);

        if ($remoteParts['major'] > $localParts['major']) {
            return true;
        }

        if ($remoteParts['major'] < $localParts['major']) {
            return false;
        }

        return ($remoteParts['minor'] - $localParts['minor']) >= 2;
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
