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
use App\Models\TransportLoadItem;
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
                    $product = Product::find($productId);
                    throw new RuntimeException(sprintf(
                        'สินค้า %s มีพร้อมจอง %s แต่ขอจอง %s (มีอยู่ %s, จองแล้ว %s)',
                        $product?->sku_code ?? $productId,
                        $available,
                        $requestedQty,
                        $onHand,
                        $reserved,
                    ));
                }
                $balances[$productId] = $balance;
            }

            $totalQty = $items->sum('qty');
            $totalAmount = $items->sum(fn ($i) => $i['qty'] * $i['unit_price']);

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
                'fulfillment_type' => $data['fulfillment_type'],
                'delivery_due_at' => $data['delivery_due_at'] ?? null,
                'delivery_status' => SaleBooking::DELIVERY_PENDING,
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
    public function update(SaleBooking $booking, array $data): Document
    {
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
            if (TransportLoadItem::whereIn('stock_document_item_id', $oldItems->pluck('id'))->exists()) {
                throw new RuntimeException('ใบจองนี้เริ่มจัดรายการขึ้นรถแล้ว จึงแก้ไขรายการสินค้าไม่ได้');
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
                    $product = Product::find($productId);
                    throw new RuntimeException(sprintf(
                        'สินค้า %s มีพร้อมจอง %s แต่ขอจอง %s',
                        $product?->sku_code ?? $productId,
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
}
