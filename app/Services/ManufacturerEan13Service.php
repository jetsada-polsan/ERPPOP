<?php

namespace App\Services;

use App\Models\ProductBarcode;
use App\Support\BarcodePolicy;
use Illuminate\Support\Facades\DB;

class ManufacturerEan13Service
{
    /**
     * A legacy 885 code becomes GS1 only when all thirteen digits pass the
     * check digit. Values that merely look similar remain internal so an
     * existing scanner workflow is never broken by a guess.
     *
     * @return array{standard: array<int, array<string, mixed>>, exceptions: array<int, array<string, mixed>>}
     */
    public function plan(): array
    {
        $policy = app(BarcodePolicy::class);
        $standard = [];
        $exceptions = [];

        ProductBarcode::query()
            ->with('product:id,sku_code,name_th')
            ->where('barcode', 'like', '885%')
            // chunkById advances by id; ordering by barcode here would skip
            // rows whenever barcode order and id order differ.
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$standard, &$exceptions, $policy): void {
                foreach ($rows as $row) {
                    $entry = [
                        'barcode_id' => $row->id,
                        'barcode' => (string) $row->barcode,
                        'barcode_type_before' => $row->barcode_type,
                        'sku' => $row->product?->sku_code,
                        'name' => $row->product?->name_th,
                    ];
                    if ($row->product === null) {
                        $exceptions[] = $entry + ['reason' => 'ไม่พบสินค้าแม่'];
                    } elseif ($policy->isValidEan13((string) $row->barcode)) {
                        $standard[] = $entry;
                    } else {
                        $exceptions[] = $entry + ['reason' => '885 แต่ไม่ผ่าน EAN-13 check digit'];
                    }
                }
            });

        return compact('standard', 'exceptions');
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function apply(array $rows): int
    {
        $ids = collect($rows)->pluck('barcode_id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(fn () => ProductBarcode::whereIn('id', $ids)
            ->where('barcode_type', '!=', BarcodePolicy::EAN13_STANDARD)
            ->update([
                'barcode_type' => BarcodePolicy::EAN13_STANDARD,
                'type_note' => 'Manufacturer EAN-13 (legacy 885 code verified by check digit).',
            ]));
    }
}
