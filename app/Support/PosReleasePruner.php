<?php

namespace App\Support;

use RuntimeException;

/** Safely remove superseded installer files from the three bounded release folders. */
final class PosReleasePruner
{
    private const DIRECTORIES = ['pos-releases', 'pos-python-releases', 'jet-pos-python-releases'];

    public function __construct(private readonly ?string $appStoragePath = null) {}

    /** @return array<int, array{path:string,size:int,deleted:bool}> */
    public function prune(bool $dryRun = false): array
    {
        $plans = $this->candidates();
        $results = [];
        foreach ($plans as $path => $size) {
            $deleted = false;
            if (! $dryRun && (file_exists($path) || is_link($path))) {
                if (! @unlink($path)) {
                    throw new RuntimeException('ลบไฟล์ไม่ได้: '.basename($path));
                }
                $deleted = true;
            }
            $results[] = ['path' => $path, 'size' => $size, 'deleted' => $deleted];
        }

        return $results;
    }

    /** @return array<string, int> absolute file path => file size */
    private function candidates(): array
    {
        $root = $this->appStoragePath ?? storage_path('app');
        $candidates = [];

        foreach (self::DIRECTORIES as $directoryName) {
            $directory = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$directoryName;
            if (! is_dir($directory) || is_link($directory)) {
                continue;
            }

            $files = glob($directory.DIRECTORY_SEPARATOR.'*') ?: [];
            $installers = array_values(array_filter($files, static fn (string $path): bool => is_file($path)
                && ! is_link($path)
                && preg_match('/\.(exe|msi|zip)$/i', basename($path)) === 1
                && ! str_ends_with(strtolower(basename($path)), '.previous.exe')));
            usort($installers, static function (string $left, string $right): int {
                $versionOrder = version_compare(self::versionFromName($right) ?? '0.0.0', self::versionFromName($left) ?? '0.0.0');

                return $versionOrder !== 0 ? $versionOrder : (filemtime($right) <=> filemtime($left));
            });

            $keep = [];
            if (isset($installers[0])) {
                $keep[basename($installers[0])] = true;
            }
            if ($directoryName === 'pos-releases') {
                $manifestFile = $directory.DIRECTORY_SEPARATOR.'latest.json';
                if (is_file($manifestFile)) {
                    $manifest = json_decode((string) @file_get_contents($manifestFile), true);
                    $url = data_get($manifest, 'platforms.windows-x86_64.url');
                    if (is_string($url) && $url !== '') {
                        $path = parse_url($url, PHP_URL_PATH) ?: $url;
                        $reference = basename(rawurldecode($path));
                        if ($reference !== '' && $reference !== '.' && $reference !== '..') {
                            $keep[$reference] = true;
                        }
                    }
                }
            }

            foreach ($files as $file) {
                if (! is_file($file) || is_link($file)) {
                    continue;
                }
                $name = basename($file);
                if (str_ends_with(strtolower($name), '.previous.exe')) {
                    $candidates[$file] = (int) filesize($file);

                    continue;
                }
                if (preg_match('/\.(exe|msi|zip)$/i', $name) === 1 && ! isset($keep[$name])) {
                    $candidates[$file] = (int) filesize($file);
                }
            }

            // A signature belongs to its matching .exe (either foo.exe.sig or foo.sig).
            // Remove it when its installer is already scheduled for removal or is missing.
            foreach ($files as $file) {
                if (! is_file($file) || is_link($file) || ! str_ends_with(strtolower($file), '.sig')) {
                    continue;
                }
                $name = basename($file);
                $exeName = str_ends_with(strtolower($name), '.exe.sig')
                    ? substr($name, 0, -4)
                    : substr($name, 0, -4).'.exe';
                $exePath = $directory.DIRECTORY_SEPARATOR.$exeName;
                if (! is_file($exePath) || isset($candidates[$exePath])) {
                    $candidates[$file] = (int) filesize($file);
                }
            }
        }

        ksort($candidates);

        return $candidates;
    }

    private static function versionFromName(string $path): ?string
    {
        preg_match('/(?<!\d)(\d+\.\d+\.\d+)(?!\d)/', basename($path), $matches);

        return $matches[1] ?? null;
    }
}
