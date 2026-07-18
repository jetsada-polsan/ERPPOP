<?php

namespace App\Services\Sales;

use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Inventory\FifoStockService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Converts a pending booking (ใบจอง) into a credit sale (ใบขายเชื่อ): copies the
 * booking's line items into a new sale document, actually cuts on_hand_qty this
 * time (stock_movements + stock_balances), releases the reservation the booking
 * made, and opens an AR entry (customer_open_items) for the customer to pay later.
 * The booking itself is marked converted_to_sale and linked to the new document
 * so "ดึงใบจองมาอ้าง" (pull the booking as reference) is always traceable.
 */
class CreditSaleService
{
    private const DEFAULT_CREDIT_DAYS = 30;

    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly \App\Services\Accounting\GlPostingService $glPosting,
        private readonly FifoStockService $fifo,
    ) {}

    public function convertBookingToCreditSale(SaleBooking $booking, ?\App\Models\DocumentBook $book = null): Document
    {
        $documentType = DocumentType::where('code', DocumentType::CREDIT_SALE)->firstOrFail();
        $book ??= \App\Models\DocumentBook::defaultFor(DocumentType::CREDIT_SALE);

        return DB::transaction(function () use ($booking, $documentType, $book) {
            $lockedBooking = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($lockedBooking->status !== SaleBooking::STATUS_PENDING) {
                throw new RuntimeException("ใบจองนี้ถูกใช้ไปแล้ว (สถานะปัจจุบัน: {$lockedBooking->status})");
            }

            $bookingDocument = $lockedBooking->document()->with('stockDocument.items')->firstOrFail();
            $sourceItems = $bookingDocument->stockDocument?->items ?? collect();
            if ($sourceItems->isEmpty()) {
                throw new RuntimeException('ใบจองนี้ไม่มีรายการสินค้า');
            }

            $totalQty = $sourceItems->sum('qty');
            $totalAmount = $sourceItems->sum(fn ($i) => $i->qty * $i->unit_price);

            $saleDocument = Document::create([
                'document_type_id' => $documentType->id,
                'document_book_id' => $book?->id,
                'branch_id' => $bookingDocument->branch_id,
                'doc_number' => $book
                    ? $this->numbers->nextInBook($book, $bookingDocument->branch_id)
                    : $this->numbers->next(DocumentType::CREDIT_SALE, $bookingDocument->branch_id),
                'doc_date' => now()->toDateString(),
                'salesman_id' => $bookingDocument->salesman_id,
                'customer_id' => $bookingDocument->customer_id,
                'reference' => $bookingDocument->doc_number,
                'status' => 'active',
                'total_items' => $sourceItems->count(),
                'total_amount' => $totalAmount,
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $saleDocument->id,
                'total_qty' => $totalQty,
                'total_items' => $sourceItems->count(),
                'refer_reference' => $bookingDocument->doc_number,
            ]);

            $seq = 1;
            foreach ($sourceItems as $item) {
                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $item->product_id,
                    'warehouse_location_id' => $item->warehouse_location_id,
                    'qty' => $item->qty,
                    'unit_price' => $item->unit_price,
                ]);

                $balance = StockBalance::firstOrCreate(
                    ['product_id' => $item->product_id, 'warehouse_location_id' => $item->warehouse_location_id],
                    ['on_hand_qty' => 0, 'reserved_qty' => 0]
                );
                $this->fifo->issue((int) $item->product_id, (int) $item->warehouse_location_id, (float) $item->qty, $saleDocument->id);
                $balance->decrement('reserved_qty', (float) $item->qty);
            }

            CustomerOpenItem::create([
                'customer_id' => $bookingDocument->customer_id,
                'document_id' => $saleDocument->id,
                'salesman_id' => $bookingDocument->salesman_id,
                'gross_amount' => $totalAmount,
                'net_amount' => $totalAmount,
                'balance_amount' => $totalAmount,
                'due_date' => now()->addDays((int) (\App\Models\AppSetting::get('default_credit_days') ?: self::DEFAULT_CREDIT_DAYS))->toDateString(),
                'status' => CustomerOpenItem::STATUS_OPEN,
            ]);

            $lockedBooking->update([
                'status' => SaleBooking::STATUS_CONVERTED,
                'confirmed_at' => now(),
                'confirmed_document_id' => $saleDocument->id,
            ]);

            $this->glPosting->postCreditSale($saleDocument);

            return $saleDocument->fresh();
        });
    }
}
