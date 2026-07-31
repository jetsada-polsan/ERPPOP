<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyBackofficeSummaryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $secret = (string) config('legacy_sync.shared_secret');
        $timestamp = (int) $request->header('X-Legacy-Sync-Timestamp', 0);
        $signature = (string) $request->header('X-Legacy-Sync-Signature', '');

        abort_unless($secret !== '' && $timestamp > 0, 403);
        abort_unless(abs(now()->timestamp - $timestamp) <= (int) config('legacy_sync.max_clock_skew_seconds'), 401);
        abort_unless(hash_equals(hash_hmac('sha256', $timestamp.'.'.$raw, $secret), $signature), 401);

        $data = $request->validate([
            'sale_date' => ['required', 'date'],
            'documents' => ['required', 'array'],
            'documents.*.doc_code' => ['nullable', 'string', 'max:30'],
            'documents.*.doc_properties' => ['nullable'],
            'documents.*.document_count' => ['required', 'numeric'],
            'documents.*.amount' => ['required', 'numeric'],
            'total' => ['required', 'array'],
            'total.document_count' => ['required', 'numeric'],
            'total.amount' => ['required', 'numeric'],
        ]);

        AppSetting::set('legacy_backoffice_summary_'.date('Y-m-d', strtotime($data['sale_date'])), json_encode([
            'sale_date' => $data['sale_date'],
            'documents' => $data['documents'],
            'total' => $data['total'],
            'synced_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE));

        return response()->json(['status' => 'stored', 'document_types' => count($data['documents'])]);
    }
}
