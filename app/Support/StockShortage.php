<?php

namespace App\Support;

use App\Models\Product;

/**
 * ข้อความ "สต๊อกไม่พอ" ที่บอกได้ว่าสินค้าตัวไหน ต้องการเท่าไร มีเท่าไร ขาดเท่าไร.
 *
 * เดิมบริการสต๊อกโยน RuntimeException ข้อความกลาง ๆ เช่น "สต็อกต้นทางไม่พอสำหรับ
 * สินค้าบางรายการ" ซึ่งผู้ใช้หน้าร้านเอาไปทำอะไรต่อไม่ได้เลย.
 */
class StockShortage
{
    /** บรรทัดเดียวต่อสินค้าหนึ่งตัว */
    public static function line(int $productId, int|float|string $requested, int|float|string $available): string
    {
        $product = Product::find($productId);
        $label = $product
            ? trim($product->sku_code.' '.$product->name_th)
            : "สินค้า #{$productId}";

        $short = max(0, (float) $requested - (float) $available);

        return sprintf(
            '%s (ต้องการ %s มีอยู่ %s ขาดอีก %s)',
            $label,
            self::qty($requested),
            self::qty($available),
            self::qty($short),
        );
    }

    /** รวมหลายบรรทัดเป็นข้อความเดียวสำหรับ RuntimeException */
    public static function message(array $lines, string $prefix = 'สต๊อกไม่พอ'): string
    {
        return $prefix.': '.implode(' / ', $lines);
    }

    /** ตัดศูนย์ท้ายทศนิยมออกให้อ่านง่าย: 999.0000 -> 999, 2.5000 -> 2.5 */
    private static function qty(int|float|string $value): string
    {
        $formatted = number_format((float) $value, 4, '.', ',');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }
}
