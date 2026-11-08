<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ProductBarcode;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockCountController extends Controller
{
    public function index(): View
    {
        $counts = StockCount::with(['branch', 'warehouseLocation', 'postedDocument'])
            ->withCount('items')
            ->latest('id')
            ->paginate(30);
        $branches = Branch::orderBy('code')->get(['id', 'code', 'name_th']);
        $locations = WarehouseLocation::orderBy('code')->get(['id', 'code', 'name']);
        $staleCounts = StockCount::stale()->with('branch')->orderBy('created_at')->get();

        return view('stock-counts.index', compact('counts', 'branches', 'locations', 'staleCounts'));
    }

    public function store(Request $request, StockCountService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'count_mode' => ['required', 'in:partial,full_zero_missing'],
            'product_scope' => ['required', 'in:active,inactive,all'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // ไม่ระบุตำแหน่งเก็บ = ใช้คลังเริ่มต้นของสาขา ซึ่งต้องมีอยู่จริงก่อน
        if (! ($data['warehouse_location_id'] ?? null)) {
            if ($redirect = $this->guardBranchWarehouse($data['branch_id'])) {
                return $redirect;
            }
        }

        try {
            $count = $service->create($data);
        } catch (RuntimeException $e) {
            return back()->withErrors(['branch_id' => $e->getMessage()]);
        }

        return redirect()->route('stock-counts.show', $count)
            ->with('success', "เปิดใบตรวจนับ {$count->doc_number} แล้ว ({$count->items()->count()} รายการ)");
    }

    public function show(StockCount $stockCount): View
    {
        $stockCount->load(['branch', 'warehouseLocation', 'postedDocument']);

        // Whole sheet as one JSON payload: the entry grid filters/saves client-side
        $items = StockCountItem::where('stock_count_id', $stockCount->id)
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->leftJoin('product_units', 'product_units.id', '=', 'products.base_unit_id')
            ->orderBy('products.sku_code')
            ->get([
                'stock_count_items.id',
                'stock_count_items.counted_qty',
                'stock_count_items.system_qty',
                'products.sku_code',
                'products.name_th',
                'products.default_price',
                'product_units.name as unit_name',
                'products.id as product_id',
            ]);

        $barcodes = ProductBarcode::whereIn('product_id', $items->pluck('product_id'))
            ->where('is_active', true)->get(['product_id', 'barcode', 'unit_factor'])
            ->groupBy('product_id');

        $itemsJson = $items->map(fn ($i) => [
            'id' => $i->id,
            'sku' => $i->sku_code,
            'name' => $i->name_th,
            'unit' => $i->unit_name ?? '-',
            'price' => (float) $i->default_price,
            'barcodes' => ($barcodes[$i->product_id] ?? collect())->pluck('barcode')->values()->all(),
            'pack' => (float) (($barcodes[$i->product_id] ?? collect())->max('unit_factor') ?: 1),
            'search' => mb_strtolower($i->sku_code.' '.$i->name_th.' '.($barcodes[$i->product_id] ?? collect())->pluck('barcode')->implode(' ')),
            'system' => (float) $i->system_qty,
            'counted' => $i->counted_qty !== null ? (float) $i->counted_qty : null,
        ])->values();

        return view('stock-counts.show', compact('stockCount', 'itemsJson'));
    }

    // Bulk save typed counts: { items: [{id, counted}] }
    public function saveItems(Request $request, StockCount $stockCount): JsonResponse
    {
        abort_unless($stockCount->isEditable(), 422, 'ใบนี้ปรับปรุงแล้ว แก้ไขไม่ได้');

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.counted' => ['nullable', 'numeric', 'min:0'],
        ]);

        $updated = 0;
        foreach ($data['items'] as $row) {
            $updated += StockCountItem::where('stock_count_id', $stockCount->id)
                ->where('id', $row['id'])
                ->update(['counted_qty' => $row['counted'] ?? null]);
        }

        return response()->json(['success' => true, 'updated' => $updated]);
    }

    public function submit(Request $request, StockCount $stockCount): RedirectResponse
    {
        abort_unless($stockCount->isEditable(), 422, 'ใบนี้ส่งตรวจแล้ว');
        abort_if($stockCount->items()->whereNotNull('counted_qty')->doesntExist(), 422, 'ยังไม่มีรายการนับจริง');
        if ($stockCount->count_mode === 'full_zero_missing') {
            abort_if($stockCount->items()->whereNull('counted_qty')->exists(), 422, 'โหมดนับเต็มต้องกรอกจำนวนจริงให้ครบทุกสินค้า รวมรายการที่นับได้ 0');
        }
        $stockCount->update(['status' => 'review', 'submitted_by' => $request->user()?->id, 'submitted_at' => now()]);

        return back()->with('success', 'ส่งใบตรวจนับให้หัวหน้าตรวจสอบแล้ว ยังไม่กระทบสต็อกจริง');
    }

    // CSV export (UTF-8 BOM so Excel opens Thai correctly) for offline counting
    public function export(StockCount $stockCount): StreamedResponse
    {
        $stockCount->load(['branch', 'warehouseLocation']);
        $items = StockCountItem::where('stock_count_id', $stockCount->id)
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->leftJoin('product_units', 'product_units.id', '=', 'products.base_unit_id')
            ->orderBy('products.sku_code')
            ->get([
                'stock_count_items.id as item_reference',
                'products.sku_code', 'products.name_th',
                'product_units.name as unit_name',
                'stock_count_items.system_qty', 'stock_count_items.counted_qty',
            ]);

        return response()->streamDownload(function () use ($stockCount, $items) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ใบตรวจนับ', $stockCount->doc_number, 'สาขา', $stockCount->branch->name_th, 'ตำแหน่งเก็บ', $stockCount->warehouseLocation->name]);
            fputcsv($out, ['รหัสสินค้า', 'ชื่อสินค้า', 'หน่วย', 'จำนวนตามระบบ', 'จำนวนนับจริง', 'หมายเหตุ', 'รหัสอ้างอิง']);
            foreach ($items as $i) {
                fputcsv($out, [
                    $i->sku_code, $i->name_th, $i->unit_name ?? '-',
                    (float) $i->system_qty,
                    $i->counted_qty !== null ? (float) $i->counted_qty : '',
                    '', $i->item_reference,
                ]);
            }
            fclose($out);
        }, $stockCount->doc_number.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Import the filled CSV back and reject incomplete or ambiguous files atomically.
    public function import(Request $request, StockCount $stockCount): RedirectResponse
    {
        abort_unless($stockCount->isEditable(), 422, 'ใบนี้ปรับปรุงแล้ว แก้ไขไม่ได้');

        $request->validate(
            ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']],
            ['file.mimes' => 'รองรับไฟล์ .csv เท่านั้น (ใน Excel ใช้ Save As > CSV UTF-8)'],
        );

        $items = StockCountItem::where('stock_count_id', $stockCount->id)
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->get(['stock_count_items.id', 'products.sku_code']);
        $itemById = $items->keyBy(fn ($item) => (string) $item->id);
        $itemIdBySku = $items->pluck('id', 'sku_code');
        $seen = [];
        $updates = [];
        $errors = [];
        $fh = fopen($request->file('file')->getRealPath(), 'r');
        if ($fh === false) {
            throw ValidationException::withMessages(['file' => 'อ่านไฟล์ CSV ไม่สำเร็จ']);
        }

        fgetcsv($fh); // metadata row
        $header = fgetcsv($fh);
        $expectedHeader = ['รหัสสินค้า', 'ชื่อสินค้า', 'หน่วย', 'จำนวนตามระบบ', 'จำนวนนับจริง', 'หมายเหตุ'];
        if (! is_array($header) || array_slice($header, 0, 6) !== $expectedHeader) {
            fclose($fh);
            throw ValidationException::withMessages(['file' => 'รูปแบบไฟล์ไม่ตรงกับไฟล์ Export ใบตรวจนับนี้']);
        }

        $rowNo = 2;
        while (($row = fgetcsv($fh)) !== false) {
            $rowNo++;
            $sku = trim((string) ($row[0] ?? ''));
            $sku = preg_replace('/^\xEF\xBB\xBF/', '', $sku);
            $counted = trim((string) ($row[4] ?? ''));
            $itemReference = trim((string) ($row[6] ?? ''));

            if ($sku === '' && $counted === '' && $itemReference === '') {
                continue;
            }
            $item = $itemReference !== '' ? $itemById->get($itemReference) : null;
            $itemId = $item?->id ?? ($itemIdBySku[$sku] ?? null);
            if (! $itemId || ($itemReference !== '' && $item && $sku !== '' && $item->sku_code !== $sku)) {
                $errors[] = "แถว {$rowNo}: ไม่พบรหัสสินค้า/รหัสอ้างอิงในใบนี้";
                continue;
            }
            if (isset($seen[$itemId])) {
                $errors[] = "แถว {$rowNo}: สินค้าซ้ำ ({$sku})";
                continue;
            }
            $seen[$itemId] = true;

            if ($counted === '') {
                if ($stockCount->count_mode === 'full_zero_missing') {
                    $errors[] = "แถว {$rowNo}: โหมดนับเต็มต้องระบุจำนวนจริง (ใส่ 0 หากไม่มีสินค้า)";
                }
                continue;
            }

            $normalized = str_replace(',', '', $counted);
            if (! is_numeric($normalized) || (float) $normalized < 0) {
                $errors[] = "แถว {$rowNo}: จำนวนต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป";
                continue;
            }
            $updates[$itemId] = (float) $normalized;
        }
        fclose($fh);

        $missing = $items->filter(fn ($item) => ! isset($seen[$item->id]));
        if ($missing->isNotEmpty()) {
            $errors[] = 'ไฟล์มีรายการไม่ครบ '.number_format($missing->count()).' รายการ';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => implode(' · ', array_slice($errors, 0, 8))]);
        }

        DB::transaction(function () use ($updates, $stockCount): void {
            $locked = StockCount::whereKey($stockCount->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->isEditable(), 422, 'ใบนี้ปรับปรุงแล้ว แก้ไขไม่ได้');
            foreach ($updates as $id => $counted) {
                StockCountItem::where('stock_count_id', $locked->id)->whereKey($id)
                    ->update(['counted_qty' => $counted]);
            }
        });

        $blankCount = $items->count() - count($updates);
        $msg = 'Import สำเร็จ อัปเดตยอดนับ '.number_format(count($updates)).' รายการ';
        if ($blankCount > 0) {
            $msg .= ' · เว้นว่าง '.number_format($blankCount).' รายการ (โหมดนับบางส่วน)';
        }

        return redirect()->route('stock-counts.show', $stockCount)->with('success', $msg);
    }

    // Post differences as a stock adjustment document
    public function post(Request $request, StockCount $stockCount, StockCountService $service): RedirectResponse
    {
        $allowed = $request->user()?->roles()->whereIn('code', ['GM', 'BRANCH_MGR', 'IT_MGR'])->exists();
        abort_unless($allowed, 403, 'เฉพาะ Manager/Admin เท่านั้นที่ยืนยันปรับสต็อกได้');
        abort_unless($stockCount->status === 'review', 422, 'ต้องส่งตรวจสอบก่อนยืนยันปรับสต็อก');
        try {
            $document = $service->post($stockCount, $request->input('remark'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['post' => $e->getMessage()]);
        }

        if ($document === null) {
            return redirect()->route('stock-counts.show', $stockCount)
                ->with('success', 'ปิดใบตรวจนับแล้ว — ยอดนับตรงกับระบบทั้งหมด ไม่มีรายการต้องปรับ');
        }

        return redirect()->route('stock-adjustments.show', $document->id)
            ->with('success', "สร้างใบปรับสต๊อก {$document->doc_number} แล้ว ต้องให้ผู้อนุมัติอีกคนตรวจและอนุมัติก่อนกระทบสต๊อกจริง");
    }
}
