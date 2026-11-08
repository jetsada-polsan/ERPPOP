<?php

namespace App\Services\Sales;

use App\Models\AppSetting;
use App\Models\AuditLog;
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
use App\Models\TradeAgreement;
use App\Services\Accounting\AccountingPeriodGuard;
use App\Services\Accounting\GlPostingService;
use App\Services\Inventory\CostingService;
use App\Services\Inventory\FifoStockService;
use App\Services\Inventory\NegativeStockPolicyService;
use App\Support\DecimalMath;
use App\Support\TradeTax;
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

    /** Validate and summarize an explicit per-line credit-sale allocation without writing data. */
    public function previewBookingToCreditSales(SaleBooking $booking, array $assignments, array $agreementIds = []): array
    {
        if ($booking->status !== SaleBooking::STATUS_PENDING) {
            throw new RuntimeException('ใบจองนี้ถูกใช้ไปแล้ว');
        }
        $source = $booking->document()->with('documentBook', 'stockDocument.items.product')->firstOrFail();
        $items = $source->stockDocument?->items ?? collect();
        $books = DocumentBook::whereHas('documentType', fn ($q) => $q->where('code', DocumentType::CREDIT_SALE))
            ->where('is_active', true)->whereIn('id', array_values($assignments))->get()->keyBy('id');
        if (in_array(0, array_map('intval', array_values($assignments)), true) && $books->isEmpty()) {
            $hasConfiguredBook = DocumentBook::whereHas('documentType', fn ($q) => $q->where('code', DocumentType::CREDIT_SALE))
                ->where('is_active', true)->exists();
            if ($hasConfiguredBook) {
                throw new RuntimeException('กรุณาเลือกเล่มขายเชื่อที่เปิดใช้งาน');
            }
            $books->put(0, $this->unbookedCreditSaleBook());
        }
        $plan = app(BookingSplitPlan::class)->prepare($items, $assignments, $books);
        if ($source->documentBook?->no_vat && $items->contains(fn ($item) => $item->product?->is_vat)) {
            throw new RuntimeException('เล่มใบจองไม่คำนวณ VAT แต่มีสินค้าที่คิด VAT กรุณาตรวจสอบการตั้งค่า');
        }

        $sourceTotal = DecimalMath::sum($items->map(fn ($item) => DecimalMath::multiply($item->qty, $item->unit_price)));
        $saleDate = $booking->delivered_at?->toDateString() ?? now()->toDateString();
        $agreements = $source->customer?->tradeAgreements()->effectiveOn($saleDate)->get() ?? collect();
        $rate = TradeAgreement::standardRate($saleDate);

        foreach ($plan as &$group) {
            $book = $group['book'];
            $compatible = $agreements->filter(fn (TradeAgreement $agreement) => $agreement->fitsSaleBook($book));
            $selectedId = $agreementIds[$book->id] ?? null;
            $agreement = $selectedId ? $compatible->firstWhere('id', $selectedId) : $compatible->first();
            if ($selectedId && ! $agreement) {
                throw new RuntimeException('ข้อตกลงที่เลือกใช้ไม่ได้กับลูกค้า วันที่ หรือเล่ม '.$book->code);
            }

            $tax = $agreement?->taxSnapshot($rate) ?? [
                'agreement_id' => null,
                'agreement_name' => null,
                'vat_mode' => $book->no_vat ? 'none' : 'standard',
                'vat_rate' => $book->no_vat ? '0' : $rate,
                'prices_include_vat' => true,
                'payment_terms' => null,
                'credit_days' => null,
            ];
            $lines = $group['items']->mapWithKeys(function ($item) use ($tax) {
                $taxable = $tax['vat_mode'] !== 'none' && $item->product->is_vat;
                return [$item->id => TradeTax::line($item->qty, $item->unit_price, $tax['prices_include_vat'], $taxable ? $tax['vat_rate'] : '0')
                    + ['taxable' => (bool) $taxable]];
            });
            $group['total'] = DecimalMath::sum($lines->pluck('gross'));
            $subtotal = DecimalMath::sum($lines->pluck('base'), 2);
            $group['tax'] = $tax + [
                'taxable_base' => DecimalMath::subtract($subtotal, DecimalMath::sum($lines->where('taxable', false)->pluck('gross'), 2), 2),
                'exempt_amount' => DecimalMath::sum($lines->where('taxable', false)->pluck('gross'), 2),
                'exempt_product_ids' => $group['items']->filter(fn ($item) => ! $lines[$item->id]['taxable'])->pluck('product_id')->unique()->values()->all(),
                'vat_amount' => DecimalMath::subtract(DecimalMath::round($group['total'], 2), $subtotal, 2),
            ];
            $group['subtotal'] = $subtotal;
            $group['lines'] = $lines;
        }
        unset($group);

        $targetTotal = DecimalMath::sum(collect($plan)->map(fn ($group) => DecimalMath::round($group['total'], 2)), 2);
        $fingerprint = hash('sha256', serialize([$saleDate, $sourceTotal, collect($plan)->map(fn ($group) => [
            $group['book']->id, $group['book']->code, $group['tax'], $group['lines']->all(),
            $group['items']->map(fn ($item) => [$item->id, $item->product_id, $item->qty, $item->unit_price, $item->warehouse_location_id])->all(),
        ])->all()]));

        return ['source_total' => $sourceTotal, 'target_total' => $targetTotal, 'groups' => $plan, 'fingerprint' => $fingerprint];
    }

    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly GlPostingService $glPosting,
        private readonly FifoStockService $fifo,
        private readonly CostingService $costing,
        private readonly AccountingPeriodGuard $periods,
        private readonly NegativeStockPolicyService $negativeStockPolicy,
    ) {}

    /** @return \Illuminate\Support\Collection<int, Document> */
    public function splitBookingToCreditSales(SaleBooking $booking, array $assignments, array $agreementIds = [], ?string $expectedFingerprint = null): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($booking, $assignments, $agreementIds, $expectedFingerprint) {
            $locked = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SaleBooking::STATUS_PENDING) {
                throw new RuntimeException('ใบจองนี้ถูกใช้ไปแล้ว');
            }
            $source = $locked->document()->firstOrFail();
            $summary = $this->previewBookingToCreditSales($locked, $assignments, $agreementIds);
            if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $summary['fingerprint'])) {
                throw new RuntimeException('ข้อมูลใบจองหรือข้อตกลงเปลี่ยน กรุณากลับไปดูตัวอย่างใหม่');
            }
            $plan = $summary['groups'];
            $sales = collect();
            foreach ($plan as $group) {
                $sales->push($this->createSaleForItems($locked, $source, $group));
            }
            $locked->update([
                'status' => SaleBooking::STATUS_CONVERTED,
                'confirmed_at' => now(),
                'confirmed_document_id' => $sales->first()->id,
            ]);
            AuditLog::create([
                'user_id' => auth()->id(),
                'branch_id' => $source->branch_id,
                'action' => 'booking_converted_split',
                'table_name' => 'sale_bookings',
                'record_id' => $locked->id,
                'old_values' => ['status' => SaleBooking::STATUS_PENDING],
                'new_values' => [
                    'status' => SaleBooking::STATUS_CONVERTED,
                    'source_document_id' => $source->id,
                    'source_total' => $summary['source_total'],
                    'target_total' => $summary['target_total'],
                    'sales' => $sales->map(fn (Document $sale) => [
                        'document_id' => $sale->id,
                        'doc_number' => $sale->doc_number,
                        'document_book_id' => $sale->document_book_id,
                        'total_amount' => $sale->total_amount,
                        'vat_amount' => $sale->vat_amount,
                    ])->all(),
                ],
            ]);
            return $sales;
        });
    }

    private function createSaleForItems(SaleBooking $lockedBooking, Document $bookingDocument, array $group): Document
    {
        $documentType = DocumentType::where('code', DocumentType::CREDIT_SALE)->firstOrFail();
        $saleDate = $lockedBooking->delivered_at?->toDateString() ?? now()->toDateString();
        $this->periods->assertOpen($saleDate, $bookingDocument->branch_id);
        $book = $group['book'];
        $sourceItems = $group['items'];
        $totalQty = DecimalMath::sum($sourceItems->pluck('qty'), DecimalMath::QUANTITY_SCALE);
        $totalAmount = $group['total'];
        $tax = $group['tax'];

        $saleDocument = Document::create([
            'document_type_id' => $documentType->id,
            'document_book_id' => $book->exists ? $book->id : null,
            'branch_id' => $bookingDocument->branch_id,
            'doc_number' => $book->exists
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
            'subtotal_amount' => $group['subtotal'],
            'vat_amount' => $tax['vat_amount'],
            'trade_tax' => $tax,
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
                allowNegative: $branch->allowsNegativeStock()
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
                'unit_price' => $group['lines'][$item->id]['gross_unit'],
                'vat_amount' => $group['lines'][$item->id]['vat'],
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
            'vat_amount' => $tax['vat_amount'],
            'net_amount' => $totalAmount,
            'balance_amount' => $totalAmount,
            'due_date' => Carbon::parse($saleDate)
                ->addDays($tax['credit_days'] ?? (int) (AppSetting::get('default_credit_days') ?: self::DEFAULT_CREDIT_DAYS))
                ->toDateString(),
            'status' => CustomerOpenItem::STATUS_OPEN,
        ]);

        $this->glPosting->postCreditSale($saleDocument);
        return $saleDocument->fresh();
    }

    private function unbookedCreditSaleBook(): DocumentBook
    {
        $book = new DocumentBook;
        $book->id = 0;
        $book->code = 'AUTO';
        $book->is_active = true;
        $book->no_vat = false;

        return $book;
    }

    public function convertBookingToCreditSale(SaleBooking $booking, ?DocumentBook $book = null): Document
    {
        $documentType = DocumentType::where('code', DocumentType::CREDIT_SALE)->firstOrFail();
        $book ??= DocumentBook::defaultFor(DocumentType::CREDIT_SALE);

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

            // คอนเฟิร์มบิลตอนบ่าย ระหว่างรถยังส่งของอยู่ได้ ตัดสต๊อกและตั้งลูกหนี้ตอนนี้
            $saleDate = $lockedBooking->delivered_at?->toDateString() ?? now()->toDateString();
            $this->periods->assertOpen($saleDate, $bookingDocument->branch_id);

            $totalQty = DecimalMath::sum($sourceItems->pluck('qty'), DecimalMath::QUANTITY_SCALE);
            $totalAmount = DecimalMath::sum(
                $sourceItems->map(fn ($item) => DecimalMath::multiply($item->qty, $item->unit_price)),
            );

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
                    allowNegative: $branch->allowsNegativeStock()
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
