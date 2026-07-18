<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a booking (ใบจอง): reserves stock against the branch's default
 * warehouse location without cutting on_hand_qty. Nothing here touches AR or
 * stock_movements - the booking only becomes a real sale (and a real stock cut)
 * once CreditSaleService::convertBookingToCreditSale() runs against it.
 */
class BookingService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  array{customer_id:int, branch_id:int, salesman_id:?int, remark:?string, items: array<int, array{product_id:int, qty:float, unit_price:float}>}  $data
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

        $documentType = DocumentType::where('code', DocumentType::BOOKING)->firstOrFail();

        return DB::transaction(function () use ($data, $branch, $documentType) {
            $items = collect($data['items']);
            $totalQty = $items->sum('qty');
            $totalAmount = $items->sum(fn ($i) => $i['qty'] * $i['unit_price']);

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->next(DocumentType::BOOKING, $branch->id),
                'doc_date' => now()->toDateString(),
                'salesman_id' => $data['salesman_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'status' => 'active',
                'total_items' => $items->count(),
                'total_amount' => $totalAmount,
                'remark' => $data['remark'] ?? null,
            ]);

            SaleBooking::create([
                'document_id' => $document->id,
                'salesman_id' => $data['salesman_id'] ?? null,
                'status' => SaleBooking::STATUS_PENDING,
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => $totalQty,
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

                $balance = StockBalance::firstOrCreate(
                    ['product_id' => $item['product_id'], 'warehouse_location_id' => $branch->default_warehouse_location_id],
                    ['on_hand_qty' => 0, 'reserved_qty' => 0]
                );
                $balance->increment('reserved_qty', $item['qty']);
            }

            return $document->fresh();
        });
    }
}
