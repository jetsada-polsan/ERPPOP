<?php

namespace App\Services;

use App\Models\ProductBarcode;
use App\Support\BarcodePolicy;
use Illuminate\Support\Facades\DB;

class LegacyScalePluService
{
    /**
     * A legacy PLU is only the exact six digit 801xxx reference stored in the
     * product barcode row.  It is not the 13 digit label printed by the scale.
     * Keeping the two concepts separate prevents a scanned EAN-13 label from
     * being treated as a catalogue barcode.
     *
     * @return array{candidates: array<int, array<string, mixed>>, exceptions: array<int, array<string, mixed>>}
     */
    public function plan(): array
    {
        $rows = ProductBarcode::query()
            ->with(['product:id,sku_code,name_th,product_category_id', 'product.category:id,code'])
            ->where('barcode', 'like', '801%')
            ->orderBy('barcode')
            ->get();

        $candidates = [];
        $exceptions = [];
        foreach ($rows as $row) {
            $entry = [
                'barcode_id' => $row->id,
                'plu' => $row->barcode,
                'barcode_type_before' => $row->barcode_type,
                'sku' => $row->product?->sku_code,
                'name' => $row->product?->name_th,
                'category' => $row->product?->category?->code,
                'active' => (bool) $row->is_active,
            ];
            if (preg_match('/^801[0-9]{3}$/', (string) $row->barcode) !== 1) {
                $exceptions[] = $entry + ['reason' => 'รูปแบบไม่ใช่ PLU 801xxx 6 หลัก'];
            } elseif ($row->product === null) {
                // Barcode orphan rows are legacy debris, not a usable scale
                // configuration. Do not make POS treat them as a product.
                $exceptions[] = $entry + ['reason' => 'ไม่พบสินค้าแม่ที่ผูกกับ PLU นี้'];
            } else {
                $candidates[] = $entry;
            }
        }

        return compact('candidates', 'exceptions');
    }

    /** @param array<int, array<string, mixed>> $candidates */
    public function apply(array $candidates): int
    {
        return DB::transaction(function () use ($candidates): int {
            $ids = collect($candidates)->pluck('barcode_id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                return 0;
            }

            return ProductBarcode::whereIn('id', $ids)
                ->where('barcode_type', '!=', BarcodePolicy::SCALE_PLU)
                ->update([
                    'barcode_type' => BarcodePolicy::SCALE_PLU,
                    'type_note' => 'Legacy scale PLU 801xxx; EAN-13 labels are decoded separately.',
                ]);
        });
    }
}
