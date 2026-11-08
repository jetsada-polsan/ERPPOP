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
use Illuminate\Support\Collection;
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

    public function convertBookingToCreditSale(SaleBooking $booking): Document
    {
        // Preserve the legacy single-document return contract for internal callers;
        // actual conversion defaults each line to its product VAT master status.
        return $this->convertBookingToCreditSalesByVat($booking)->firstOrFail();
    }

    /**
     * Confirm a booking as one or two credit-sale documents, assigning each line
     * to DS or DSN from the submitted per-line VAT selection, defaulting to the
     * product's VAT master flag. Stock and reservations are consumed once inside
     * the same transaction; the delivery note remains the original single booking.
     *
     * @return Collection<int, Document>
     */
    public function convertBookingToCreditSalesByVat(SaleBooking $booking, array $vatSelections = []): Collection
    {
        $documentType = DocumentType::where('code', DocumentType::CREDIT_SALE)->firstOrFail();

        return DB::transaction(function () use ($booking, $documentType, $vatSelections) {
            $lockedBooking = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($lockedBooking->status !== SaleBooking::STATUS_PENDING) {
                throw new RuntimeException("ใบจองนี้ถูกใช้ไปแล้ว (สถานะปัจจุบัน: {$lockedBooking->status})");
            }

            $bookingDocument = $lockedBooking->document()->with('stockDocument.items')->firstOrFail();
            $sourceItems = $bookingDocument->stockDocument?->items ?? collect();
            if ($sourceItems->isEmpty()) {
                throw new RuntimeException('ใบจองนี้ไม่มีรายการสินค้า');
            }

            $saleDate = $lockedBooking->delivered_at?->toDateString() ?? now()->toDateString();
            $this->periods->assertOpen($saleDate, $bookingDocument->branch_id);
            $products = Product::whereIn('id', $sourceItems->pluck('product_id')->unique())->get()->keyBy('id');
            if ($products->count() !== $sourceItems->pluck('product_id')->unique()->count()) {
                throw new RuntimeException('พบสินค้าที่ถูกลบหรือไม่มีข้อมูล VAT ไม่สามารถยืนยันใบจองได้');
            }

            $bookingItemIds = $sourceItems->pluck('id')->map(fn ($id) => (string) $id)->all();
            if (array_diff(array_map('strval', array_keys($vatSelections)), $bookingItemIds)) {
                throw new RuntimeException('พบรายการ VAT ที่ไม่ตรงกับสินค้าในใบจอง กรุณาโหลดหน้าใหม่');
            }
            foreach ($vatSelections as $selection) {
                if (! in_array($selection, [true, false, 1, 0, '1', '0'], true)) {
                    throw new RuntimeException('ค่าเลือก VAT ไม่ถูกต้อง');
                }
            }

            $vatApplicableByItem = [];
            $groups = $sourceItems->groupBy(function ($item) use ($products, $vatSelections, &$vatApplicableByItem) {
                $vatApplicable = array_key_exists((string) $item->id, $vatSelections)
                    ? filter_var($vatSelections[(string) $item->id], FILTER_VALIDATE_BOOLEAN)
                    : (bool) $products->get((int) $item->product_id)->is_vat;
                $vatApplicableByItem[(int) $item->id] = $vatApplicable;

                return $vatApplicable ? 'taxable' : 'no_vat';
            });
            $books = DocumentBook::where('document_type_id', $documentType->id)
                ->where('is_active', true)
                ->orderByDesc('is_default')->orderBy('id')->get();
            $created = collect();
            $branch = Branch::findOrFail($bookingDocument->branch_id);

            foreach (['taxable', 'no_vat'] as $vatGroup) {
                $items = $groups->get($vatGroup, collect());
                if ($items->isEmpty()) {
                    continue;
                }

                $noVat = $vatGroup === 'no_vat';
                $book = $books->first(fn (DocumentBook $candidate) => (bool) $candidate->no_vat === $noVat);
                if (! $book) {
                    $label = $noVat ? 'DSN (เล่มไม่มีภาษี)' : 'DS (เล่มปกติ)';
                    throw new RuntimeException("ยังไม่มีเล่ม {$label} ที่เปิดใช้งาน กรุณาตั้งค่าเล่มเอกสารก่อนยืนยัน");
                }

                $totalQty = DecimalMath::sum($items->pluck('qty'), DecimalMath::QUANTITY_SCALE);
                $totalAmount = DecimalMath::sum($items->map(fn ($item) => DecimalMath::multiply($item->qty, $item->unit_price)));
                $saleDocument = Document::create([
                    'document_type_id' => $documentType->id,
                    'document_book_id' => $book->id,
                    'branch_id' => $bookingDocument->branch_id,
                    'doc_number' => $this->numbers->nextInBook($book, $bookingDocument->branch_id, $saleDate),
                    'doc_date' => $saleDate,
                    'salesman_id' => $bookingDocument->salesman_id,
                    'sales_user_id' => $bookingDocument->sales_user_id,
                    'sales_area_id' => $bookingDocument->sales_area_id,
                    'customer_id' => $bookingDocument->customer_id,
                    'reference' => $bookingDocument->doc_number,
                    'status' => 'active',
                    'total_items' => $items->count(),
                    'total_amount' => $totalAmount,
                ]);

                $stockDocument = StockDocument::create([
                    'document_id' => $saleDocument->id,
                    'total_qty' => $totalQty,
                    'total_items' => $items->count(),
                    'refer_reference' => $bookingDocument->doc_number,
                ]);

                $seq = 1;
                foreach ($items as $item) {
                    $productId = (int) $item->product_id;
                    $qty = $item->qty;
                    $product = $products->get($productId);
                    $balance = StockBalance::firstOrCreate(
                        ['product_id' => $productId, 'warehouse_location_id' => $item->warehouse_location_id],
                        ['on_hand_qty' => 0, 'reserved_qty' => 0]
                    );
                    $balance->decrement('reserved_qty', $qty);
                    $allocations = $this->fifo->issue(
                        $productId,
                        (int) $item->warehouse_location_id,
                        $qty,
                        $saleDocument->id,
                        allowNegative: $branch->allowsNegativeStock()
                            && $this->negativeStockPolicy->policyFor($product) === 'allow'
                            && ($product->negative_stock_policy ?? 'allow') === 'allow',
                    );
                    $unitCost = $this->costing->unitCostFromAllocations($allocations, $qty, $product->average_cost ?? 0);
                    StockDocumentItem::create([
                        'stock_document_id' => $stockDocument->id,
                        'seq' => $seq++,
                        'product_id' => $productId,
                        'warehouse_location_id' => $item->warehouse_location_id,
                        'qty' => $qty,
                        'unit_price' => $item->unit_price,
                        'unit_cost' => $unitCost,
                        'cost_amount' => DecimalMath::multiply($qty, $unitCost),
                        'vat_applicable' => $vatApplicableByItem[(int) $item->id],
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
                $created->push($saleDocument->fresh(['documentBook']));
            }

            $primary = $created->first(fn (Document $document) => ! $document->documentBook?->no_vat) ?? $created->first();
            $lockedBooking->update([
                'status' => SaleBooking::STATUS_CONVERTED,
                'confirmed_at' => now(),
                'confirmed_document_id' => $primary->id,
            ]);
            foreach ($created as $document) {
                DB::table('sale_booking_documents')->insert([
                    'sale_booking_id' => $lockedBooking->id,
                    'document_id' => $document->id,
                    'vat_group' => (bool) $document->documentBook?->no_vat ? 'no_vat' : 'taxable',
                ]);
            }

            return $created;
        });
    }
}
