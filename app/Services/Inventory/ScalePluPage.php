<?php

namespace App\Services\Inventory;

use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScalePluPage
{
    public const ISSUES = [
        'sku_plu' => 'SKU เป็นเลข PLU — ย้ายไปใต้ SKU จริง',
        'inactive' => 'SKU ปิดใช้งาน — เปิดสินค้าในแฟ้มสินค้าหรือย้าย PLU',
        'multiple' => 'SKU มีหลาย PLU — ตรวจชื่อบนป้ายและย้ายให้ถูกสินค้า',
        'duplicate' => 'PLU ซ้ำ — ตรวจเจ้าของรหัสก่อนขาย',
        'mismatch' => 'ราคาไม่ตรง — ตั้ง ERP และเครื่องชั่งให้เท่ากัน',
        'unpriced' => 'ยังไม่ตั้งราคา — กรอกราคา ERP มากกว่า 1 บาท',
        'missing' => 'ยังไม่มี PLU — ตรวจรหัสเดิมจากเครื่องชั่ง (ข้อมูล)',
        'barcode_inactive' => 'บาร์โค้ดปิดใช้งาน — ตรวจในแฟ้มสินค้า',
        'type' => 'ชนิดรหัสไม่ใช่ SCALE_PLU — ยืนยัน SKU ด้วยปุ่มย้าย PLU',
        'unchecked' => 'ยังไม่ระบุราคาเครื่องชั่ง — กรอกเพื่อตรวจเทียบ',
    ];

    public static function isPlu(string $value): bool
    {
        return preg_match('/^80[01][0-9]{3}$/D', $value) === 1;
    }

    public function rows(): Collection
    {
        $products = Product::with(['barcodes', 'baseUnit'])
            ->where(fn ($q) => $q->where('name_th', 'like', '%ชั่ง%')->orWhere('name_th', 'like', '%ซั่ง%')
                ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', 'like', '800%')->orWhere('barcode', 'like', '801%')))->get()
            ->filter(fn ($p) => str_contains($p->name_th, 'ชั่ง') || str_contains($p->name_th, 'ซั่ง') || $p->barcodes->contains(fn ($b) => self::isPlu($b->barcode)));
        $prices = ProductPrice::where('price_table_id', PriceTable::where('is_default', true)->value('id'))
            ->whereNull('unit_id')->where('is_active', true)->pluck('price', 'product_id');
        $settings = DB::table('scale_plu_settings')->pluck('scale_price', 'barcode');
        $barcodes = $products->flatMap->barcodes->filter(fn ($b) => self::isPlu($b->barcode));
        $counts = $barcodes->countBy('barcode');

        return $products->flatMap(function ($p) use ($prices, $settings, $counts) {
            $codes = $p->barcodes->filter(fn ($b) => self::isPlu($b->barcode));
            $price = (float) ($prices[$p->id] ?? $p->default_price ?? 0);

            return ($codes->isEmpty() ? collect([null]) : $codes)->map(function ($b) use ($p, $price, $settings, $counts, $codes) {
                $plu = $b?->barcode;
                $scale = $plu ? ($settings[$plu] ?? null) : null;
                $issues = [];
                if ($plu && self::isPlu($p->sku_code)) {
                    $issues[] = 'sku_plu';
                }
                if (! $p->is_active) {
                    $issues[] = 'inactive';
                }
                if ($codes->count() > 1) {
                    $issues[] = 'multiple';
                }
                if ($plu && $counts[$plu] > 1) {
                    $issues[] = 'duplicate';
                }
                if ($scale !== null && round($price * 100) !== round((float) $scale * 100)) {
                    $issues[] = 'mismatch';
                }
                if ($price <= 1) {
                    $issues[] = 'unpriced';
                }
                if (! $plu) {
                    $issues[] = 'missing';
                }
                if ($b && ! $b->is_active) {
                    $issues[] = 'barcode_inactive';
                }
                if ($b && $b->barcode_type !== 'SCALE_PLU') {
                    $issues[] = 'type';
                }
                if ($plu && $scale === null) {
                    $issues[] = 'unchecked';
                }

                return ['product' => $p, 'barcode' => $b, 'plu' => $plu, 'price' => $price, 'scale_price' => $scale,
                    'issues' => $issues, 'has_problem' => count(array_diff($issues, ['missing', 'unchecked'])) > 0];
            });
        })->sortBy(fn ($r) => $r['plu'] ?? '999999')->values();
    }
}
