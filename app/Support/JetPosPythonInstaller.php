<?php

namespace App\Support;

final class JetPosPythonInstaller
{
    /** Find the latest isolated JET POS Python UAT installer published to the ERP server. */
    public static function current(): ?array
    {
        $files = glob(storage_path('app/jet-pos-python-releases/JET-POS-Python-UAT-*.exe')) ?: [];
        $files = array_values(array_filter($files, 'is_file'));

        usort($files, static function (string $left, string $right): int {
            $versionOrder = version_compare(
                self::versionFromPath($right) ?? '0.0.0',
                self::versionFromPath($left) ?? '0.0.0',
            );

            return $versionOrder !== 0 ? $versionOrder : filemtime($right) <=> filemtime($left);
        });

        $path = $files[0] ?? null;
        if (! $path) {
            return null;
        }

        return [
            'path' => $path,
            'filename' => basename($path),
            'version' => self::versionFromPath($path) ?? 'ไม่ระบุรุ่น',
        ];
    }

    private static function versionFromPath(string $path): ?string
    {
        preg_match('/-(\d+\.\d+\.\d+)\.exe$/', basename($path), $matches);

        return $matches[1] ?? null;
    }
}
