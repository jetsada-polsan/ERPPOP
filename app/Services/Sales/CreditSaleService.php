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

    /**
     * แปลงทั้งใบตามราคาในใบจอง (ทางลัดเดิม) — เลือกเล่มรายบรรทัดได้ผ่าน $lineBooks [item_id => DS|DSN]
     *
     * @param  array<int|string, string>|null  $lineBooks
     */
    public function convertBookingToCreditSale(SaleBooking $booking, ?DocumentBook $book = null, ?array $lineBooks = null): Document
    {
        $items = $booking->document()->with('stockDocument.items.product')->firstOrFail()->stockDocument?->items ?? collect();
        if ($lineBooks !== null) {
            $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $chosenIds = collect(array_keys($lineBooks))->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($itemIds !== $chosenIds) {
                throw new RuntimeException('รายการที่เลือกเล่มไม่ตรงกับรายการในใบจอง กรุณาเปิดหน้าตรวจใหม่');
            }
        }
        $bills = [];
        foreach ($items->sortBy('id') as $item) {
            $remaining = DecimalMath::subtract($item->qty, $item->billed_qty ?? 0, DecimalMath::QUANTITY_SCALE);
            if (DecimalMath::compare($remaining, 0) <= 0) {
                continue;
            }
            $code = $lineBooks[$item->id] ?? ($item->product?->is_vat ? 'DS' : 'DSN');
            $bills[$code]['lines'][] = ['booking_item_id' => $item->id, 'qty' => $remaining, 'unit_price' => $item->unit_price];
        }

        return $this->billFromBooking($booking, $bills, null, allowBelowCost: true, fallbackBook: $book)->first();
    }

    /**
     * ออกใบขายเชื่อจากใบจองแบบแก้ได้ (เหมือน BPlus "อ่านจาก..." แล้วแก้ก่อนบันทึก)
     *
     * $bills = ['DS' => ['lines' => [['booking_item_id', 'qty', 'unit_price'], ...], 'credit_days' => 30, 'remark' => '...'], 'DSN' => [...]]
     * - จำนวนรวมต่อบรรทัดใบจองต้องไม่เกินยอดค้าง (qty - billed_qty)
     * - ราคาต่ำกว่าทุนบันทึกได้เฉพาะผู้มีสิทธิ์ sales.price_below_cost
     * - ยอดที่ไม่ได้ออกบิลยังค้างในใบจองและยังกันสต๊อกไว้
     *
     * @return \Illuminate\Support\Collection<int, Document>
     */
    public function billFromBooking(SaleBooking $booking, array $bills, ?\App\Models\User $user, bool $allowBelowCost = false, ?DocumentBook $fallbackBook = null)
    {
        $documentType = DocumentType::where('code', DocumentType::CREDIT_SALE)->firstOrFail();
        $allowBelowCost = $allowBelowCost || (bool) $user?->hasPermission('sales.price_below_cost');

        return DB::transaction(function () use ($booking, $bills, $documentType, $allowBelowCost, $fallbackBook) {
            $lockedBooking = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($lockedBooking->status !== SaleBooking::STATUS_PENDING) {
                throw new RuntimeException("ใบจองนี้ถูกใช้ไปแล้ว (สถานะปัจจุบัน: {$lockedBooking->status})");
            }
            if ($lockedBooking->stock_shortage_status === 'pending') {
                throw new RuntimeException('ใบจองนี้สต๊อกในระบบไม่พอ ต้องให้ผู้จัดการยืนยันใน ERP ก่อนออกใบขาย');
            }

            $bookingDocument = $lockedBooking->document()->with('stockDocument.items.product')->firstOrFail();
            $bookingItems = ($bookingDocument->stockDocument?->items ?? collect())->keyBy('id');
            if ($bookingItems->isEmpty()) {
                throw new RuntimeException('ใบจองนี้ไม่มีรายการสินค้า');
            }

            $saleDate = $lockedBooking->delivered_at?->toDateString() ?? now()->toDateString();
            $this->periods->assertOpen($saleDate, $bookingDocument->branch_id);

            // รวมจำนวนที่จะออกบิลต่อบรรทัดใบจอง แล้วตรวจไม่ให้เกินยอดค้าง
            $requested = [];
            $prepared = [];
            foreach ($bills as $code => $bill) {
                if (! in_array($code, ['DS', 'DSN'], true)) {
                    throw new RuntimeException('เลือกเล่มได้เฉพาะ DS หรือ DSN');
                }
                foreach ($bill['lines'] ?? [] as $line) {
                    $itemId = (int) ($line['booking_item_id'] ?? 0);
                    $source = $bookingItems->get($itemId);
                    if (! $source) {
                        throw new RuntimeException('มีรายการที่ไม่อยู่ในใบจองนี้ กรุณาเปิดหน้าออกบิลใหม่');
                    }
                    $qty = DecimalMath::of($line['qty'] ?? 0)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
                    if (DecimalMath::compare($qty, 0) <= 0) {
                        continue;
                    }
                    $price = DecimalMath::of($line['unit_price'] ?? 0)->toScale(4, \Brick\Math\RoundingMode::HalfUp);
                    if (DecimalMath::compare($price, 0) < 0) {
                        throw new RuntimeException('ราคาขายติดลบไม่ได้');
                    }
                    $cost = DecimalMath::of($source->product?->average_cost ?? 0);
                    if (! $allowBelowCost && DecimalMath::compare($price, $cost) < 0) {
                        throw new RuntimeException('ราคา '.($source->product?->name_th ?? '').' ต่ำกว่าทุน ต้องให้ผู้จัดการอนุมัติ (ผู้มีสิทธิ์อนุมัติราคาต่ำกว่าทุนเป็นผู้บันทึก)');
                    }
                    $requested[$itemId] = DecimalMath::add($requested[$itemId] ?? '0', $qty, DecimalMath::QUANTITY_SCALE);
                    $prepared[$code][] = (object) [
                        'booking_item_id' => $itemId,
                        'product_id' => $source->product_id,
                        'warehouse_location_id' => $source->warehouse_location_id,
                        'qty' => (string) $qty,
                        'unit_price' => (string) $price,
                    ];
                }
            }
            if ($prepared === []) {
                throw new RuntimeException('ยังไม่มีรายการที่จะออกบิล');
            }
            foreach ($requested as $itemId => $qty) {
                $source = $bookingItems->get($itemId);
                $remaining = DecimalMath::subtract($source->qty, $source->billed_qty ?? 0, DecimalMath::QUANTITY_SCALE);
                if (DecimalMath::compare($qty, $remaining) > 0) {
                    throw new RuntimeException('จำนวน '.($source->product?->name_th ?? '').' เกินยอดค้างในใบจอง (ค้าง '.(float) $remaining.') ถ้าลูกค้าเพิ่ม ให้แก้ใบจองก่อน');
                }
            }

            $sales = collect();
            foreach (['DS', 'DSN'] as $code) {
                if (empty($prepared[$code])) {
                    continue;
                }
                $noVat = $code === 'DSN';
                $groupBook = DocumentBook::where('document_type_id', $documentType->id)->where('is_active', true)->where('code', $code)->first()
                    ?? DocumentBook::where('document_type_id', $documentType->id)->where('is_active', true)
                        ->where('no_vat', $noVat)->orderByDesc('is_default')->orderBy('id')->first();
                if ($groupBook && (bool) $groupBook->no_vat !== $noVat) {
                    throw new RuntimeException("ตั้งค่าภาษีเล่ม {$code} ไม่ถูกต้อง");
                }
                if (! $groupBook && count($prepared) > 1) {
                    throw new RuntimeException("ยังไม่มีเล่มขายเชื่อ {$code} ที่เปิดใช้งาน");
                }
                $groupBook ??= $fallbackBook ?? DocumentBook::defaultFor(DocumentType::CREDIT_SALE);
                $header = $bills[$code] ?? [];
                $sales->push($this->createSale($lockedBooking, $bookingDocument, collect($prepared[$code]), $groupBook, $saleDate, $header));
            }

            foreach ($requested as $itemId => $qty) {
                $source = $bookingItems->get($itemId);
                $source->update(['billed_qty' => DecimalMath::add($source->billed_qty ?? 0, $qty, DecimalMath::QUANTITY_SCALE)]);
            }
            $fullyBilled = $bookingItems->every(fn ($item) => DecimalMath::compare($item->fresh()->billed_qty, $item->qty) >= 0);
            $lockedBooking->update(array_filter([
                'status' => $fullyBilled ? SaleBooking::STATUS_CONVERTED : SaleBooking::STATUS_PENDING,
                'confirmed_at' => now(),
                'confirmed_document_id' => $lockedBooking->confirmed_document_id ?? $sales->first()->id,
            ], fn ($v) => $v !== null));

            return $sales;
        });
    }

    /** ผู้จัดการปิดยอดค้างที่ลูกค้าไม่เอาแล้ว: คืนสต๊อกที่กันไว้ ใบจองจบ */
    public function closeRemainder(SaleBooking $booking, ?int $userId): void
    {
        DB::transaction(function () use ($booking, $userId) {
            $locked = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SaleBooking::STATUS_PENDING) {
                throw new RuntimeException('ปิดยอดค้างได้เฉพาะใบจองที่ยังเปิดอยู่');
            }
            $items = $locked->document()->with('stockDocument.items')->firstOrFail()->stockDocument?->items ?? collect();
            if ($items->every(fn ($i) => DecimalMath::compare($i->billed_qty ?? 0, 0) <= 0)) {
                throw new RuntimeException('ใบจองนี้ยังไม่ได้ออกบิลเลย ถ้าไม่เอาแล้วให้ยกเลิกใบจองแทน');
            }
            foreach ($items as $item) {
                $remaining = DecimalMath::subtract($item->qty, $item->billed_qty ?? 0, DecimalMath::QUANTITY_SCALE);
                if (DecimalMath::compare($remaining, 0) <= 0) {
                    continue;
                }
                $balance = StockBalance::where('product_id', $item->product_id)->where('warehouse_location_id', $item->warehouse_location_id)->lockForUpdate()->first();
                if ($balance) {
                    $balance->update(['reserved_qty' => max(0, (float) DecimalMath::subtract($balance->reserved_qty, $remaining, DecimalMath::QUANTITY_SCALE))]);
                }
            }
            $locked->update(['status' => SaleBooking::STATUS_CONVERTED, 'remainder_closed_at' => now(), 'remainder_closed_by' => $userId]);
            \App\Models\AuditLog::create(['user_id' => $userId, 'branch_id' => $locked->document->branch_id, 'action' => 'booking_remainder_closed',
                'table_name' => 'sale_bookings', 'record_id' => $locked->id]);
        });
    }

    /** ตรวจราคาต่ำกว่าทุนโดยไม่ส่งตัวเลขทุนออกไป: [product_id => bool] */
    public function belowCostFlags(array $prices): array
    {
        $products = Product::whereIn('id', array_map('intval', array_keys($prices)))->get(['id', 'average_cost'])->keyBy('id');
        $flags = [];
        foreach ($prices as $productId => $price) {
            $cost = $products->get((int) $productId)?->average_cost ?? 0;
            $flags[(int) $productId] = DecimalMath::compare(DecimalMath::of($price ?: 0), DecimalMath::of($cost)) < 0;
        }

        return $flags;
    }

    private function createSale(SaleBooking $booking, Document $bookingDocument, $sourceItems, ?DocumentBook $book, string $saleDate, array $header = []): Document
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
                'remark' => trim((string) ($header['remark'] ?? '')) ?: 'อ้างถึงใบจองสินค้า #'.$bookingDocument->doc_number,
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
                    ->addDays(isset($header['credit_days']) && $header['credit_days'] !== '' && $header['credit_days'] !== null
                        ? max(0, (int) $header['credit_days'])
                        : (int) (AppSetting::get('default_credit_days') ?: self::DEFAULT_CREDIT_DAYS))
                    ->toDateString(),
                'status' => CustomerOpenItem::STATUS_OPEN,
            ]);

            $this->glPosting->postCreditSale($saleDocument);

            return $saleDocument->fresh();
    }
}
