<?php

namespace App\Support;

class AssetVersion
{
    public static function url(string $path): string
    {
        $normalized = ltrim($path, '/');
        $url = asset($normalized);
        $fullPath = public_path($normalized);

        if (! is_file($fullPath)) {
            return $url;
        }

        $mtime = @filemtime($fullPath);

        return $mtime === false ? $url : $url.'?v='.$mtime;
    }
}
