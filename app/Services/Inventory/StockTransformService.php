<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockMovement;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ใบแปรรูปสินค้า (DT, BPlus manual 5-21): consume raw-material lines (ตัดสต๊อก)
 * and receive output lines (รับเข้า) in one document. Total raw value is
 * allocated to outputs by %ปันทุน, which sets the output's received unit cost.
 * Raw lines are stored with negative qty (out), outputs positive (in), same
 * sign convention as adjustment documents.
 */
class StockTransformService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param array{
     *   branch_id:int, warehouse_location_id:?int, remark:?string,
     *   raw_items: array<int, array{product_id:int, qty:float, unit_price:float}>,
     *   output_items: array<int, array{product_id:int, qty:float, percent:?float}>
     * } $data
     */
    public function create(array $data): Document
    {
        if (empty($data['raw_items']) || empty($data['output_items'])) {
            throw new RuntimeException('ต้องมีวัตถุดิบและผลผลิตอย่างน้อยอย่างละ 1 รายการ');
        }

        $branch = Branch::findOrFail($data['branch_id']);
        $locationId = $data['warehouse_location_id'] ?? $branch->default_warehouse_location_id;
        if (! $locationId) {
            throw new RuntimeException("สาขา {$branch->name_th} ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น");
        }

        $raw = collect($data['raw_items']);
        $outputs = collect($data['output_items']);
        $rawTotal = $raw->sum(fn ($i) => (float) $i['qty'] * (float) $i['unit_price']);

        // %ปันทุน: ไม่กรอกเลย = เฉลี่ยเท่ากันทุกผลผลิต, กรอกแล้วต้องรวม ~100
        $percents = $outputs->pluck('percent')->filter(fn ($p) => $p !== null && $p !== '');
        if ($percents->isEmpty()) {
            $equal = round(100 / $outputs->count(), 4);
            $outputs = $outputs->map(function ($i) use ($equal) {
                $i['percent'] = $equal;

                return $i;
            });
        } elseif (abs($percents->sum() - 100) > 0.01 || $percents->count() !== $outputs->count()) {
            throw new RuntimeException('%ปันทุนของผลผลิตต้องกรอกครบทุกแถวและรวมกันได้ 100%');
        }

        $documentType = DocumentType::where('code', 'STOCK_TRANSFORM')->firstOrFail();

        return DB::transaction(function () use ($data, $branch, $locationId, $raw, $outputs, $rawTotal, $documentType) {
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->next('STOCK_TRANSFORM', $branch->id),
                'doc_date' => now()->toDateString(),
                'status' => 'active',
                'total_items' => $raw->count() + $outputs->count(),
                'total_amount' => $rawTotal,
                'remark' => $data['remark'] ?? null,
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => $raw->sum('qty') + $outputs->sum('qty'),
                'total_items' => $raw->count() + $outputs->count(),
            ]);

            $seq = 1;

            foreach ($raw as $item) {
                $this->writeLine($stockDocument, $document, $seq++, $locationId, $item['product_id'],
                    -(float) $item['qty'], (float) $item['unit_price'], 'out', (float) $item['qty']);
            }

            foreach ($outputs as $item) {
                $qty = (float) $item['qty'];
                $allocated = $rawTotal * ((float) $item['percent']) / 100;
                $unitCost = $qty > 0 ? round($allocated / $qty, 4) : 0;
                $this->writeLine($stockDocument, $document, $seq++, $locationId, $item['product_id'],
                    $qty, $unitCost, 'in', $qty);
            }

            return $document->fresh();
        });
    }

    private function writeLine(StockDocument $stockDocument, Document $document, int $seq, int $locationId,
        int $productId, float $signedQty, float $unitPrice, string $movementType, float $absQty): void
    {
        StockDocumentItem::create([
            'stock_document_id' => $stockDocument->id,
            'seq' => $seq,
            'product_id' => $productId,
            'warehouse_location_id' => $locationId,
            'qty' => $signedQty,
            'unit_price' => $unitPrice,
        ]);

        StockMovement::create([
            'product_id' => $productId,
            'warehouse_location_id' => $locationId,
            'document_id' => $document->id,
            'movement_type' => $movementType,
            'qty' => $absQty,
            'movement_date' => now()->toDateString(),
        ]);

        $balance = StockBalance::firstOrCreate(
            ['product_id' => $productId, 'warehouse_location_id' => $locationId],
            ['on_hand_qty' => 0, 'reserved_qty' => 0]
        );
        $movementType === 'in'
            ? $balance->increment('on_hand_qty', $absQty)
            : $balance->decrement('on_hand_qty', $absQty);
    }
}
