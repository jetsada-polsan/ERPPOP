<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use RuntimeException;

class FifoStockService
{
    public function receive(int $productId, int $locationId, float $qty, ?int $documentId, string $movementType = 'in', ?string $lotNumber = null, ?string $receivedDate = null, ?string $expiryDate = null, float $unitCost = 0, ?string $manufactureDate = null): StockLot
    {
        $date = $receivedDate ?: now()->toDateString();
        $lot = StockLot::create([
            'product_id' => $productId,
            'warehouse_location_id' => $locationId,
            'source_document_id' => $documentId,
            'lot_number' => $lotNumber ?: ($documentId ? 'DOC-'.$documentId : 'LOT-'.now()->format('YmdHis')),
            'received_date' => $date,
            'manufacture_date' => $manufactureDate,
            'expiry_date' => $expiryDate,
            'initial_qty' => $qty,
            'remaining_qty' => $qty,
            'unit_cost' => $unitCost,
        ]);

        $this->movement($productId, $locationId, $documentId, $lot->id, $movementType, $qty, $date);
        $this->balance($productId, $locationId)->increment('on_hand_qty', $qty);

        return $lot;
    }

    /** @return Collection<int, array{lot:StockLot,qty:float}> */
    public function issue(int $productId, int $locationId, float $qty, ?int $documentId, string $movementType = 'out', ?string $movementDate = null, bool $allowNegative = false, bool $allowExpired = false, bool $allowRestricted = false): Collection
    {
        $balance = $this->balance($productId, $locationId);
        $available = (float) $balance->on_hand_qty;
        if (! $allowNegative && $qty > $available + 0.0001) {
            throw new RuntimeException('สต๊อกไม่พอสำหรับการตัดสินค้า');
        }

        $this->ensureOpeningLot($productId, $locationId, max(0, $available));
        $product = Product::find($productId);
        $blockExpired = (bool) $product?->tracks_expiry
            && ($product->expiry_sale_policy ?? 'block') === 'block'
            && ! $allowExpired;

        $lotsQuery = StockLot::where('product_id', $productId)
            ->where('warehouse_location_id', $locationId)
            ->where('remaining_qty', '>', 0);
        if (! $allowRestricted) {
            $lotsQuery->where('quality_status', 'available');
            $usable = (float) (clone $lotsQuery)->sum('remaining_qty');
            $restricted = (float) StockLot::where('product_id', $productId)
                ->where('warehouse_location_id', $locationId)
                ->where('remaining_qty', '>', 0)->where('quality_status', '!=', 'available')
                ->sum('remaining_qty');
            if ($restricted > 0.0001 && $qty > $usable + 0.0001) {
                throw new RuntimeException('สต๊อกที่ใช้ได้ไม่พอ เนื่องจากมี Lot ถูกพักตรวจ กักกัน หรือเรียกคืน');
            }
        }
        if ($blockExpired) {
            $lotsQuery->where(fn ($query) => $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()));
            $sellable = (float) (clone $lotsQuery)->sum('remaining_qty');
            $expired = (float) StockLot::where('product_id', $productId)
                ->where('warehouse_location_id', $locationId)
                ->where('remaining_qty', '>', 0)->whereDate('expiry_date', '<', now()->toDateString())
                ->sum('remaining_qty');
            if ($expired > 0.0001 && $qty > $sellable + 0.0001) {
                throw new RuntimeException('สต๊อกที่ขายได้ไม่พอ เนื่องจากมี Lot หมดอายุถูกระงับ กรุณาตัดเป็นสินค้าชำรุด');
            }
        }

        $remaining = $qty;
        $allocations = collect();
        if ($product?->tracks_expiry) {
            $lotsQuery->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date');
        }
        $lots = $lotsQuery->orderBy('received_date')->orderBy('id')->lockForUpdate()->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0.0001) {
                break;
            }
            $take = min($remaining, (float) $lot->remaining_qty);
            $lot->decrement('remaining_qty', $take);
            $this->movement($productId, $locationId, $documentId, $lot->id, $movementType, $take, $movementDate ?: now()->toDateString());
            $allocations->push(['lot' => $lot, 'qty' => $take]);
            $remaining -= $take;
        }

        if ($remaining > 0.0001) {
            if (! $allowNegative) {
                throw new RuntimeException('ยอดคงเหลือใน Lot ไม่พอ กรุณาตรวจสอบข้อมูลสต๊อก');
            }
            // ส่วนที่เกินสต๊อกไม่มี lot ต้นทาง แต่ต้องมี movement เพื่อ audit และกระทบยอดภายหลัง
            $this->movement($productId, $locationId, $documentId, null, $movementType, $remaining, $movementDate ?: now()->toDateString());
        }
        $balance->decrement('on_hand_qty', $qty);

        return $allocations;
    }

    public function restoreDocumentIssues(int $documentId, string $movementType = 'void_in', ?string $movementDate = null): void
    {
        $issues = StockMovement::query()
            ->where('document_id', $documentId)
            ->whereIn('movement_type', ['out', 'transfer_out', 'transform_out', 'adjust_out'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($issues as $issue) {
            $qty = (float) $issue->qty;
            if ($issue->stock_lot_id) {
                $lot = StockLot::whereKey($issue->stock_lot_id)->lockForUpdate()->first();
                if (! $lot) {
                    throw new RuntimeException('ไม่พบ Lot ต้นทางของเอกสาร กรุณาตรวจสอบสต๊อกก่อนยกเลิก');
                }
                $lot->increment('remaining_qty', $qty);
            }

            $this->balance($issue->product_id, $issue->warehouse_location_id)->increment('on_hand_qty', $qty);
            $this->movement(
                $issue->product_id,
                $issue->warehouse_location_id,
                $documentId,
                $issue->stock_lot_id,
                $movementType,
                $qty,
                $movementDate ?: now()->toDateString(),
            );
        }
    }

    private function ensureOpeningLot(int $productId, int $locationId, float $balanceQty): void
    {
        $lotQty = (float) StockLot::where('product_id', $productId)
            ->where('warehouse_location_id', $locationId)->sum('remaining_qty');
        $missing = round($balanceQty - $lotQty, 4);
        if ($missing > 0.0001) {
            StockLot::create([
                'product_id' => $productId,
                'warehouse_location_id' => $locationId,
                'lot_number' => 'OPENING-'.$productId.'-'.$locationId,
                'received_date' => '1900-01-01',
                'initial_qty' => $missing,
                'remaining_qty' => $missing,
                'unit_cost' => 0,
            ]);
        }
    }

    private function balance(int $productId, int $locationId): StockBalance
    {
        return StockBalance::firstOrCreate(
            ['product_id' => $productId, 'warehouse_location_id' => $locationId],
            ['on_hand_qty' => 0, 'reserved_qty' => 0]
        );
    }

    private function movement(int $productId, int $locationId, ?int $documentId, ?int $lotId, string $type, float $qty, string $date): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'warehouse_location_id' => $locationId,
            'document_id' => $documentId,
            'stock_lot_id' => $lotId,
            'movement_type' => $type,
            'qty' => $qty,
            'movement_date' => $date,
        ]);
    }
}
