<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LegacyBackofficeSalesController extends Controller
{
    public function index(Request $request): View
    {
        $date = $request->filled('date') ? Carbon::parse($request->input('date'))->toDateString() : now()->toDateString();
        $data = json_decode((string) AppSetting::get('legacy_backoffice_summary_'.$date, '{}'), true) ?: [];
        $rows = collect($data['documents'] ?? []);
        $ds = $rows->filter(fn ($row) => in_array(strtoupper((string) ($row['doc_code'] ?? '')), ['DS', 'DSN'], true));
        $reserved = $rows->filter(fn ($row) => (string) ($row['doc_properties'] ?? '') === '207');

        return view('legacy-backoffice-sales.index', [
            'date' => $date,
            'syncedAt' => $data['synced_at'] ?? null,
            'rows' => $rows,
            'creditCount' => $ds->sum('document_count'),
            'creditAmount' => $ds->sum('amount'),
            'reservationCount' => $reserved->sum('document_count'),
            'reservationAmount' => $reserved->sum('amount'),
            'uniqueCount' => $rows->sum('document_count'),
            'uniqueAmount' => $rows->sum('amount'),
        ]);
    }
}
