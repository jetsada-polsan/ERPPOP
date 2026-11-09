<?php

namespace App\Services\Sales;

use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\CustomerOpenItem;
use App\Models\Document;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Accounting\AccountingPeriodGuard;
use App\Services\Accounting\GlPostingService;
use App\Services\Inventory\CostingService;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\NegativeStockPolicyService;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;
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
        private readonly GlPostingService $glPosting,
        private readonly FifoStockService $fifo,
        private readonly CostingService $costing,
        private readonly AccountingPeriodGuard $periods,
        private readonly NegativeStockPolicyService $negativeStockPolicy,
    ) {}

    public function convertBookingToCreditSale(SaleBooking $booking, ?DocumentBook $book = null): Document
    {
        $documentType = DocumentType::where('code', DocumentType::CREDIT_SALE)->firstOrFail();
        

        return DB::transaction(function () use ($booking, $documentType, $book) {
            $lockedBooking = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($lockedBooking->status !== SaleBooking::STATUS_PENDING) {
                throw new RuntimeException("ใบจองนี้ถูกใช้ไปแล้ว (สถานะปัจจุบัน: {$lockedBooking->status})");
            }
            if ($lockedBooking->stock_shortage_status === 'pending') {
                throw new RuntimeException('ใบจองนี้สต๊อกในระบบไม่พอ ต้องให้ผู้จัดการยืนยันใน ERP ก่อนออกใบขาย');
            }

            $bookingDocument = $lockedBooking->document()->with('stockDocument.items')->firstOrFail();
            $sourceItems = $bookingDocument->stockDocument?->items ?? collect();
            if ($sourceItems->isEmpty()) {
                throw new RuntimeException('ใบจองนี้ไม่มีรายการสินค้า');
            }

            // คอนเฟิร์มบิลตอนบ่าย ระหว่างรถยังส่งของอยู่ได้ ตัดสต๊อกและตั้งลูกหนี้ตอนนี้
            $saleDate = $lockedBooking->delivered_at?->toDateString() ?? now()->toDateString();
            $this->periods->assertOpen($saleDate, $bookingDocument->branch_id);

            $groups = $sourceItems->sortBy('id')->groupBy(fn ($item) => $item->product->is_vat ? 'DS' : 'DSN');
            $sales = collect();
            foreach ($groups as $code => $groupItems) {
                $noVat = $code === 'DSN';
                // Prefer the book named DS/DSN; otherwise any active credit-sale book with the same tax setting.
                $groupBook = DocumentBook::where('document_type_id', $documentType->id)->where('is_active', true)
                    ->where('code', $code)->first()
                    ?? DocumentBook::where('document_type_id', $documentType->id)->where('is_active', true)
                        ->where('no_vat', $noVat)->orderByDesc('is_default')->orderBy('id')->first();
                if ($groupBook && (bool) $groupBook->no_vat !== $noVat) {
                    throw new RuntimeException("ตั้งค่าภาษีเล่ม {$code} ไม่ถูกต้อง");
                }
                if (! $groupBook && $groups->count() > 1) {
                    throw new RuntimeException("ยังไม่มีเล่มขายเชื่อ {$code} ที่เปิดใช้งาน");
                }
                $groupBook ??= $book ?? DocumentBook::defaultFor(DocumentType::CREDIT_SALE);
                $sales->push($this->createSale($lockedBooking, $bookingDocument, $groupItems, $groupBook, $saleDate));
            }
            if (DecimalMath::compare(DecimalMath::sum($sales->pluck('total_amount')), $bookingDocument->total_amount) !== 0) {
                throw new RuntimeException('ยอดใบขายรวมไม่ตรงกับใบจอง กรุณาตรวจรายการต้นทาง');
            }
            $lockedBooking->update([
                'status' => SaleBooking::STATUS_CONVERTED,
                'confirmed_at' => now(),
                'confirmed_document_id' => $sales->first()->id,
            ]);

            return $sales->first();
        });
    }

    private function createSale(SaleBooking $booking, Document $bookingDocument, $sourceItems, ?DocumentBook $book, string $saleDate): Document
    {
            $documentType = DocumentType::where('code', DocumentType::CREDIT_SALE)->firstOrFail();
            $totalQty = DecimalMath::sum($sourceItems->pluck('qty'), DecimalMath::QUANTITY_SCALE);
            $totalAmount = DecimalMath::sum($sourceItems->map(fn ($item) => DecimalMath::multiply($item->qty, $item->unit_price)));

            $saleDocument = Document::create([
                'document_type_id' => $documentType->id,
                'document_book_id' => $book?->id,
                'branch_id' => $bookingDocument->branch_id,
                'doc_number' => $book
                    ? $this->numbers->nextInBook($book, $bookingDocument->branch_id, $saleDate)
                    : $this->numbers->next(DocumentType::CREDIT_SALE, $bookingDocument->branch_id, $saleDate),
                'doc_date' => $saleDate,
                'salesman_id' => $bookingDocument->salesman_id,
                'sales_user_id' => $bookingDocument->sales_user_id,
                'sales_area_id' => $bookingDocument->sales_area_id,
                'customer_id' => $bookingDocument->customer_id,
                'reference' => $bookingDocument->doc_number,
                'source_document_id' => $bookingDocument->id,
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
            $products = Product::whereIn('id', $sourceItems->pluck('product_id')->unique())->get()->keyBy('id');
            $branch = Branch::findOrFail($bookingDocument->branch_id);
            foreach ($sourceItems as $item) {
                $productId = (int) $item->product_id;
                $qty = $item->qty;
                $fallbackCost = $products->get($productId)?->average_cost ?? 0;

                $balance = StockBalance::firstOrCreate(
                    ['product_id' => $productId, 'warehouse_location_id' => $item->warehouse_location_id],
                    ['on_hand_qty' => 0, 'reserved_qty' => 0]
                );
                // ตัด FIFO lot จริงก่อน แล้วคิดต้นทุนขายจาก Lot ที่ถูกตัดจริง (ดู CashSaleService)
                $balance->decrement('reserved_qty', $qty);
                $allocations = $this->fifo->issue(
                    $productId,
                    (int) $item->warehouse_location_id,
                    $qty,
                    $saleDocument->id,
                    // ผู้จัดการยืนยันแล้วว่าของจริงมี: ยอมให้สต๊อกติดลบชั่วคราวจนกว่าจะรับเข้า/ปรับยอด
                    allowNegative: $booking->stock_shortage_status === 'approved' || $branch->allowsNegativeStock()
                        && $this->negativeStockPolicy->policyFor($products->get($productId)) === 'allow'
                        && ($products->get($productId)?->negative_stock_policy ?? 'allow') === 'allow',
                );
                $unitCost = $this->costing->unitCostFromAllocations($allocations, $qty, $fallbackCost);

                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $productId,
                    'warehouse_location_id' => $item->warehouse_location_id,
                    'qty' => $qty,
                    'unit_price' => $item->unit_price,
                    'unit_cost' => $unitCost,
                    'cost_amount' => DecimalMath::multiply($qty, $unitCost),
                ]);
            }

            CustomerOpenItem::create([
                'customer_id' => $bookingDocument->customer_id,
                'document_id' => $saleDocument->id,
                'salesman_id' => $bookingDocument->salesman_id,
                'sales_user_id' => $bookingDocument->sales_user_id,
                'gross_amount' => $totalAmount,
                'net_amount' => $totalAmount,
                'balance_amount' => $totalAmount,
                'due_date' => Carbon::parse($saleDate)
                    ->addDays((int) (AppSetting::get('default_credit_days') ?: self::DEFAULT_CREDIT_DAYS))
                    ->toDateString(),
                'status' => CustomerOpenItem::STATUS_OPEN,
            ]);

            $this->glPosting->postCreditSale($saleDocument);

            return $saleDocument->fresh();
    }
}
