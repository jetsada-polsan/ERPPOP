<?php

namespace App\Services\Inventory;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves stock between two warehouse locations (โอนย้ายสต็อก), e.g. restocking a
 * mobile sales cart ("ตู้") from the head office warehouse. Unlike
 * Purchase/CreditSale which only touch one location, a transfer always touches
 * two - the source location lives on stock_document_items.warehouse_location_id
 * (the "from"), the destination lives on stock_documents.to_warehouse_location_id.
 *
 * Two-step flow: พนักงานสาขา createRequest() = ใบ pending (ยังไม่ตัดสต๊อก) ->
 * ผู้ถือ stock.manage approve() = ตัด/เพิ่มสต๊อกจริง. create() = โอนตรงขั้นเดียว
 * (สร้าง+ตัดสต๊อกทันที) สำหรับผู้มีสิทธิ์ stock.manage เอง.
 */
class StockTransferService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly FifoStockService $fifo,
    ) {}

    /**
     * สร้าง "ใบขอโอน" สถานะ pending - ยังไม่ตัดสต๊อก รอผู้มีสิทธิ์อนุมัติ.
     *
     * @param  array{branch_id:int, from_warehouse_location_id:int, to_warehouse_location_id:int, remark:?string, items: array<int, array{product_id:int, qty:float}>}  $data
     */
    public function createRequest(array $data): Document
    {
        return DB::transaction(fn () => $this->buildDocument($data, 'pending'));
    }

    /**
     * อนุมัติใบขอโอน = ตัดสต๊อกต้นทาง + เพิ่มปลายทางจริง แล้วเปลี่ยนสถานะเป็น active.
     */
    public function approve(Document $document): Document
    {
        return DB::transaction(function () use ($document) {
            $this->applyStockMovements($document);
            $document->update(['status' => 'active']);

            return $document->fresh();
        });
    }

    /**
     * ปฏิเสธใบขอโอน - ไม่ตัดสต๊อก บันทึกเหตุผลต่อท้ายหมายเหตุ.
     */
    public function reject(Document $document, ?string $reason = null): Document
    {
        $remark = $document->remark;
        if ($reason !== null && trim($reason) !== '') {
            $remark = trim(($remark ? $remark.' | ' : '').'ปฏิเสธ: '.trim($reason));
        }
        $document->update(['status' => 'rejected', 'remark' => $remark]);

        return $document->fresh();
    }

    /**
     * โอนตรงขั้นเดียว (สร้าง + ตัดสต๊อกทันที) สำหรับผู้มีสิทธิ์ stock.manage.
     *
     * @param  array{branch_id:int, from_warehouse_location_id:int, to_warehouse_location_id:int, remark:?string, items: array<int, array{product_id:int, qty:float}>}  $data
     */
    public function create(array $data): Document
    {
        return DB::transaction(function () use ($data) {
            $document = $this->buildDocument($data, 'active');
            $this->applyStockMovements($document);

            return $document->fresh();
        });
    }

    /**
     * สร้างหัวเอกสาร + รายการ (ยังไม่ยุ่งกับสต๊อก).
     */
    private function buildDocument(array $data, string $status): Document
    {
        $fromLocationId = (int) $data['from_warehouse_location_id'];
        $toLocationId = (int) $data['to_warehouse_location_id'];
        if ($fromLocationId === $toLocationId) {
            throw new RuntimeException('ต้นทางและปลายทางต้องไม่ใช่คลังเดียวกัน');
        }

        $items = collect($data['items'])->filter(fn ($i) => (float) $i['qty'] > 0)->values();
        if ($items->isEmpty()) {
            throw new RuntimeException('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $documentType = DocumentType::where('code', 'STOCK_TRANSFER')->firstOrFail();
        $totalQty = $items->sum('qty');

        $document = Document::create([
            'document_type_id' => $documentType->id,
            'branch_id' => $data['branch_id'],
            'doc_number' => $this->numbers->next('STOCK_TRANSFER', $data['branch_id']),
            'doc_date' => now()->toDateString(),
            'status' => $status,
            'total_items' => $items->count(),
            'total_amount' => 0,
            'remark' => $data['remark'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $stockDocument = StockDocument::create([
            'document_id' => $document->id,
            'to_warehouse_location_id' => $toLocationId,
            'total_qty' => $totalQty,
            'total_items' => $items->count(),
        ]);

        $seq = 1;
        foreach ($items as $item) {
            StockDocumentItem::create([
                'stock_document_id' => $stockDocument->id,
                'seq' => $seq++,
                'product_id' => $item['product_id'],
                'warehouse_location_id' => $fromLocationId,
                'qty' => $item['qty'],
            ]);
        }

        return $document->fresh();
    }

    /**
     * ตัดสต๊อกต้นทาง + เพิ่มปลายทาง จากรายการในใบ (ตรวจสอบความพอ ณ ตอนนี้).
     */
    private function applyStockMovements(Document $document): void
    {
        $stockDocument = $document->stockDocument()->with('items')->first();
        if (! $stockDocument) {
            throw new RuntimeException('ไม่พบรายละเอียดใบโอน');
        }

        $toLocationId = (int) $stockDocument->to_warehouse_location_id;
        $items = $stockDocument->items;
        if ($items->isEmpty()) {
            throw new RuntimeException('ไม่มีรายการสินค้า');
        }

        foreach ($items as $item) {
            $fromLocationId = (int) $item->warehouse_location_id;
            $available = (float) (StockBalance::where('product_id', $item->product_id)
                ->where('warehouse_location_id', $fromLocationId)
                ->value('on_hand_qty') ?? 0);
            if ((float) $item->qty > $available + 0.0001) {
                throw new RuntimeException('สต็อกต้นทางไม่พอสำหรับสินค้าบางรายการ');
            }
        }

        foreach ($items as $item) {
            $fromLocationId = (int) $item->warehouse_location_id;

            $allocations = $this->fifo->issue(
                (int) $item->product_id, $fromLocationId, (float) $item->qty,
                $document->id, 'transfer_out'
            );
            foreach ($allocations as $allocation) {
                $sourceLot = $allocation['lot'];
                $this->fifo->receive(
                    (int) $item->product_id, $toLocationId, (float) $allocation['qty'],
                    $document->id, 'transfer_in', $sourceLot->lot_number,
                    now()->toDateString(), $sourceLot->expiry_date?->toDateString(), (float) $sourceLot->unit_cost
                );
            }
        }
    }
}
