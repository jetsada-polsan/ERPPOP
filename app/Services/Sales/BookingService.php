<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\Customer;
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
     * @param  array{customer_id:int, branch_id:int, sales_area_id?:?int, sales_user_id:?int, salesman_id:?int, document_book_id?:?int, claim_customer_owner?:bool, remark:?string, items: array<int, array{product_id:int, qty:float, unit_price:float}>}  $data
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
        $documentBook = null;
        if (! empty($data['document_book_id'])) {
            $documentBook = DocumentBook::where('document_type_id', $documentType->id)
                ->where('is_active', true)
                ->findOrFail($data['document_book_id']);
        }

        return DB::transaction(function () use ($data, $branch, $documentType, $documentBook) {
            $items = collect($data['items']);
            $totalQty = $items->sum('qty');
            $totalAmount = $items->sum(fn ($i) => $i['qty'] * $i['unit_price']);

            if (! empty($data['claim_customer_owner'])) {
                $customer = Customer::whereKey($data['customer_id'])->lockForUpdate()->firstOrFail();
                $customer->fill([
                    'sales_user_id' => $customer->sales_user_id ?? ($data['sales_user_id'] ?? null),
                    'sales_area_id' => $customer->sales_area_id ?? ($data['sales_area_id'] ?? null),
                ])->save();
            }

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'document_book_id' => $documentBook?->id,
                'branch_id' => $branch->id,
                'doc_number' => $documentBook
                    ? $this->numbers->nextInBook($documentBook, $branch->id)
                    : $this->numbers->next(DocumentType::BOOKING, $branch->id),
                'doc_date' => now()->toDateString(),
                'salesman_id' => $data['salesman_id'] ?? null,
                'sales_user_id' => $data['sales_user_id'] ?? null,
                'sales_area_id' => $data['sales_area_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'status' => 'active',
                'total_items' => $items->count(),
                'total_amount' => $totalAmount,
                'remark' => $data['remark'] ?? null,
            ]);

            SaleBooking::create([
                'document_id' => $document->id,
                'salesman_id' => $data['salesman_id'] ?? null,
                'sales_user_id' => $data['sales_user_id'] ?? null,
                'sales_area_id' => $data['sales_area_id'] ?? null,
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
