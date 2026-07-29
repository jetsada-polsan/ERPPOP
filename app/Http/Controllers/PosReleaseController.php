<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Support\PosReleaseManifest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PosReleaseController extends Controller
{
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
