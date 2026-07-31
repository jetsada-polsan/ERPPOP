<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PosImport\PosImportStagingService;
use App\Services\PosImport\PosImportValidationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyPosSyncController extends Controller
{
    public function store(Request $request, PosImportStagingService $staging, PosImportValidationService $validation): JsonResponse
    {
        $raw = $request->getContent();
        $secret = (string) config('legacy_sync.shared_secret');
        $timestamp = (int) $request->header('X-Legacy-Sync-Timestamp', 0);
        $signature = (string) $request->header('X-Legacy-Sync-Signature', '');

        abort_unless($secret !== '' && $timestamp > 0, 403, 'Legacy sync is not configured.');
        abort_unless(abs(now()->timestamp - $timestamp) <= (int) config('legacy_sync.max_clock_skew_seconds'), 401, 'Expired sync request.');
        abort_unless(hash_equals(hash_hmac('sha256', $timestamp.'.'.$raw, $secret), $signature), 401, 'Invalid sync signature.');

        $data = $request->validate([
            'pos_code' => ['required', 'string', 'max:20'],
            'sale_date' => ['required', 'date'],
            'receipts' => ['required', 'array'],
            'items' => ['required', 'array'],
            'payments' => ['required', 'array'],
            'payment_type_names' => ['nullable', 'array'],
        ]);

        $batch = $staging->stagePayload(
            $data['pos_code'],
            Carbon::parse($data['sale_date']),
            $data['receipts'],
            $data['items'],
            $data['payments'],
            $data['payment_type_names'] ?? [],
        );
        $batch = $validation->validate($batch);

        return response()->json([
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'record_count' => $batch->record_count,
            'error_count' => $batch->errors()->count(),
        ]);
    }
}
