<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockMovement;
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
        private readonly \App\Services\Accounting\GlPostingService $glPosting,
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
        }

        $documentType = DocumentType::where('code', 'SALE_RETURN')->firstOrFail();

        return DB::transaction(function () use ($data, $branch, $documentType, $openItem) {
            $items = collect($data['items']);
            $totalAmount = $items->sum(fn ($i) => $i['qty'] * $i['unit_price']);

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->next('SALE_RETURN', $branch->id),
                'doc_date' => now()->toDateString(),
                'customer_id' => $data['customer_id'] ?? null,
                'status' => 'active',
                'total_items' => $items->count(),
                'total_amount' => $totalAmount,
                'remark' => $data['remark'] ?? null,
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => $items->sum('qty'),
                'total_items' => $items->count(),
            ]);

            $seq = 1;
            foreach ($items as $item) {
                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $item['product_id'],
                    'warehouse_location_id' => $branch->default_warehouse_location_id,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                ]);

                StockMovement::create([
                    'product_id' => $item['product_id'],
                    'warehouse_location_id' => $branch->default_warehouse_location_id,
                    'document_id' => $document->id,
                    'movement_type' => 'in',
                    'qty' => $item['qty'],
                    'movement_date' => now()->toDateString(),
                ]);

                $balance = StockBalance::firstOrCreate(
                    ['product_id' => $item['product_id'], 'warehouse_location_id' => $branch->default_warehouse_location_id],
                    ['on_hand_qty' => 0, 'reserved_qty' => 0]
                );
                $balance->increment('on_hand_qty', (float) $item['qty']);
            }

            if ($openItem !== null) {
                $reduction = min((float) $totalAmount, (float) $openItem->balance_amount);
                $newBalance = round((float) $openItem->balance_amount - $reduction, 4);
                $openItem->update([
                    'balance_amount' => $newBalance,
                    'status' => $newBalance <= 0.01 ? CustomerOpenItem::STATUS_PAID : CustomerOpenItem::STATUS_PARTIAL,
                ]);
            }

            // ลง GL: กลับรายได้+ภาษี และกลับต้นทุนขาย - คืนที่มีลูกหนี้ = ลด AR,
            // ไม่มี (ขายสด) = คืนเงินสด
            $this->glPosting->postSaleReturn($document, $openItem !== null);

            return $document->fresh();
        });
    }
}
