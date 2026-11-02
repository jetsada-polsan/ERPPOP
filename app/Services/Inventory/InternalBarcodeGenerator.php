<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Support\BarcodePolicy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * บาร์โค้ดภายใน 13 หลักสำหรับใช้ในบริษัท ไม่ใช่ EAN ที่ได้จากผู้ผลิต
 *
 * SKU ที่เป็นตัวเลขไม่เกิน 12 หลัก: เติม 0 ข้างหน้าให้ครบ 12 แล้วต่อ check digit
 * เช่น 102399 -> 0000001023992 ถ้าเลขนั้นถูกใช้ไปแล้ว หรือ SKU มีตัวอักษร/ยาวเกิน
 * ให้ใช้เลขรันช่วง 299 แทน
 */
class InternalBarcodeGenerator
{
    public const FROM_SKU = 'generated_from_sku';
    public const SEQUENCE = 'generated_sequence';

    public function __construct(private BarcodePolicy $policy) {}

    /** เลขที่จะได้จาก SKU หรือ null ถ้า SKU ใช้สร้างไม่ได้ */
    public function fromSku(string $sku): ?string
    {
        if ($sku === '' || ! ctype_digit($sku) || strlen($sku) > 12) {
            return null;
        }
        $body = str_pad($sku, 12, '0', STR_PAD_LEFT);

        return $body.$this->policy->checkDigit($body);
    }

    /** สินค้านี้มีบาร์โค้ดที่ระบบสร้างให้อยู่แล้วหรือยัง */
    public function hasGenerated(Product $product): bool
    {
        return $product->barcodes()->whereIn('source', [self::FROM_SKU, self::SEQUENCE])->exists();
    }

    /** สร้างบาร์โค้ดภายในให้สินค้า ไม่แตะบาร์โค้ดเดิมของสินค้า */
    public function generate(Product $product, ?int $unitId = null, float $unitFactor = 1): ProductBarcode
    {
        return DB::transaction(function () use ($product, $unitId, $unitFactor): ProductBarcode {
            if (DB::getDriverName() === 'pgsql') {
                // กันเลขรันชนกันกรณีหลายคนกดพร้อมกัน
                DB::statement('SELECT pg_advisory_xact_lock(299013)');
            }

            $barcode = $this->fromSku((string) $product->sku_code);
            $source = self::FROM_SKU;
            if ($barcode === null || ProductBarcode::where('barcode', $barcode)->exists()) {
                $barcode = $this->nextSequence();
                $source = self::SEQUENCE;
            }

            return $product->barcodes()->create([
                'barcode' => $barcode, 'barcode_type' => BarcodePolicy::INTERNAL_13, 'source' => $source,
                'unit_id' => $unitId ?? $product->base_unit_id, 'unit_factor' => $unitFactor, 'is_active' => true,
            ]);
        });
    }

    /** เลขรันช่วง 299 + 9 หลัก + check digit ข้ามเลขที่มีอยู่แล้วทุกครั้ง */
    public function nextSequence(): string
    {
        $last = ProductBarcode::query()
            ->where('barcode', 'like', '299%')
            ->whereRaw('LENGTH(barcode) = 13')
            ->orderByDesc('barcode')
            ->value('barcode');

        $sequence = $last ? ((int) substr((string) $last, 3, 9)) + 1 : 1;
        while ($sequence <= 999999999) {
            $body = '299'.str_pad((string) $sequence, 9, '0', STR_PAD_LEFT);
            $barcode = $body.$this->policy->checkDigit($body);
            if (! ProductBarcode::where('barcode', $barcode)->exists()) {
                return $barcode;
            }
            $sequence++;
        }

        throw new RuntimeException('เลขบาร์โค้ดภายในช่วง 299 เต็มแล้ว');
    }
}
