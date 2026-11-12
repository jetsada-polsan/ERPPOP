<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\StockDocumentItem;

class UnitQty
{
    /**
     * จัดรูปแบบแสดงสองหน่วย (เช่น "36 ถุง (2 ลัง)" หรือ "40 ถุง (2 ลัง 4 ถุง)")
     *
     * @param  Product|null  $product  โมเดลสินค้า (ควร eager load 'baseUnit' และ 'barcodes.unit')
     * @param  float|int|string|null  $baseQty  จำนวนในหน่วยหลัก
     * @param  int|null  $unitId  หน่วยที่เลือกของบรรทัด (ถ้ามี ให้ใช้หน่วยนี้ก่อน)
     * @return string
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
     */
    public static function selectableUnits(Product $product): array
    {
        $units = [];
        $baseUnit = $product->baseUnit;
        $baseUnitName = ($baseUnit && ! $baseUnit->isCorrupted()) ? $baseUnit->cleanName() : 'หน่วยฐาน';

        // 1. หน่วยหลัก (factor 1)
        $units[] = [
            'unit_id' => $product->base_unit_id,
            'name' => $baseUnitName,
            'factor' => 1,
            'barcode' => $product->barcodes->firstWhere('unit_id', $product->base_unit_id)?->barcode
                ?? $product->barcodes->first()?->barcode
                ?? '',
            'is_base' => true,
        ];

        // 2. หน่วยอื่นจากบาร์โค้ด
        $seenUnitIds = [$product->base_unit_id];
        foreach ($product->barcodes as $barcode) {
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
            $units[] = [
                'unit_id' => $barcode->unit_id,
                'name' => $unit->cleanName(),
                'factor' => (int) $factor,
                'barcode' => $barcode->barcode,
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
