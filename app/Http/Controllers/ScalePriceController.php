<?php

namespace App\Http\Controllers;

use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ราคาสินค้าเครื่องชั่ง — หน้าเดียวจบสำหรับสินค้าขายชั่งน้ำหนักทั้งหมด
 *
 * กติกา PLU เครื่องชั่ง (ช่วงเลข 8): รหัส 800xxx/801xxx ผูกกับสินค้าเป็น
 * "บาร์โค้ด" (ไม่แตะ sku เดิม — ETL/BPlus ไม่พัง) เครื่องชั่งพิมพ์ป้าย
 * PLU(6)+ราคารวม(6,÷100)+check(1) แล้ว POS คำนวณน้ำหนักย้อนกลับจากราคา/กก.
 * → ราคาที่ตั้งหน้านี้ (ตารางหลัก) จึง "ต้องตรงกับราคาที่ตั้งในเครื่องชั่ง" เสมอ
 *
 * สินค้าโผล่หน้านี้เมื่อ: มี PLU 80xxxx ลงทะเบียนไว้ หรือชื่อมีคำว่า ชั่ง/ซั่ง
 */
class ScalePriceController extends Controller
{
    private const PLU_REGEX = '^80[01][0-9]{3}$';

    public function index(): View
    {
        $defaultTable = PriceTable::where('is_default', true)->first();
        $products = $this->scaleProducts();

        // ราคาปัจจุบันจากตารางหลัก (แถวราคาฐาน unit_id = null)
        $prices = $defaultTable
            ? ProductPrice::where('price_table_id', $defaultTable->id)
                ->whereIn('product_id', $products->pluck('id'))
                ->whereNull('unit_id')
                ->where('is_active', true)
                ->pluck('price', 'product_id')
            : collect();

        // ราคา override รายสาขา (ตารางอื่นที่ไม่ใช่ตารางหลัก) — เตือนให้รู้ว่าตั้งที่นี่แล้วสาขานั้นยังไม่เปลี่ยน
        $overrides = ProductPrice::query()
            ->join('price_tables', 'price_tables.id', '=', 'product_prices.price_table_id')
            ->whereIn('product_prices.product_id', $products->pluck('id'))
            ->whereNull('product_prices.unit_id')
            ->where('product_prices.is_active', true)
            ->where('price_tables.is_default', false)
            ->get(['product_prices.product_id', 'price_tables.name', 'product_prices.price'])
            ->groupBy('product_id');

        // จำนวนที่ยังไม่ตั้งราคา (ราคา <= 1 = placeholder ค้างจาก BPlus)
        $notPriced = $products->filter(function ($p) use ($prices) {
            $current = (float) ($prices[$p->id] ?? $p->default_price ?? 0);

            return $current <= 1;
        })->count();

        return view('scale-prices.index', [
            'products' => $products,
            'prices' => $prices,
            'overrides' => $overrides,
            'defaultTable' => $defaultTable,
            'notPriced' => $notPriced,
        ]);
    }

    /** ดึงสินค้าใด ๆ เข้ารายการชั่ง โดยผูกรหัส PLU 800xxx ถัดไปให้อัตโนมัติ */
    public function attachPlu(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);
        $product = Product::findOrFail($data['product_id']);

        // มี PLU เครื่องชั่งอยู่แล้วหรือยัง
        $hasPlu = $product->barcodes()
            ->where(fn ($w) => $w->where('barcode', 'like', '800%')->orWhere('barcode', 'like', '801%'))
            ->get()
            ->contains(fn ($b) => preg_match('/^80[01][0-9]{3}$/', (string) $b->barcode) === 1);
        if ($hasPlu) {
            return redirect()->route('scale-prices.index')
                ->with('success', "{$product->name_th} มี PLU เครื่องชั่งอยู่แล้ว");
        }

        // เลข 800xxx ถัดไป (max+1) + กันซ้ำ
        $max = ProductBarcode::where('barcode', 'like', '800%')
            ->pluck('barcode')
            ->filter(fn ($b) => preg_match('/^800[0-9]{3}$/', (string) $b) === 1)
            ->map(fn ($b) => (int) $b)
            ->max();
        $next = str_pad((string) (($max ?: 800000) + 1), 6, '0', STR_PAD_LEFT);
        while (ProductBarcode::where('barcode', $next)->exists()) {
            $next = str_pad((string) ((int) $next + 1), 6, '0', STR_PAD_LEFT);
        }

        $product->barcodes()->create([
            'barcode' => $next,
            'unit_id' => $product->base_unit_id,
            'unit_factor' => 1,
            'is_active' => true,
        ]);

        return redirect()->route('scale-prices.index')
            ->with('success', "เพิ่ม {$product->name_th} เข้ารายการชั่ง PLU {$next} แล้ว — อย่าลืมตั้งราคา/กก.");
    }

    /** บันทึกราคา/กก. ทั้งหน้า ลงตารางหลัก (อัปเดตเฉพาะตัวที่เปลี่ยนจริง) */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        $tableId = PriceTable::where('is_default', true)->value('id');
        if (! $tableId) {
            return back()->withErrors(['prices' => 'ยังไม่มีตารางราคาหลัก (default) ในระบบ']);
        }

        $validIds = $this->scaleProducts()->pluck('id')->all();
        $changed = 0;

        foreach ($data['prices'] as $productId => $price) {
            if ($price === null || $price === '' || ! in_array((int) $productId, $validIds, true)) {
                continue;
            }
            $price = round((float) $price, 2);

            $row = ProductPrice::firstOrNew([
                'product_id' => (int) $productId,
                'price_table_id' => $tableId,
                'unit_id' => null,
            ]);
            if (! $row->exists || (float) $row->price !== $price || ! $row->is_active) {
                $row->price = $price;
                $row->is_active = true;
                $row->save();
                $changed++;
            }
        }

        return redirect()->route('scale-prices.index')
            ->with('success', $changed > 0 ? "บันทึกราคาแล้ว {$changed} รายการ — อย่าลืมตั้งราคาเดียวกันที่เครื่องชั่ง" : 'ไม่มีรายการที่เปลี่ยนแปลง');
    }

    /** CSV สำหรับตั้งเครื่องชั่ง: PLU, ชื่อ, ราคา/กก. ปัจจุบัน */
    public function export(): Response
    {
        $defaultTableId = PriceTable::where('is_default', true)->value('id');
        $products = $this->scaleProducts();
        $prices = $defaultTableId
            ? ProductPrice::where('price_table_id', $defaultTableId)
                ->whereIn('product_id', $products->pluck('id'))
                ->whereNull('unit_id')->where('is_active', true)
                ->pluck('price', 'product_id')
            : collect();

        $csv = "\xEF\xBB\xBF" . "PLU ตั้งเครื่องชั่ง,ชื่อสินค้า,ราคา/กก.,รหัสสินค้าในระบบ\n";
        foreach ($products as $p) {
            $price = (float) ($prices[$p->id] ?? $p->default_price ?? 0);
            $row = [$p->scale_plu ?? '-', $p->name_th, number_format($price, 2, '.', ''), $p->sku_code];
            $csv .= '"' . implode('","', array_map(fn ($v) => str_replace('"', '""', (string) $v), $row)) . '"' . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="scale-plu-' . now()->format('Ymd') . '.csv"',
        ]);
    }

    /** สินค้าเครื่องชั่งทั้งหมด พร้อม attribute scale_plu (เรียงตาม PLU ก่อน แล้วค่อยตัวที่ยังไม่มี PLU) */
    private function scaleProducts()
    {
        $products = Product::where('is_active', true)
            ->where(fn ($w) => $w
                ->where('name_th', 'like', '%ชั่ง%')
                ->orWhere('name_th', 'like', '%ซั่ง%')
                ->orWhereHas('barcodes', fn ($b) => $this->possibleScaleBarcodes($b->where('is_active', true))))
            ->with(['barcodes' => fn ($q) => $this->possibleScaleBarcodes($q->where('is_active', true))])
            ->get(['id', 'sku_code', 'name_th', 'default_price']);

        return $products
            ->map(function ($p) {
                $p->scale_plu = $p->barcodes->first(fn ($b) => $this->isScalePlu($b->barcode))?->barcode
                    ?? ($this->isScalePlu($p->sku_code) ? $p->sku_code : null);

                return $p;
            })
            ->filter(fn ($p) => $p->scale_plu || $this->productHasScaleName($p->name_th))
            ->sortBy([['scale_plu', 'asc'], ['sku_code', 'asc']])
            ->values();
    }

    private function possibleScaleBarcodes($query)
    {
        return $query->where(fn ($w) => $w
            ->where('barcode', 'like', '800%')
            ->orWhere('barcode', 'like', '801%'));
    }

    private function isScalePlu(?string $value): bool
    {
        return is_string($value) && preg_match('/' . self::PLU_REGEX . '/', $value) === 1;
    }

    private function productHasScaleName(?string $name): bool
    {
        return is_string($name) && preg_match('/\x{0E0A}\x{0E31}\x{0E48}\x{0E07}|\x{0E0B}\x{0E31}\x{0E48}\x{0E07}/u', $name) === 1;
    }
}
