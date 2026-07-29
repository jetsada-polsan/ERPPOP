<?php

namespace App\Support;

use JsonException;

class PosReleaseManifest
{
    public function current(): ?array
    {
        $stored = null;
        $path = storage_path('app/pos-releases/latest.json');
        if (is_file($path)) {
            try {
                $stored = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $stored = null;
            }
        }

        $fallback = config('pos_release');
        if (! is_array($fallback)) {
            return $stored;
        }

        return version_compare((string) data_get($fallback, 'version', '0.0.0'), (string) data_get($stored, 'version', '0.0.0'), '>')
            ? $fallback
            : $stored;
    }
}
