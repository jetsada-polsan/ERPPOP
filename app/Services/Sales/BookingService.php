<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\SaleBooking;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\TransportJob;
use App\Support\DecimalMath;
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
     * @param  array{customer_id:int, branch_id:int, sales_area_id?:?int, sales_user_id:?int, salesman_id:?int, document_book_id?:?int, remark:?string, items: array<int, array{product_id:int, qty:float, unit_price:float}>}  $data
     */
    public function create(array $data): Document
    {
        if (empty($data['items'])) {
            throw new RuntimeException('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
        }

        // ใบจองแบบส่งของต้องรู้กำหนดส่งตั้งแต่ต้น ไม่งั้นรายงานเกินกำหนดส่งไม่มีอะไรให้เทียบ
        $data['fulfillment_type'] = $data['fulfillment_type'] ?? SaleBooking::FULFILLMENT_PICKUP;
        if ($data['fulfillment_type'] === SaleBooking::FULFILLMENT_DELIVERY && blank($data['delivery_due_at'] ?? null)) {
            throw new RuntimeException('ใบจองแบบส่งของต้องระบุวันและเวลาที่ต้องส่ง');
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
            $requestedByProduct = $items->groupBy('product_id')->map(
                fn ($rows) => DecimalMath::sum($rows->pluck('qty'), DecimalMath::QUANTITY_SCALE)
            );
            $balances = [];
            $shortages = [];

            // Lock each balance before reserving so concurrent bookings cannot
            // consume the same available quantity. Duplicate rows are checked as
            // one product total, matching the reservations written below.
            foreach ($requestedByProduct as $productId => $requestedQty) {
                $balance = StockBalance::where('product_id', $productId)
                    ->where('warehouse_location_id', $branch->default_warehouse_location_id)
                    ->lockForUpdate()
                    ->first();
                $onHand = $balance?->on_hand_qty ?? 0;
                $reserved = $balance?->reserved_qty ?? 0;
                $availableRaw = DecimalMath::subtract($onHand, $reserved, DecimalMath::QUANTITY_SCALE);
                $available = DecimalMath::compare($availableRaw, 0) < 0 ? '0' : $availableRaw;
                if (DecimalMath::compare($requestedQty, $available) > 0) {
                    // ของจริงมีแต่ระบบไม่พอ: บันทึกได้ถ้าผู้ขายขอ แต่ต้องรอผู้จัดการยืนยันก่อนออกใบขาย
                    if (! empty($data['allow_stock_shortage'])) {
                        $product = Product::find($productId);
                        $shortages[] = ($product?->name_th ?? "#{$productId}")." ขาด ".DecimalMath::subtract($requestedQty, $available, DecimalMath::QUANTITY_SCALE);
                        $balances[$productId] = $balance;
                        continue;
                    }
                    throw new RuntimeException($this->availabilityError(
                        Product::with('baseUnit')->find($productId),
                        $productId,
                        $available,
                        $requestedQty,
                        $onHand,
                        $reserved,
                    ));
                }
                $balances[$productId] = $balance;
            }

            $totalQty = DecimalMath::sum($items->pluck('qty'), DecimalMath::QUANTITY_SCALE);
            $totalAmount = DecimalMath::sum($items->map(fn ($i) => DecimalMath::multiply($i['qty'], $i['unit_price'])));

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'document_book_id' => $documentBook?->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->nextBooking($branch->id, null, $data['sales_area_id'] ?? null),
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
                'fulfillment_type' => $data['fulfillment_type'],
                'delivery_due_at' => $data['delivery_due_at'] ?? null,
                'delivery_status' => SaleBooking::DELIVERY_PENDING,
                'stock_shortage_status' => $shortages ? 'pending' : null,
                'stock_shortage_note' => $shortages ? implode("\n", $shortages) : null,
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

                $balance = $balances[$item['product_id']] ?? null;
                if (! $balance) {
                    $balance = StockBalance::create([
                        'product_id' => $item['product_id'],
                        'warehouse_location_id' => $branch->default_warehouse_location_id,
                        'on_hand_qty' => 0,
                        'reserved_qty' => 0,
                    ]);
                    $balances[$item['product_id']] = $balance;
                }
                $balance->increment('reserved_qty', $item['qty']);
            }

            return $document->fresh();
        });
    }

    /**
     * Edit a pending booking without changing its document number or recording
     * stock movements. The reservation is moved atomically with the document.
     */
    /** ยกเลิกใบจองที่ยังไม่ออกบิล คืนยอดจองให้สต๊อก ไม่แตะบัญชีเพราะใบจองไม่ลงบัญชี */
    /**
     * ถอย Confirm (ผู้จัดการ): ยกเลิกใบขาย DS/DSN ทุกใบที่ออกจากใบจองนี้ด้วย void เดิม
     * (คืนสต๊อก ปิดลูกหนี้ กลับ GL) แล้วใบจองกลับไปกันของไว้เหมือนก่อน Confirm
     */
    /**
     * ใครแก้ใบขายที่ออกแล้วได้ (ยกเลิกใบขายแล้วกลับไปแก้ใบจอง)
     *
     * - ผู้ที่มีสิทธิ์ยกเลิกเอกสาร (ผู้จัดการ/แอดมิน) แก้ได้ทุกใบในสาขาที่เข้าถึง
     * - คนขายแก้ได้เฉพาะใบจอง/ใบขายที่ตัวเองเป็นเจ้าของ และต้องมีสิทธิ์จัดการการขาย
     * กติกาที่เหลือ (รับชำระแล้ว อยู่ในใบวางบิล มีใบลดหนี้อ้างอิง งวดปิดแล้ว) ยังบังคับที่
     * DocumentVoidService เหมือนเดิม คนขายผ่านไม่ได้ถ้าติดกติกาเหล่านั้น
     */
    public function canRevertConfirmation(?\App\Models\User $user, SaleBooking $booking): bool
    {
        if (! $user || ! $booking->document || ! $user->canAccessBranch((int) $booking->document->branch_id)) {
            return false;
        }
        if ($user->hasPermission('documents.void')) {
            return true;
        }
        if (! $user->hasPermission('sales.manage')) {
            return false;
        }

        $doc = $booking->document;
        // เจ้าของใบ = ผู้สร้าง หรือคนขายที่ถูกมอบหมายให้ดูแลใบนี้ (sales_user_id) หรือคนขายตามรหัสพนักงานขายของใบจอง
        $ownsDocument = ($doc->created_by !== null && (int) $doc->created_by === (int) $user->id)
            || ($doc->sales_user_id !== null && (int) $doc->sales_user_id === (int) $user->id);
        $isBookingSalesman = $booking->salesman?->user_id !== null && (int) $booking->salesman->user_id === (int) $user->id;

        return $ownsDocument || $isBookingSalesman;
    }

    public function revertConfirmation(SaleBooking $booking, string $reason, ?int $userId): void
    {
        DB::transaction(function () use ($booking, $reason, $userId) {
            $locked = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $sales = $locked->saleDocuments()->where('status', 'active')->get();
            if ($sales->isEmpty() || ! in_array($locked->status, [SaleBooking::STATUS_CONVERTED, SaleBooking::STATUS_PENDING], true)) {
                throw new RuntimeException('ถอยได้เฉพาะใบจองที่ Confirm ออกใบขายแล้ว');
            }
            $wasClosed = $locked->remainder_closed_at !== null;
            $oldStatus = $locked->status;
            foreach ($sales as $sale) {
                app(\App\Services\Accounting\DocumentVoidService::class)->void($sale, 'ถอย Confirm ใบจอง: '.$reason, $userId);
            }

            $items = $locked->document()->with('stockDocument.items')->firstOrFail()->stockDocument?->items ?? collect();
            foreach ($items as $item) {
                // ส่วนที่ยังค้าง (ไม่ได้ออกบิล) ยังกันสต๊อกอยู่แล้ว ยกเว้นผู้จัดการปิดยอดค้างไปแล้ว
                $reReserve = $wasClosed ? $item->qty : ($item->billed_qty ?? 0);
                if (DecimalMath::compare($reReserve, 0) > 0) {
                    $balance = StockBalance::where('product_id', $item->product_id)
                        ->where('warehouse_location_id', $item->warehouse_location_id)->lockForUpdate()->first()
                        ?? StockBalance::create(['product_id' => $item->product_id, 'warehouse_location_id' => $item->warehouse_location_id, 'on_hand_qty' => 0, 'reserved_qty' => 0]);
                    $balance->increment('reserved_qty', $reReserve);
                }
                $item->update(['billed_qty' => 0]);
            }

            $locked->update(['status' => SaleBooking::STATUS_PENDING, 'confirmed_at' => null, 'confirmed_document_id' => null,
                'remainder_closed_at' => null, 'remainder_closed_by' => null]);
            DB::table('audit_logs')->insert([
                'user_id' => $userId, 'branch_id' => $locked->document->branch_id, 'action' => 'revert_confirm',
                'table_name' => 'sale_bookings', 'record_id' => $locked->id,
                'old_values' => json_encode(['status' => $oldStatus, 'sales' => $sales->pluck('doc_number')], JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode(['status' => SaleBooking::STATUS_PENDING, 'reason' => $reason], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });
    }

    public function cancel(SaleBooking $booking, ?string $reason, ?int $userId): void
    {
        DB::transaction(function () use ($booking, $reason, $userId) {
            $locked = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SaleBooking::STATUS_PENDING) {
                throw new RuntimeException('ยกเลิกได้เฉพาะใบจองที่ยังไม่ออกบิล');
            }
            if (($locked->document->stockDocument?->items ?? collect())->contains(fn ($i) => DecimalMath::compare($i->billed_qty ?? 0, 0) > 0)) {
                throw new RuntimeException('ใบจองนี้ออกบิลไปบางส่วนแล้ว ยกเลิกไม่ได้ ให้ผู้จัดการปิดยอดค้าง หรือถอย Confirm');
            }
            foreach ($locked->document->stockDocument?->items ?? [] as $item) {
                $balance = StockBalance::where('product_id', $item->product_id)
                    ->where('warehouse_location_id', $item->warehouse_location_id)->lockForUpdate()->first();
                if ($balance) {
                    $balance->update(['reserved_qty' => max(0, (float) DecimalMath::subtract($balance->reserved_qty, $item->qty, DecimalMath::QUANTITY_SCALE))]);
                }
            }
            $locked->update(['status' => SaleBooking::STATUS_CANCELLED]);
            $locked->document->update(['status' => 'cancelled']);
            TransportJob::where('booking_id', $locked->id)->update(['status' => 'cancelled']);
            \App\Models\AuditLog::create(['user_id' => $userId, 'branch_id' => $locked->document->branch_id, 'action' => 'booking_cancelled',
                'table_name' => 'sale_bookings', 'record_id' => $locked->id, 'new_values' => ['reason' => $reason]]);
        });
    }

    public function update(SaleBooking $booking, array $data): Document
    {
        if (($booking->document->stockDocument?->items ?? collect())->contains(fn ($i) => DecimalMath::compare($i->billed_qty ?? 0, 0) > 0)) {
            throw new RuntimeException('ใบจองนี้ออกบิลไปบางส่วนแล้ว แก้ไขไม่ได้ ให้ผู้จัดการถอย Confirm ก่อน');
        }
        if (empty($data['items'])) {
            throw new RuntimeException('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $data['fulfillment_type'] = $data['fulfillment_type'] ?? SaleBooking::FULFILLMENT_PICKUP;
        if ($data['fulfillment_type'] === SaleBooking::FULFILLMENT_DELIVERY && blank($data['delivery_due_at'] ?? null)) {
            throw new RuntimeException('ใบจองแบบส่งของต้องระบุวันและเวลาที่ต้องส่ง');
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

        return DB::transaction(function () use ($booking, $data, $branch, $documentBook) {
            $lockedBooking = SaleBooking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($lockedBooking->status !== SaleBooking::STATUS_PENDING
                || $lockedBooking->delivery_status !== SaleBooking::DELIVERY_PENDING) {
                throw new RuntimeException('แก้ไขได้เฉพาะใบจองที่ยังไม่แปลงเป็นใบขายและยังไม่มีการส่งสินค้า');
            }

            $document = $lockedBooking->document()->lockForUpdate()->firstOrFail();
            $stockDocument = StockDocument::where('document_id', $document->id)->lockForUpdate()->firstOrFail();
            $oldItems = $stockDocument->items()->orderBy('id')->lockForUpdate()->get();
            if ($oldItems->isEmpty()) {
                throw new RuntimeException('ไม่พบรายการสินค้าเดิมของใบจองนี้');
            }
            $transportJob = TransportJob::where('booking_id', $lockedBooking->id)->lockForUpdate()->first();
            // ขึ้นรถแล้วแต่ยังไม่ออกส่ง: คนขายแก้ได้เลย แล้วรถต้องตรวจขึ้นของใหม่ตามใบจองล่าสุด
            if ($transportJob && ! in_array($transportJob->status, ['booked', 'loaded'], true)) {
                throw new RuntimeException('ใบจองนี้ออกส่งแล้ว จึงแก้ไขรายการสินค้าไม่ได้');
            }
            if ($transportJob && ($transportJob->status === 'loaded' || $transportJob->loaded_at !== null)) {
                $transportJob->update(['status' => 'booked', 'loaded_at' => null]);
            }

            $newItems = collect($data['items']);
            $newByProduct = $newItems->groupBy('product_id')->map(
                fn ($rows) => DecimalMath::sum($rows->pluck('qty'), DecimalMath::QUANTITY_SCALE)
            );
            $oldByBalance = $oldItems->groupBy(fn ($item) => $item->product_id.':'.$item->warehouse_location_id)
                ->map(fn ($rows) => DecimalMath::sum($rows->pluck('qty'), DecimalMath::QUANTITY_SCALE));
            $newLocationId = (int) $branch->default_warehouse_location_id;
            $newByBalance = $newByProduct->mapWithKeys(fn ($qty, $productId) => [
                $productId.':'.$newLocationId => $qty,
            ]);
            $balanceKeys = $oldByBalance->keys()->merge($newByBalance->keys())->unique()->values();

            $balanceQuery = StockBalance::query()->where(function ($query) use ($balanceKeys) {
                foreach ($balanceKeys as $key) {
                    [$productId, $locationId] = explode(':', $key, 2);
                    $query->orWhere(fn ($pair) => $pair
                        ->where('product_id', $productId)
                        ->where('warehouse_location_id', $locationId));
                }
            });
            $balances = $balanceQuery->orderBy('id')->lockForUpdate()->get()
                ->keyBy(fn ($balance) => $balance->product_id.':'.$balance->warehouse_location_id);

            foreach ($balanceKeys as $key) {
                $balance = $balances->get($key);
                if (! $balance) {
                    throw new RuntimeException('ไม่พบยอดสต๊อกที่ผูกกับรายการจองเดิมหรือสาขาที่เลือก');
                }

                $reservedAfterRelease = DecimalMath::subtract(
                    $balance->reserved_qty,
                    $oldByBalance->get($key, '0'),
                    DecimalMath::QUANTITY_SCALE,
                );
                if (DecimalMath::compare($reservedAfterRelease, 0) < 0) {
                    throw new RuntimeException('ยอดจองสต๊อกไม่ตรงกับใบจองเดิม กรุณาตรวจสอบยอดสต๊อกก่อนแก้ไข');
                }

                $available = DecimalMath::subtract($balance->on_hand_qty, $reservedAfterRelease, DecimalMath::QUANTITY_SCALE);
                if (DecimalMath::compare($available, 0) < 0) {
                    $available = '0';
                }
                $requested = $newByBalance->get($key, '0');
                if (DecimalMath::compare($requested, $available) > 0) {
                    $productId = explode(':', $key, 2)[0];
                    throw new RuntimeException($this->availabilityError(
                        Product::with('baseUnit')->find($productId),
                        $productId,
                        $available,
                        $requested,
                    ));
                }
            }

            foreach ($balanceKeys as $key) {
                $balance = $balances->get($key);
                $released = $oldByBalance->get($key, '0');
                $requested = $newByBalance->get($key, '0');
                $balance->update([
                    'reserved_qty' => DecimalMath::add(
                        DecimalMath::subtract($balance->reserved_qty, $released, DecimalMath::QUANTITY_SCALE),
                        $requested,
                        DecimalMath::QUANTITY_SCALE,
                    ),
                ]);
            }

            $totalQty = DecimalMath::sum($newItems->pluck('qty'), DecimalMath::QUANTITY_SCALE);
            $totalAmount = DecimalMath::sum($newItems->map(
                fn ($item) => DecimalMath::multiply($item['qty'], $item['unit_price'])
            ));

            $document->update([
                'document_book_id' => $documentBook?->id,
                'branch_id' => $branch->id,
                'salesman_id' => $data['salesman_id'] ?? null,
                'sales_user_id' => $data['sales_user_id'] ?? null,
                'sales_area_id' => $data['sales_area_id'] ?? null,
                'customer_id' => $data['customer_id'],
                'total_items' => $newItems->count(),
                'total_amount' => $totalAmount,
                'remark' => $data['remark'] ?? null,
            ]);
            $lockedBooking->update([
                'salesman_id' => $data['salesman_id'] ?? null,
                'sales_user_id' => $data['sales_user_id'] ?? null,
                'sales_area_id' => $data['sales_area_id'] ?? null,
                'fulfillment_type' => $data['fulfillment_type'],
                'delivery_due_at' => $data['delivery_due_at'] ?? null,
            ]);
            $stockDocument->update([
                'total_qty' => $totalQty,
                'total_items' => $newItems->count(),
            ]);
            $stockDocument->items()->delete();
            foreach ($newItems->values() as $index => $item) {
                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $index + 1,
                    'product_id' => $item['product_id'],
                    'warehouse_location_id' => $newLocationId,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                ]);
            }

            return $document->fresh();
        });
    }

    private function availabilityError(
        ?Product $product,
        int|string $productId,
        mixed $available,
        mixed $requested,
        mixed $onHand = null,
        mixed $reserved = null,
    ): string {
        $unit = $product?->baseUnit?->cleanName() ?: 'หน่วย';
        $isWeight = $this->isWeightProduct($product, $unit);
        $message = sprintf(
            'สินค้า %s พร้อมจอง %s %s แต่ขอจอง %s %s',
            $product?->sku_code ?? $productId,
            $this->formatQuantity($available, $isWeight),
            $unit,
            $this->formatQuantity($requested, $isWeight),
            $unit,
        );

        if ($onHand === null || $reserved === null) {
            return $message;
        }

        return sprintf(
            '%s (มีอยู่ %s %s, จองแล้ว %s %s)',
            $message,
            $this->formatQuantity($onHand, $isWeight),
            $unit,
            $this->formatQuantity($reserved, $isWeight),
            $unit,
        );
    }

    private function isWeightProduct(?Product $product, string $unit): bool
    {
        return preg_match('/กก\.?|กิโล|kg|กรัม|gram|ลิตร|liter|ml/i', $unit) === 1
            || preg_match('/ชั่ง|ซั่ง/u', (string) $product?->name_th) === 1
            || $product?->barcodes->contains(
                fn ($barcode) => preg_match('/^80[01][0-9]{3}$/', (string) $barcode->barcode) === 1,
            );
    }

    private function formatQuantity(mixed $quantity, bool $isWeight): string
    {
        $value = (float) $quantity;
        if ($isWeight) {
            return number_format($value, 2, '.', '');
        }

        if (abs($value - round($value)) < 0.00000001) {
            return number_format($value, 0, '.', '');
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
