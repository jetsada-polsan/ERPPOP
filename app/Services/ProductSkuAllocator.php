<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates a human-readable product code from the selected product category.
 *
 * Call this from inside the transaction that creates the product. Locking the
 * category row serializes allocation for that category without a global counter.
 */
class ProductSkuAllocator
{
    public function nextForCategory(int $categoryId): string
    {
        $category = ProductCategory::query()->lockForUpdate()->findOrFail($categoryId);
        $code = trim((string) $category->code);

        if ($code === '' || $code === '0') {
            throw new RuntimeException('ต้องเลือกประเภทสินค้าก่อน ระบบจึงจะรันรหัสสินค้าได้');
        }
        if ($code === 'CC') {
            throw new RuntimeException('หมวดสินค้ายกเลิกใช้สำหรับเก็บประวัติเท่านั้น ไม่สามารถเพิ่มสินค้าใหม่ได้');
        }

        [$prefix, $digits] = $this->formatForCategoryCode($code);
        $highest = 0;

        Product::withTrashed()
            ->where('sku_code', 'like', $prefix.'%')
            ->pluck('sku_code')
            ->each(function (string $sku) use ($prefix, $digits, &$highest): void {
                if (preg_match('/^'.preg_quote($prefix, '/').'([0-9]{'.$digits.'})$/', $sku, $matches)) {
                    $highest = max($highest, (int) $matches[1]);
                }
            });

        $next = $highest + 1;
        if ($next > (10 ** $digits) - 1) {
            throw new RuntimeException("รหัสประเภท {$code} เต็มแล้ว กรุณาเพิ่มประเภทย่อยก่อนเพิ่มสินค้า");
        }

        return $prefix.str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
    }

    /** @return array{0:string,1:int} */
    public function formatForCategoryCode(string $code): array
    {
        if (preg_match('/^[1-9][0-9]{2}$/', $code)) {
            return [$code, 3];
        }
        if (preg_match('/^[A-Z]{2}$/', $code)) {
            return [$code, 4];
        }

        throw new RuntimeException("รหัสประเภท {$code} ไม่รองรับการรัน SKU อัตโนมัติ");
    }
}
