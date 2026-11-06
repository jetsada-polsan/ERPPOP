<?php

namespace App\Services\Inventory;

use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Models\Product;
use App\Models\PurchaseCostAdjustment;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Services\Accounting\AccountingPeriodGuard;
use App\Services\Accounting\GlPostingService;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Valuation-only correction: inventory/COGS against cost-variance clearing.
 * Supplier price changes require a separate approved supplier debit/credit note.
 * Original purchase, sold FIFO snapshots and tax are immutable.
 */
class PurchaseCostAdjustmentService
{
    public function __construct(private readonly InventoryCostCloseGuard $costCloseGuard) {}

    /**
     * จับคู่รายการในใบซื้อกับ Lot ที่เกิดจากใบซื้อนั้นแบบตำแหน่งต่อตำแหน่ง (seq ตรงกับ
     * ลำดับที่ FifoStockService::receive() ถูกเรียกตอนสร้างใบซื้อใน PurchaseService)
     * เพราะ stock_document_items ไม่ได้เก็บ stock_lot_id ย้อนกลับไว้โดยตรง
     *
     * @return Collection<int, array{item: object, lot: StockLot}>
     */
    public function preview(Document $purchase): Collection
    {
        $this->assertPurchase($purchase);

        $items = $purchase->stockDocument->items()->orderBy('seq')->with('product')->get();
        $lots = StockLot::where('source_document_id', $purchase->id)->orderBy('id')->get();

        if ($items->count() !== $lots->count()) {
            throw new RuntimeException('จำนวนรายการในใบซื้อกับ Lot สต๊อกไม่ตรงกัน (ข้อมูลอาจถูกแก้ไขนอกระบบ) กรุณาตรวจสอบด้วยตนเองก่อนปรับต้นทุน');
        }

        return $items->values()->map(function ($item, int $i) use ($lots) {
            $lot = $lots[$i];
            $remaining = DecimalMath::sum(StockLot::whereIn('id', $this->familyIds($lot))->pluck('remaining_qty'));

            return ['item' => $item, 'lot' => $lot, 'remaining_qty' => $remaining];
        });
    }

    /**
     * @param  array<int, array{stock_lot_id:int, new_unit_cost: float|string}>  $rows
     * @return Collection<int, PurchaseCostAdjustment>
     */
    public function adjust(Document $purchase, array $rows, string $reason, ?int $userId): Collection
    {
        $this->assertPurchase($purchase);

        $validLotIds = StockLot::where('source_document_id', $purchase->id)->pluck('id');

        return DB::transaction(function () use ($purchase, $rows, $reason, $userId, $validLotIds): Collection {
            $purchase = Document::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status !== 'active' || ! filled($reason)) {
                throw new RuntimeException('ต้องเป็นใบซื้อที่ใช้งานและมีเหตุผลปรับต้นทุน');
            }
            if (count(array_unique(array_column($rows, 'stock_lot_id'))) !== count($rows)) {
                throw new RuntimeException('ห้ามระบุ Lot ซ้ำ');
            }
            app(AccountingPeriodGuard::class)->assertOpen(now()->toDateString(), $purchase->branch_id);
            $created = collect();
            foreach ($rows as $row) {
                $lotId = (int) $row['stock_lot_id'];
                if (! $validLotIds->contains($lotId)) {
                    throw new RuntimeException("Lot #{$lotId} ไม่ได้มาจากใบซื้อนี้");
                }

                $productId = StockLot::whereKey($lotId)->value('product_id');
                $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
                // Same lock order as receipts: product -> balances -> lots.
                StockBalance::where('product_id', $productId)->orderBy('id')->lockForUpdate()->get();
                $productLots = StockLot::where('product_id', $productId)->orderBy('id')->lockForUpdate()->get();
                $inventoryBefore = DecimalMath::sum($productLots->map(fn ($l) => DecimalMath::multiply($l->remaining_qty, $l->unit_cost)));
                $lot = $productLots->firstWhere('id', $lotId);
                $oldCost = $lot->unit_cost;
                $newCost = DecimalMath::round($row['new_unit_cost'], DecimalMath::COST_SCALE);

                if (DecimalMath::compare($newCost, 0) < 0) {
                    throw new RuntimeException('ต้นทุนใหม่ต้องไม่ติดลบ');
                }
                if (DecimalMath::compare($oldCost, $newCost) === 0) {
                    continue; // ไม่มีการเปลี่ยนแปลงจริง ข้ามไปไม่บันทึกหลักฐานเปล่าๆ
                }

                $this->costCloseGuard->assertOpen($lot->received_date, 'new_unit_cost');

                // Transfers/returns preserve source_lot_id. Follow descendants so stock in
                // transit or at another branch is not incorrectly treated as already sold.
                $lotIds = $this->familyIds($lot);
                $lots = StockLot::whereIn('id', $lotIds)->orderBy('id')->lockForUpdate()->get();
                $remainingQty = DecimalMath::sum($lots->pluck('remaining_qty'));
                if (DecimalMath::compare($remainingQty, $lot->initial_qty) > 0) {
                    throw new RuntimeException('จำนวน Lot สืบทอดเกินจำนวนรับเดิม ต้องตรวจสอบก่อนปรับ');
                }
                foreach ($lots as $descendant) {
                    if (DecimalMath::compare($descendant->unit_cost, $oldCost) !== 0) {
                        throw new RuntimeException('ต้นทุน Lot สืบทอดไม่ตรงกัน ต้องตรวจสอบก่อนปรับ');
                    }
                }
                $consumedQty = DecimalMath::subtract($lot->initial_qty, $remainingQty, DecimalMath::QUANTITY_SCALE);
                $deltaPerUnit = DecimalMath::subtract($newCost, $oldCost);
                $remainingValueDelta = DecimalMath::multiply($deltaPerUnit, $remainingQty);
                $consumedValueDelta = DecimalMath::multiply($deltaPerUnit, $consumedQty);

                foreach ($lots as $descendant) {
                    $descendant->update(['unit_cost' => $newCost]);
                }

                if (DecimalMath::compare($remainingQty, 0) > 0) {
                    $onHand = DecimalMath::sum(StockBalance::where('product_id', $lot->product_id)->pluck('on_hand_qty'));
                    if ($product && DecimalMath::compare($onHand, 0) > 0) {
                        $newAverage = DecimalMath::add($product->average_cost, DecimalMath::divide($remainingValueDelta, $onHand));
                        $product->update(['average_cost' => DecimalMath::round($newAverage, DecimalMath::COST_SCALE)]);
                    }
                }

                $adjustment = PurchaseCostAdjustment::create([
                    'document_id' => $lot->source_document_id,
                    'stock_lot_id' => $lot->id,
                    'product_id' => $lot->product_id,
                    'old_unit_cost' => $oldCost,
                    'new_unit_cost' => $newCost,
                    'remaining_qty_adjusted' => $remainingQty,
                    'consumed_qty' => $consumedQty,
                    'unconfirmed_variance_amount' => $consumedValueDelta,
                    'reason' => $reason,
                    'created_by' => $userId,
                ]);
                $inventory = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_INVENTORY)->firstOrFail();
                $cogs = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_COGS)->firstOrFail();
                $clearing = ChartOfAccount::where('default_role', ChartOfAccount::ROLE_PURCHASE_COST_CLEARING)->first()
                    ?? ChartOfAccount::firstOrCreate(['code' => 'COST-CLEARING'], [
                        'name_th' => 'พักผลต่างต้นทุนซื้อรอยืนยันเอกสารผู้ขาย',
                        'account_type' => 'liability',
                        'default_role' => ChartOfAccount::ROLE_PURCHASE_COST_CLEARING,
                    ]);
                if ($clearing->default_role !== ChartOfAccount::ROLE_PURCHASE_COST_CLEARING) {
                    throw new RuntimeException('รหัส COST-CLEARING ถูกใช้งานอื่น กรุณาผูกบัญชีพักผลต่างต้นทุนซื้อ');
                }
                $inventoryDelta = DecimalMath::subtract(DecimalMath::round(DecimalMath::add($inventoryBefore, $remainingValueDelta), 2), DecimalMath::round($inventoryBefore, 2), 2);
                $totalDelta = DecimalMath::subtract(DecimalMath::multiply($newCost, $lot->initial_qty, 2), DecimalMath::multiply($oldCost, $lot->initial_qty, 2), 2);
                $consumedDelta = DecimalMath::subtract($totalDelta, $inventoryDelta, 2);
                $lines = [];
                foreach ([[$inventory->id, $inventoryDelta], [$cogs->id, $consumedDelta]] as [$account, $delta]) {
                    $positive = DecimalMath::compare($delta, 0) >= 0;
                    $amount = (string) DecimalMath::of($delta)->abs();
                    $lines[] = [$account, $positive ? $amount : 0, $positive ? 0 : $amount];
                    $lines[] = [$clearing->id, $positive ? 0 : $amount, $positive ? $amount : 0];
                }
                // An adjustment is a new event in today's open period, not a purchase repost.
                app(GlPostingService::class)->postResolved($purchase, $lines,
                    'ปรับต้นทุน #'.$adjustment->id.' '.$reason, 'cost-adjustment:'.$adjustment->id,
                    now()->toDateString());
                $created->push($adjustment);
            }

            if ($created->isEmpty()) {
                throw new RuntimeException('ไม่มีรายการที่เปลี่ยนแปลงต้นทุนจริง');
            }

            return $created;
        });
    }

    private function familyIds(StockLot $lot): array
    {
        $ids = [$lot->id];
        $frontier = $ids;
        while ($frontier !== []) {
            $frontier = StockLot::whereIn('source_lot_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function assertPurchase(Document $purchase): void
    {
        if ($purchase->documentType?->code !== 'PURCHASE') {
            throw new RuntimeException('ไม่ใช่เอกสารใบซื้อ');
        }
    }
}
