<?php

namespace App\Support;

final class PythonPosInstaller
{
    /** Find the latest Windows installer published by the build pipeline. */
    public static function current(): ?array
    {
        $files = collect(array_merge(
            glob(storage_path('app/pos-releases/PopCentral-POS-UAT-*-setup.exe')) ?: [],
            glob(storage_path('app/pos-python-releases/PopCentral-POS-UAT-*-setup.exe')) ?: [],
        ))
            ->filter(fn (string $path) => is_file($path))
            ->unique()
            ->values()
            ->all();

        usort($files, static function (string $left, string $right): int {
            $versionOrder = version_compare(
                self::versionFromPath($right) ?? '0.0.0',
                self::versionFromPath($left) ?? '0.0.0'
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
            'size_bytes' => filesize($path),
            'updated_at' => filemtime($path),
        ];
    }

    private static function versionFromPath(string $path): ?string
    {
        preg_match('/-(\d+\.\d+\.\d+)-setup\.exe$/', basename($path), $matches);

        return $matches[1] ?? null;
    }
}
