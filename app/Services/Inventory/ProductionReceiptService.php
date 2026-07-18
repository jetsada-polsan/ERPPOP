<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ProductionOrder;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockMovement;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ใบรับสินค้าจากการผลิต (IP, BPlus manual 5-19): receive finished goods from a
 * production order into stock. Raw materials were already cut by the
 * requisition (DR เบิกเพื่อการผลิต) step, so this side only adds the output.
 */
class ProductionReceiptService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    public function receive(ProductionOrder $order, float $qty, ?string $remark = null): Document
    {
        if ($qty <= 0) {
            throw new RuntimeException('จำนวนรับเข้าต้องมากกว่า 0');
        }
        if ($order->status === 'completed') {
            throw new RuntimeException('ใบสั่งผลิตนี้ปิดงานแล้ว');
        }

        $branchId = $order->branch_id ?? Branch::orderBy('id')->value('id');
        $locationId = $order->warehouse_location_id
            ?? Branch::find($branchId)?->default_warehouse_location_id;
        if (! $locationId) {
            throw new RuntimeException('ใบสั่งผลิตนี้ไม่ได้ระบุคลังรับเข้า และสาขายังไม่มีคลังหลัก');
        }

        $documentType = DocumentType::where('code', 'PRODUCTION_RECEIPT')->firstOrFail();

        return DB::transaction(function () use ($order, $qty, $remark, $branchId, $locationId, $documentType) {
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branchId,
                'doc_number' => $this->numbers->next('PRODUCTION_RECEIPT', $branchId),
                'doc_date' => now()->toDateString(),
                'reference' => $order->doc_no,
                'status' => 'active',
                'total_items' => 1,
                'total_amount' => 0,
                'remark' => 'รับจากใบสั่งผลิต '.$order->doc_no.($remark ? ' | '.$remark : ''),
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => $qty,
                'total_items' => 1,
            ]);

            StockDocumentItem::create([
                'stock_document_id' => $stockDocument->id,
                'seq' => 1,
                'product_id' => $order->finished_product_id,
                'warehouse_location_id' => $locationId,
                'qty' => $qty,
            ]);

            StockMovement::create([
                'product_id' => $order->finished_product_id,
                'warehouse_location_id' => $locationId,
                'document_id' => $document->id,
                'movement_type' => 'in',
                'qty' => $qty,
                'movement_date' => now()->toDateString(),
            ]);

            $balance = StockBalance::firstOrCreate(
                ['product_id' => $order->finished_product_id, 'warehouse_location_id' => $locationId],
                ['on_hand_qty' => 0, 'reserved_qty' => 0]
            );
            $balance->increment('on_hand_qty', $qty);

            $produced = (float) $order->produced_qty + $qty;
            $order->update([
                'produced_qty' => $produced,
                'status' => $produced >= (float) $order->planned_qty ? 'completed' : 'in_progress',
            ]);

            return $document->fresh();
        });
    }
}
