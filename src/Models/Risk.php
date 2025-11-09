<?php

namespace Watchdog\Models;

class Risk
{
    public function __construct(
        public readonly string $pluginSlug,
        public readonly string $pluginName,
        public readonly string $localVersion,
        public readonly ?string $remoteVersion,
        public readonly array $reasons,
        public readonly array $details = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'plugin_slug'   => $this->pluginSlug,
            'plugin_name'   => $this->pluginName,
            'local_version' => $this->localVersion,
            'remote_version' => $this->remoteVersion,
            'reasons'       => $this->reasons,
            'details'       => $this->details,
        ];
    }
}
