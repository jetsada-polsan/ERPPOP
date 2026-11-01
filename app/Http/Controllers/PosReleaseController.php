<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Support\PosReleaseManifest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PosReleaseController extends Controller
{
    public function downloadLatest(): Response
    {
        $manifest = app(PosReleaseManifest::class)->current();
        $url = data_get($manifest, 'platforms.windows-x86_64.url');
        $filename = is_string($url) ? basename((string) parse_url($url, PHP_URL_PATH)) : '';

        if ($filename === '' || $filename === 'latest.json' || ! is_file(storage_path('app/pos-releases/'.$filename))) {
            return redirect()->route('python-pos.download');
        }

        return $this->download($filename);
    }

    public function latest(): JsonResponse
    {
        $manifest = app(PosReleaseManifest::class)->current();
        abort_unless(is_array($manifest), 404, 'ยังไม่มี POS รุ่นอัปเดต');

        return response()->json($manifest)
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    public function download(string $filename): BinaryFileResponse
    {
        abort_unless(preg_match('/\A[A-Za-z0-9._-]+\z/', $filename), 404);
        $path = storage_path('app/pos-releases/'.$filename);
        abort_unless(is_file($path) && $filename !== 'latest.json', 404);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'public, max-age=86400, immutable',
        ]);
    }
}
