<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\FixedAsset;
use App\Services\Accounting\DepreciationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class FixedAssetController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));

        $assets = FixedAsset::with('branch')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('asset_code', 'ilike', "%{$q}%")
                ->orWhere('name', 'ilike', "%{$q}%")
                ->orWhere('category', 'ilike', "%{$q}%")
            ))
            ->orderBy('asset_code')
            ->paginate(50)->withQueryString();

        // สรุปมูลค่ารวม
        $totals = [
            'cost' => (float) FixedAsset::where('status', '!=', 'disposed')->sum('cost'),
            'accumulated' => (float) FixedAsset::where('status', '!=', 'disposed')->sum('accumulated_depreciation'),
        ];
        $totals['book_value'] = round($totals['cost'] - $totals['accumulated'], 2);

        return view('fixed-assets.index', [
            'assets' => $assets,
            'status' => $status,
            'q' => $q,
            'totals' => $totals,
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'currentPeriod' => now()->format('Y-m'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'asset_code' => ['required', 'string', 'max:40', 'unique:fixed_assets,asset_code'],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'acquired_date' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'min:0.01'],
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'lt:cost'],
            'useful_life_years' => ['required', 'numeric', 'min:0.1', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'asset_code.unique' => 'รหัสทรัพย์สินนี้ถูกใช้แล้ว',
            'salvage_value.lt' => 'มูลค่าซากต้องน้อยกว่าราคาทุน',
            'useful_life_years.required' => 'กรุณาระบุอายุการใช้งาน (ปี)',
        ]);

        FixedAsset::create([
            'asset_code' => $data['asset_code'],
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'acquired_date' => $data['acquired_date'],
            'cost' => $data['cost'],
            'salvage_value' => $data['salvage_value'] ?? 0,
            'useful_life_months' => (int) round($data['useful_life_years'] * 12),
            'status' => 'active',
            'note' => $data['note'] ?? null,
        ]);

        return redirect()->route('fixed-assets.index')->with('success', "เพิ่มทรัพย์สิน {$data['asset_code']} แล้ว");
    }

    public function show(FixedAsset $fixedAsset): View
    {
        $fixedAsset->load(['branch', 'depreciationRecords' => fn ($q) => $q->orderBy('period_date')]);

        return view('fixed-assets.show', ['asset' => $fixedAsset]);
    }

    // คิดค่าเสื่อมทั้งงวด (เดือน) สำหรับทรัพย์สินที่ใช้งานอยู่
    public function runDepreciation(Request $request, DepreciationService $service): RedirectResponse
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $result = $service->runForPeriod(Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth());

        $period = Carbon::createFromFormat('Y-m', $data['period']);
        $msg = "คิดค่าเสื่อมงวด {$period->thaiDate()}: {$result['posted']} รายการ รวม ".number_format($result['amount'], 2)." บาท";
        if ($result['skipped'] > 0) {
            $msg .= " (ข้าม {$result['skipped']} รายการ — คิดไปแล้ว/ครบแล้ว)";
        }

        return back()->with('success', $msg);
    }

    public function dispose(FixedAsset $fixedAsset): RedirectResponse
    {
        abort_if($fixedAsset->status === 'disposed', 422, 'ทรัพย์สินนี้จำหน่ายไปแล้ว');
        $fixedAsset->update(['status' => 'disposed', 'disposed_date' => now()->toDateString()]);

        return back()->with('success', "จำหน่าย/ตัดทรัพย์สิน {$fixedAsset->asset_code} แล้ว");
    }
}
