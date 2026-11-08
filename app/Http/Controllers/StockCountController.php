<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockCountService;
use App\Support\DecimalMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $categories = ProductCategory::query()->orderBy('name_th')->get(['id', 'name_th']);

        return view('stock-counts.show', compact('stockCount', 'itemsJson', 'categories'));
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
        $stockCount->update(['status' => 'review', 'submitted_by' => $request->user()?->id, 'submitted_at' => now()]);

        return back()->with('success', 'ส่งใบตรวจนับให้หัวหน้าตรวจสอบแล้ว ยังไม่กระทบสต็อกจริง');
    }

    // CSV export (UTF-8 BOM so Excel opens Thai correctly) for offline counting
    public function export(Request $request, StockCount $stockCount, StockCountService $service): StreamedResponse
    {
        $filters = $request->validate([
            'active' => ['nullable', 'in:all,1,0'],
            'category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
        ]);
        $service->addMissingCatalogItems($stockCount);
        $stockCount->load(['branch', 'warehouseLocation']);
        $items = StockCountItem::where('stock_count_id', $stockCount->id)
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->leftJoin('product_units', 'product_units.id', '=', 'products.base_unit_id')
            ->when(($filters['active'] ?? 'all') !== 'all', fn ($query) => $query->where('products.is_active', (bool) $filters['active']))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('products.product_category_id', $filters['category_id']))
            ->orderBy('products.sku_code')
            ->get([
                'products.id as product_id', 'products.sku_code', 'products.name_th',
                'product_units.name as unit_name', 'stock_count_items.system_qty', 'stock_count_items.counted_qty',
            ]);
        $unitOptions = DB::table('product_barcodes as pb')
            ->leftJoin('product_units as pu', 'pu.id', '=', 'pb.unit_id')
            ->whereIn('pb.product_id', $items->pluck('product_id')->unique())
            ->where('pb.is_active', true)->where('pb.unit_factor', '>', 1)
            ->orderByDesc('pb.unit_factor')->orderBy('pb.id')
            ->get(['pb.product_id', 'pb.unit_factor', 'pu.name as large_unit_name'])->groupBy('product_id');

        return response()->streamDownload(function () use ($stockCount, $items, $unitOptions) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ใบตรวจนับ', $stockCount->doc_number, 'สาขา', $stockCount->branch->name_th, 'ตำแหน่งเก็บ', $stockCount->warehouseLocation->name]);
            fputcsv($out, ['ลำดับ', 'รหัสสินค้า', 'ชื่อสินค้า', 'หน่วยหลัก', 'ตัวคูณเป็นหน่วยย่อย', 'จำนวนตามระบบ (หน่วยหลัก)', 'หน่วยย่อย', 'จำนวนตามระบบ (หน่วยย่อย)', 'นับจริง (หน่วยหลัก)', 'นับจริง (หน่วยย่อย)', 'หมายเหตุ']);
            foreach ($items as $index => $item) {
                $option = $unitOptions->get($item->product_id)?->first();
                $factor = (float) ($option->unit_factor ?? 1);
                $systemQty = (float) $item->system_qty;
                $mainQty = $option ? ($systemQty >= 0 ? floor($systemQty / $factor) : ceil($systemQty / $factor)) : 0;
                $subQty = $option ? $systemQty - ($mainQty * $factor) : $systemQty;
                $mainUnit = trim((string) preg_replace('/\s*[x*×]\s*\d+(?:\.\d+)?$/u', '', $option?->large_unit_name ?: '-'));
                $countedQty = $item->counted_qty !== null ? (float) $item->counted_qty : null;
                $countedMain = $countedQty !== null && $option ? ($countedQty >= 0 ? floor($countedQty / $factor) : ceil($countedQty / $factor)) : null;
                $countedSub = $countedQty === null ? '' : $this->csvQuantity($option ? $countedQty - ($countedMain * $factor) : $countedQty);
                fputcsv($out, [
                    $index + 1, $item->sku_code, $item->name_th, $mainUnit, $option ? $option->unit_factor : 1,
                    $option ? $this->csvQuantity($mainQty) : '', $item->unit_name ?? '-', $this->csvQuantity($subQty),
                    $countedMain !== null ? $this->csvQuantity($countedMain) : '', $countedSub, '',
                ]);
            }
            fclose($out);
        }, $stockCount->doc_number.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Import the filled CSV back: matches by รหัสสินค้า, fills counted qty
    public function import(Request $request, StockCount $stockCount, StockCountService $service): RedirectResponse
    {
        abort_unless($stockCount->isEditable(), 422, 'ใบนี้ปรับปรุงแล้ว แก้ไขไม่ได้');

        $request->validate(
            ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']],
            ['file.mimes' => 'รองรับไฟล์ .csv เท่านั้น (ใน Excel ใช้ Save As > CSV UTF-8)'],
        );

        $stockCount->loadMissing(['branch', 'warehouseLocation']);
        $metaHandle = fopen($request->file('file')->getRealPath(), 'r');
        $metadata = fgetcsv($metaHandle) ?: [];
        fclose($metaHandle);
        $metadata = array_map(fn ($value) => trim((string) $value), $metadata);
        $metadata[0] = preg_replace('/^\xEF\xBB\xBF/', '', $metadata[0] ?? '');
        $branchLabel = array_search('สาขา', $metadata, true);
        $locationLabel = array_search('ตำแหน่งเก็บ', $metadata, true);
        if ($branchLabel !== false || $locationLabel !== false) {
            $sameDocument = ($metadata[0] ?? null) === 'ใบตรวจนับ' && ($metadata[1] ?? null) === $stockCount->doc_number;
            $sameBranch = $branchLabel !== false && ($metadata[$branchLabel + 1] ?? null) === $stockCount->branch->name_th;
            $sameLocation = $locationLabel !== false && ($metadata[$locationLabel + 1] ?? null) === $stockCount->warehouseLocation->name;
            if (! $sameDocument || ! $sameBranch || ! $sameLocation) {
                return back()->withErrors(['file' => 'ไฟล์นี้เป็นของใบตรวจนับ/สาขา/ตำแหน่งเก็บอื่น กรุณาใช้ไฟล์ที่ส่งออกจากใบนี้']);
            }
        }

        $service->addMissingCatalogItems($stockCount);
        $itemRecords = StockCountItem::where('stock_count_id', $stockCount->id)
            ->join('products', 'products.id', '=', 'stock_count_items.product_id')
            ->get(['stock_count_items.id as item_id', 'products.id as product_id', 'products.sku_code'])
            ->keyBy('sku_code');
        $itemIdBySku = $itemRecords->map(fn ($item) => $item->item_id);
        $packFactorByProduct = DB::table('product_barcodes')->whereIn('product_id', $itemRecords->pluck('product_id')->unique())
            ->where('is_active', true)->where('unit_factor', '>', 1)->orderByDesc('unit_factor')->orderBy('id')
            ->get(['product_id', 'unit_factor'])->groupBy('product_id')
            ->map(fn ($options) => (string) $options->first()->unit_factor);

        $countedBySku = [];
        $skipped = [];
        $invalid = [];
        $header = [];
        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $rowNo = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $rowNo++;
            $sku = trim((string) ($row[0] ?? ''));
            $sku = preg_replace('/^\xEF\xBB\xBF/', '', $sku);
            if ($rowNo === 2) {
                $header = array_flip(array_map(fn ($value) => trim((string) $value), $row));
            }
            $skuIndex = $header['รหัสสินค้า'] ?? 0;
            $sku = trim((string) ($row[$skuIndex] ?? $sku));
            $sku = preg_replace('/^\xEF\xBB\xBF/', '', $sku);
            $hasSplitCount = isset($header['นับจริง (หน่วยหลัก)'], $header['นับจริง (หน่วยย่อย)'], $header['ตัวคูณเป็นหน่วยย่อย']);
            $counted = trim((string) ($row[$header['จำนวนนับจริง'] ?? 4] ?? ''));

            // Skip header/meta rows and rows without a counted value
            if ($sku === '' || ! isset($itemIdBySku[$sku])) {
                if ($sku !== '' && $rowNo > 2) {
                    $skipped[] = $sku;
                }

                continue;
            }
            if ($hasSplitCount) {
                $mainCount = str_replace(',', '', trim((string) ($row[$header['นับจริง (หน่วยหลัก)']] ?? '')));
                $subCount = str_replace(',', '', trim((string) ($row[$header['นับจริง (หน่วยย่อย)']] ?? '')));
                if ($mainCount === '' && $subCount === '') {
                    continue;
                }
                $factor = str_replace(',', '', trim((string) ($row[$header['ตัวคูณเป็นหน่วยย่อย']] ?? '')));
                $item = $itemRecords->get($sku);
                $currentFactor = $packFactorByProduct->get($item->product_id ?? 0, '1');
                if (($mainCount !== '' && (! is_numeric($mainCount) || (float) $mainCount < 0))
                    || ($subCount !== '' && (! is_numeric($subCount) || (float) $subCount < 0))
                    || ! is_numeric($factor) || (float) $factor <= 0 || DecimalMath::compare($factor, $currentFactor) !== 0) {
                    $invalid[] = $sku;

                    continue;
                }
                $countedBySku[$sku] = (float) DecimalMath::add(
                    DecimalMath::multiply($mainCount === '' ? '0' : $mainCount, $currentFactor, DecimalMath::QUANTITY_SCALE),
                    $subCount === '' ? '0' : $subCount,
                    DecimalMath::QUANTITY_SCALE,
                );

                continue;
            }
            $counted = str_replace(',', '', $counted);
            if ($counted === '') {
                continue;
            }
            if (! is_numeric($counted) || (float) $counted < 0) {
                $invalid[] = $sku;

                continue;
            }
            $countedBySku[$sku] = (float) $counted;
        }
        fclose($fh);

        if ($invalid !== []) {
            return back()->withErrors(['file' => 'จำนวนต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป ตรวจรหัสสินค้า: '.implode(', ', array_slice($invalid, 0, 5))]);
        }
        if ($countedBySku === []) {
            return back()->withErrors(['file' => 'ไม่พบรหัสสินค้าในใบนี้ที่มีจำนวนนับจริง กรุณาตรวจไฟล์ก่อนนำเข้า']);
        }
        $updated = DB::transaction(function () use ($stockCount, $countedBySku, $itemIdBySku) {
            $lockedCount = StockCount::query()->whereKey($stockCount->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedCount->isEditable(), 422, 'ใบนี้ปรับปรุงแล้ว แก้ไขไม่ได้');
            StockCountItem::where('stock_count_id', $lockedCount->id)->update(['counted_qty' => null]);
            $lockedCount->update(['count_mode' => 'partial']);
            foreach ($countedBySku as $sku => $counted) {
                StockCountItem::where('stock_count_id', $lockedCount->id)->whereKey($itemIdBySku[$sku])->update(['counted_qty' => $counted]);
            }

            return count($countedBySku);
        });

        $msg = "Import สำเร็จ {$updated} รายการ — ใช้เฉพาะรหัสที่มีจำนวนในไฟล์ สินค้าอื่นไม่ถูกปรับ";
        if ($skipped !== []) {
            $msg .= ' (ไม่พบรหัสในใบนี้ '.count($skipped).' รายการ เช่น '.implode(', ', array_slice($skipped, 0, 5)).')';
        }

        return redirect()->route('stock-counts.show', $stockCount)->with('success', $msg);
    }

    private function csvQuantity(float|int $value): string
    {
        return rtrim(rtrim(number_format((float) $value, DecimalMath::QUANTITY_SCALE, '.', ''), '0'), '.');
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
