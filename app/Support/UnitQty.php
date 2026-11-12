<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use Illuminate\Support\Collection;

class UnitQty
{
    /**
     * จัดรูปแบบแสดงสองหน่วย (เช่น "36 ถุง (2 ลัง)" หรือ "40 ถุง (2 ลัง 4 ถุง)")
     *
     * @param  Product|null  $product  โมเดลสินค้า (ควร eager load 'baseUnit' และ 'barcodes.unit')
     * @param  float|int|string|null  $baseQty  จำนวนในหน่วยหลัก
     * @param  int|null  $unitId  หน่วยที่เลือกของบรรทัด (ถ้ามี ให้ใช้หน่วยนี้ก่อน)
     */
    public static function format(?Product $product, float|int|string|null $baseQty, ?int $unitId = null): string
    {
        if ($baseQty === null || $baseQty === '') {
            return '-';
        }

        $numericQty = (float) $baseQty;

        if (! $product) {
            return (string) $baseQty;
        }

        $baseUnit = $product->baseUnit;
        $baseUnitName = ($baseUnit && ! $baseUnit->isCorrupted()) ? $baseUnit->cleanName() : '';

        // ทศนิยม (สินค้าชั่ง) แสดงเฉพาะหน่วยหลัก
        if (abs($numericQty - round($numericQty)) > 0.00001) {
            $formattedQty = rtrim(rtrim(number_format($numericQty, 4), '0'), '.');
            $text = trim("{$formattedQty} {$baseUnitName}");

            return $text !== '' ? $text : $formattedQty;
        }

        $baseQtyInt = (int) round($numericQty);
        $formattedBase = number_format($baseQtyInt);

        // หาหน่วยใหญ่ที่มีคุณสมบัติ: active, factor จำนวนเต็ม > 1, ชื่อไม่เสีย
        $barcodes = $product->relationLoaded('barcodes')
            ? $product->barcodes
            : $product->barcodes()->with('unit')->get();

        $validBarcodes = $barcodes->filter(function (ProductBarcode $b) use ($product) {
            if (! $b->is_active) {
                return false;
            }
            $factor = (float) $b->unit_factor;
            if ($factor <= 1.0 || floor($factor) != $factor) {
                return false;
            }
            if (! $b->unit_id || (int) $b->unit_id === (int) $product->base_unit_id) {
                return false;
            }
            $unit = $b->unit;
            if (! $unit || $unit->isCorrupted() || trim((string) $unit->cleanName()) === '') {
                return false;
            }

            return true;
        });

        $largerBarcode = null;
        if ($unitId !== null && (int) $unitId !== (int) $product->base_unit_id) {
            $largerBarcode = $validBarcodes->firstWhere('unit_id', (int) $unitId);
        }

        if (! $largerBarcode) {
            $largerBarcode = $validBarcodes->sortByDesc(fn ($b) => (float) $b->unit_factor)->first();
        }

        if (! $largerBarcode) {
            $text = trim("{$formattedBase} {$baseUnitName}");

            return $text !== '' ? $text : $formattedBase;
        }

        $largerFactor = (int) $largerBarcode->unit_factor;
        $largerUnitName = $largerBarcode->unit?->cleanName() ?? '';
        if ($largerUnitName === '') {
            $text = trim("{$formattedBase} {$baseUnitName}");

            return $text !== '' ? $text : $formattedBase;
        }

        $largerQty = intdiv($baseQtyInt, $largerFactor);
        $remainder = $baseQtyInt % $largerFactor;

        // ไม่ถึง 1 หน่วยใหญ่
        if ($largerQty <= 0) {
            $text = trim("{$formattedBase} {$baseUnitName}");

            return $text !== '' ? $text : $formattedBase;
        }

        $formattedLarger = number_format($largerQty);
        $baseText = trim("{$formattedBase} {$baseUnitName}");

        if ($remainder === 0) {
            // พอดีลัง: "36 ถุง (2 ลัง)"
            return $baseText !== ''
                ? "{$baseText} ({$formattedLarger} {$largerUnitName})"
                : "{$formattedBase} ({$formattedLarger} {$largerUnitName})";
        }

        // มีเศษ: "40 ถุง (2 ลัง 4 ถุง)"
        $formattedRemainder = number_format($remainder);
        $remainderText = trim("{$formattedRemainder} {$baseUnitName}");

        return $baseText !== ''
            ? "{$baseText} ({$formattedLarger} {$largerUnitName} {$remainderText})"
            : "{$formattedBase} ({$formattedLarger} {$largerUnitName} {$remainderText})";
    }

    /**
     * ดึงรายการหน่วยสำหรับให้ผู้ใช้เลือก (รวมหน่วยหลัก factor 1)
     * เฉพาะหน่วยที่มีตัวคูณเป็นจำนวนเต็มบวก และชื่อไม่เสีย
     *
     * @param  Collection<string, mixed>|null  $productPrices
     * @return array<int, array{unit_id: int|null, unit_name: string, name: string, factor: string, barcode: string, price: float|null, is_base: bool}>
     */
    public static function selectableUnits(Product $product, ?Collection $productPrices = null): array
    {
        $units = [];
        $baseUnit = $product->baseUnit;
        $baseUnitName = ($baseUnit && ! $baseUnit->isCorrupted()) ? $baseUnit->cleanName() : 'หน่วยฐาน';

        $barcodes = $product->relationLoaded('barcodes')
            ? $product->barcodes
            : $product->barcodes()->where('is_active', true)->with('unit')->get();

        // 1. หน่วยหลัก (factor 1)
        $baseBarcode = $barcodes->firstWhere('unit_id', $product->base_unit_id)
            ?? $barcodes->first();

        // 1. หน่วยหลัก (factor 1): ราคาหน่วยหลักต้องเป็น default_price เหมือนก่อนงาน 37 ทุกกรณี
        $basePrice = $product->default_price !== null ? (float) $product->default_price : null;

        $units[] = [
            'unit_id' => $product->base_unit_id,
            'unit_name' => $baseUnitName,
            'name' => $baseUnitName,
            'factor' => '1',
            'barcode' => (string) ($baseBarcode?->barcode ?? ''),
            'price' => $basePrice,
            'is_base' => true,
        ];

        // 2. หน่วยอื่นจากบาร์โค้ด
        $seenUnitIds = $product->base_unit_id ? [$product->base_unit_id] : [];
        foreach ($barcodes as $barcode) {
            if (! $barcode->is_active || ! $barcode->unit_id || in_array($barcode->unit_id, $seenUnitIds, true)) {
                continue;
            }
            $factor = (float) $barcode->unit_factor;
            // ตัวคูณต้องเป็นจำนวนเต็มบวก
            if ($factor <= 0 || floor($factor) != $factor) {
                continue;
            }
            $unit = $barcode->unit;
            if (! $unit || $unit->isCorrupted()) {
                continue;
            }
            $seenUnitIds[] = $barcode->unit_id;
            $unitCleanName = $unit->cleanName();

            $price = $barcode->price !== null
                ? (float) $barcode->price
                : ($productPrices?->get($product->id.':'.$barcode->unit_id)?->price !== null
                    ? (float) $productPrices->get($product->id.':'.$barcode->unit_id)->price
                    : ($product->default_price !== null ? (float) ($product->default_price * $factor) : null));

            $units[] = [
                'unit_id' => $barcode->unit_id,
                'unit_name' => $unitCleanName,
                'name' => $unitCleanName,
                'factor' => (string) ((int) $factor),
                'barcode' => (string) ($barcode->barcode ?? ''),
                'price' => $price,
                'is_base' => false,
            ];
        }

        return $units;
    }

    /**
     * จำนวนในหน่วยที่เลือกของบรรทัด เช่น "2 ลัง" (ใช้ในใบรวมจองหลังจำนวนหน่วยหลัก)
     */
    public static function formatSingle(?ProductUnit $unit, float|int|string|null $qty): string
    {
        if ($qty === null || $qty === '') {
            return '-';
        }

        $formatted = rtrim(rtrim(number_format((float) $qty, 4), '0'), '.');
        $name = ($unit && ! $unit->isCorrupted()) ? trim((string) $unit->cleanName()) : '';

        return $name !== '' ? "{$formatted} {$name}" : $formatted;
    }
}
