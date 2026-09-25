<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\CustomerOpenItem;
use App\Models\CustomerLedger;
use App\Models\User;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Services\Accounting\GlPostingService;
use App\Services\Inventory\FifoStockService;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a sale return / credit note (ใบรับคืนสินค้า): stock comes back in, and if
 * the original sale opened an AR open item, that item's balance is reduced.
 * Does NOT create a new open item or payment - just walks back the AR and stock.
 */
class SaleReturnService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly GlPostingService $glPosting,
        private readonly FifoStockService $fifo,
    ) {}

    /**
     * @param  array{branch_id:int, customer_id:?int, customer_open_item_id:?int, remark:?string, items: array<int, array{product_id:int, qty:float, unit_price:float}>}  $data
     */
    public function create(array $data): Document
    {
        if (empty($data['items'])) {
            throw new RuntimeException('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $branch = Branch::findOrFail($data['branch_id']);
        if ($branch->default_warehouse_location_id === null) {
            throw new RuntimeException("สาขา {$branch->name_th} ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น");
        }

        $openItem = null;
        if (! empty($data['customer_open_item_id'])) {
            $openItem = CustomerOpenItem::findOrFail($data['customer_open_item_id']);
            if ((int) $openItem->customer_id !== (int) ($data['customer_id'] ?? 0)
                || (int) $openItem->document->branch_id !== (int) $branch->id) {
                throw new RuntimeException('ใบขายอ้างอิงไม่ตรงกับลูกค้าหรือสาขา');
            }
        }

        $documentType = DocumentType::where('code', 'SALE_RETURN')->firstOrFail();

        return DB::transaction(function () use ($data, $branch, $documentType, $openItem) {
            $items = collect($data['items']);
            foreach ($items as $item) {
                if (DecimalMath::compare($item['qty'], 0) <= 0 || DecimalMath::compare($item['unit_price'], 0) < 0) {
                    throw new RuntimeException('จำนวนคืนต้องมากกว่าศูนย์ และราคาต้องไม่ติดลบ');
                }
            }
            $totalAmount = DecimalMath::sum(
                $items->map(fn ($item) => DecimalMath::multiply($item['qty'], $item['unit_price'])),
            );
            $originalCosts = $openItem?->document?->stockDocument?->items
                ?->keyBy('product_id') ?? collect();
            $currentCosts = Product::whereIn('id', $items->pluck('product_id'))
                ->pluck('average_cost', 'id');

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->next('SALE_RETURN', $branch->id),
                'doc_date' => now()->toDateString(),
                'customer_id' => $data['customer_id'] ?? null,
                'created_by' => auth()->id(),
                'reference' => $openItem?->document->doc_number,
                'status' => 'pending_approval',
                'total_items' => $items->count(),
                'total_amount' => $totalAmount,
                'remark' => $data['remark'] ?? null,
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => DecimalMath::sum($items->pluck('qty'), DecimalMath::QUANTITY_SCALE),
                'total_items' => $items->count(),
            ]);

            $seq = 1;
            foreach ($items as $item) {
                $sourceLot = ! empty($item['source_stock_lot_id'])
                    ? StockLot::findOrFail($item['source_stock_lot_id']) : null;
                if ($sourceLot && (int) $sourceLot->product_id !== (int) $item['product_id']) {
                    throw new RuntimeException('Lot ต้นทางไม่ตรงกับสินค้าที่รับคืน');
                }
                if ($sourceLot && $openItem) {
                    $soldFromLot = (float) StockMovement::where('document_id', $openItem->document_id)
                        ->where('product_id', $item['product_id'])->where('stock_lot_id', $sourceLot->id)
                        ->sum('qty');
                    if (DecimalMath::compare($item['qty'], $soldFromLot) > 0) {
                        throw new RuntimeException('จำนวนรับคืนเกินจำนวนที่ขายจาก Lot ต้นทาง');
                    }
                }
                $unitCost = (float) ($originalCosts->get((int) $item['product_id'])?->unit_cost
                    ?? $currentCosts[(int) $item['product_id']] ?? 0);
                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $item['product_id'],
                    'source_stock_lot_id' => $sourceLot?->id,
                    'return_disposition' => $item['return_disposition'] ?? 'quarantine',
                    'warehouse_location_id' => $branch->default_warehouse_location_id,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $unitCost,
                    'cost_amount' => DecimalMath::multiply($item['qty'], $unitCost),
                ]);

                // Stock is restored only by approve(); creation must be side-effect free.
            }

            return $document->fresh();
        });
    }

    public function approve(Document $document, int $userId): Document
    {
        $actor = User::findOrFail($userId);
        if (! $actor->hasPermission('finance.note.approve')) {
            throw new RuntimeException('ไม่มีสิทธิ์อนุมัติใบรับคืน');
        }
        return DB::transaction(function () use ($document, $userId): Document {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $locked->load(['documentType', 'stockDocument.items.sourceStockLot']);
            app(\App\Services\Documents\ApprovalPolicyService::class)->authorize('SALE_RETURN', $locked->total_amount,
                (int) $locked->branch_id, $locked->created_by, User::findOrFail($userId), 'finance.note.approve');
            if ($locked->documentType->code !== 'SALE_RETURN' || $locked->status !== 'pending_approval') {
                throw new RuntimeException('เอกสารนี้ไม่ใช่ใบรับคืนที่รออนุมัติ');
            }
            if ((int) $locked->created_by === $userId) {
                throw new RuntimeException('ผู้สร้างใบรับคืนไม่สามารถอนุมัติรายการของตนเอง');
            }
            $actor = User::findOrFail($userId);
            if ($actor->branch_id && (int) $actor->branch_id !== (int) $locked->branch_id) {
                throw new RuntimeException('ไม่สามารถอนุมัติใบรับคืนต่างสาขา');
            }

            $openItem = $locked->customer_id
                ? CustomerOpenItem::where('customer_id', $locked->customer_id)
                    ->whereHas('document', fn ($q) => $q->where('doc_number', $locked->reference))
                    ->lockForUpdate()->first()
                : null;
            if ($locked->reference && ! $openItem) {
                throw new RuntimeException('ไม่พบลูกหนี้อ้างอิง กรุณาตรวจเอกสารต้นทาง');
            }
            if ($openItem && DecimalMath::compare($locked->total_amount, $openItem->balance_amount) > 0) {
                throw new RuntimeException('ยอดลูกหนี้ต้นทางไม่พอสำหรับใบรับคืนนี้');
            }
            $posReturn = DB::table('pos_receipt_returns')->where('document_id', $locked->id)->lockForUpdate()->first();
            if ($posReturn?->pos_shift_id) {
                $shift = DB::table('pos_shifts')->where('id', $posReturn->pos_shift_id)->lockForUpdate()->first();
                if (! $shift || $shift->status !== 'open' || (int) $shift->branch_id !== (int) $locked->branch_id) {
                    throw new RuntimeException('กะคืนเงินต้องยังเปิดอยู่และอยู่ในสาขาเดียวกัน');
                }
            }

            foreach ($locked->stockDocument->items as $item) {
                $sourceLot = $item->sourceStockLot;
                $unitCost = $item->unit_cost ?? 0;
                $returnedLot = $this->fifo->receive(
                    (int) $item->product_id, (int) $item->warehouse_location_id,
                    $item->qty, $locked->id, 'return_in', unitCost: $unitCost,
                );
                $disposition = $item->return_disposition ?? 'quarantine';
                $returnedLot->update([
                    'source_lot_id' => $sourceLot?->id,
                    'lot_number' => $sourceLot ? $sourceLot->lot_number.'-RET-'.$locked->id : $returnedLot->lot_number,
                    'manufacture_date' => $sourceLot?->manufacture_date,
                    'expiry_date' => $sourceLot?->expiry_date,
                    'quality_status' => $disposition === 'available' ? 'available' : 'quarantine',
                    'quality_reason' => $disposition === 'available' ? null : ($disposition === 'damage' ? 'สินค้ารับคืนรอตัดของเสีย' : 'สินค้ารับคืนรอตรวจคุณภาพ'),
                    'quality_updated_by' => $userId, 'quality_updated_at' => now(),
                ]);
            }

            if ($openItem) {
                $newBalance = DecimalMath::subtract($openItem->balance_amount, $locked->total_amount);
                $openItem->update(['balance_amount' => $newBalance, 'status' => DecimalMath::compare($newBalance, '0.01') <= 0 ? CustomerOpenItem::STATUS_PAID : CustomerOpenItem::STATUS_PARTIAL]);
                $lastBalance = CustomerLedger::where('customer_id', $locked->customer_id)->latest('id')->value('balance_after') ?? 0;
                CustomerLedger::create([
                    'customer_id' => $locked->customer_id, 'document_id' => $locked->id,
                    'entry_type' => 'credit', 'amount' => $locked->total_amount,
                    'balance_after' => DecimalMath::subtract($lastBalance, $locked->total_amount),
                    'entry_date' => $locked->doc_date,
                ]);
            }
            $this->glPosting->postSaleReturn($locked, $openItem !== null, $posReturn?->refund_method ?? 'cash');
            if ($posReturn) {
                DB::table('pos_receipt_returns')->where('id', $posReturn->id)->update(['status' => 'completed', 'updated_at' => now()]);
                if ($posReturn->pos_shift_id) {
                    $field = $posReturn->refund_method === 'cash' ? 'cash_sales' : 'transfer_sales';
                    DB::table('pos_shifts')->where('id', $posReturn->pos_shift_id)->decrement($field, $locked->total_amount);
                    if ($posReturn->refund_method === 'cash') {
                        DB::table('pos_shifts')->where('id', $posReturn->pos_shift_id)->decrement('expected_cash', $locked->total_amount);
                    }
                }
            }
            $locked->update(['status' => 'active', 'approved_by' => $userId, 'approved_at' => now()]);
            return $locked->fresh();
        });
    }
}
