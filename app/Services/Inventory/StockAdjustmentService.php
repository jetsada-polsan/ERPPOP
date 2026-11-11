<?php

namespace App\Services\Inventory;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\WarehouseLocation;
use App\Services\Sales\DocumentNumberGenerator;
use App\Services\Accounting\GlPostingService;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reconciles physical stock counts against the system's on_hand_qty
 * (ตรวจนับสต็อก). Only items whose counted qty differs from the system qty
 * produce a line; the diff is signed (+found extra / -shortage) and recorded as
 * adjust_in or adjust_out so historical inventory valuation can distinguish
 * receipts from issues.
 */
class StockAdjustmentService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly FifoStockService $fifo,
        private readonly GlPostingService $glPosting,
    ) {}

    /**
     * @param  array{branch_id:int, warehouse_location_id:int, remark:?string, items: array<int, array{product_id:int, counted_qty:float, unit_cost?:float}>}  $data
     */
    public function create(array $data): Document
    {
        if (empty($data['items'])) {
            throw new RuntimeException('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $locationId = (int) $data['warehouse_location_id'];

        $locationBelongsToBranch = WarehouseLocation::query()
            ->whereKey($locationId)
            ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('branch_id', $data['branch_id']))
            ->exists();
        if (! $locationBelongsToBranch) {
            throw new RuntimeException('ตำแหน่งเก็บต้องอยู่ในสาขาเดียวกับใบปรับสต๊อก');
        }

        $systemQtyByProduct = StockBalance::whereIn('product_id', collect($data['items'])->pluck('product_id'))
            ->where('warehouse_location_id', $locationId)
            ->pluck('on_hand_qty', 'product_id');

        $diffs = collect($data['items'])
            ->map(function ($item) use ($systemQtyByProduct) {
                $systemQty = $systemQtyByProduct[$item['product_id']] ?? 0;
                $countedQty = $item['counted_qty'];

                return [
                    'product_id' => $item['product_id'],
                    'system_qty' => $systemQty,
                    'counted_qty' => $countedQty,
                    'diff' => DecimalMath::subtract($countedQty, $systemQty, DecimalMath::QUANTITY_SCALE),
                    'unit_cost' => isset($item['unit_cost']) ? DecimalMath::round($item['unit_cost'], DecimalMath::COST_SCALE) : null,
                ];
            })
            ->filter(fn ($i) => DecimalMath::compare($i['diff'], 0) !== 0)
            ->values();

        if ($diffs->isEmpty()) {
            throw new RuntimeException('ยอดนับตรงกับระบบทั้งหมด ไม่มีรายการที่ต้องปรับปรุง');
        }

        $documentType = DocumentType::where('code', 'STOCK_ADJUSTMENT')->firstOrFail();

        return DB::transaction(function () use ($data, $diffs, $locationId, $documentType) {
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $data['branch_id'],
                'doc_number' => $this->numbers->next('STOCK_ADJUSTMENT', $data['branch_id']),
                'doc_date' => now()->toDateString(),
                'status' => 'pending_approval',
                'total_items' => $diffs->count(),
                'total_amount' => 0,
                'remark' => $data['remark'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => DecimalMath::sum($diffs->map(fn ($i) => DecimalMath::absoluteDifference($i['diff'], 0)), DecimalMath::QUANTITY_SCALE),
                'total_items' => $diffs->count(),
            ]);

            $seq = 1;
            $itemsToInsert = [];
            foreach ($diffs as $diff) {
                $itemsToInsert[] = [
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $diff['product_id'],
                    'warehouse_location_id' => $locationId,
                    'qty' => $diff['diff'],
                    'system_qty' => $diff['system_qty'],
                    'counted_qty' => $diff['counted_qty'],
                    'unit_cost' => $diff['unit_cost'],
                ];
            }
            foreach (array_chunk($itemsToInsert, 500) as $chunk) {
                StockDocumentItem::insert($chunk);
            }

            return $document->fresh();
        });
    }

    public function approve(Document $document, ?int $approvedByUserId = null): Document
    {
        if ($document->status !== 'pending_approval' && $document->status !== 'approving') {
            throw new RuntimeException('เอกสารนี้ไม่ได้อยู่ในสถานะรออนุมัติ');
        }
        $userId = $approvedByUserId ?: auth()->id();
        $user = $userId ? \App\Models\User::find($userId) : auth()->user();

        if ($document->created_by && $document->created_by === $userId && ! $user?->hasPermission('system.admin')) {
            throw new RuntimeException('ผู้สร้างใบปรับสต๊อกไม่สามารถอนุมัติรายการของตนเอง');
        }

        return DB::transaction(function () use ($document, $userId, $user) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending_approval' && $locked->status !== 'approving') {
                throw new RuntimeException('เอกสารนี้ถูกดำเนินการไปแล้ว');
            }
            $locked->load('stockDocument.items');

            $items = $locked->stockDocument->items;
            $productIds = $items->pluck('product_id')->unique()->values()->all();
            $locationIds = $items->pluck('warehouse_location_id')->unique()->values()->all();

            $balances = StockBalance::whereIn('product_id', $productIds)
                ->whereIn('warehouse_location_id', $locationIds)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn ($b) => "{$b->product_id}_{$b->warehouse_location_id}");

            $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

            $valueChange = 0.0;   // บวก = ของเกิน มูลค่าสต๊อกเพิ่ม, ลบ = ของขาด
            // ยอดและสินค้าที่โหลดล่วงหน้าจะเก่าทันทีที่รายการก่อนหน้าแตะสินค้าตัวเดียวกัน
            // (ใบที่มีสินค้าซ้ำบรรทัด) กรณีนั้นต้องอ่านค่าปัจจุบันจากฐานข้อมูลเหมือนโค้ดเดิม
            $touchedBalances = [];
            $touchedProducts = [];
            foreach ($items as $item) {
                $balanceKey = "{$item->product_id}_{$item->warehouse_location_id}";
                $balance = isset($touchedBalances[$balanceKey])
                    ? StockBalance::where('product_id', $item->product_id)
                        ->where('warehouse_location_id', $item->warehouse_location_id)
                        ->lockForUpdate()->first()
                    : $balances->get($balanceKey);
                $current = $balance?->on_hand_qty ?? 0;
                if (DecimalMath::compare($current, $item->system_qty) !== 0) {
                    throw new RuntimeException('ยอดสต๊อกเปลี่ยนหลังส่งอนุมัติ กรุณายกเลิกและตรวจนับใหม่');
                }

                $productId = (int) $item->product_id;
                $warehouseLocationId = (int) $item->warehouse_location_id;
                $diff = $item->qty;
                if (DecimalMath::compare($diff, 0) > 0) {
                    // เจอของเกิน: สร้าง Lot ใหม่รองรับจำนวนที่เพิ่ม ไม่งั้น stock_lots จะไม่ตรงกับ
                    // stock_balances และมูลค่าสต๊อกปลายงวดจะขาดหายไป (ใช้ average_cost ปัจจุบันประมาณ
                    // ต้นทุน เพราะของเกินไม่มี Lot ต้นทางจริงให้อ้างอิง)
                    $product = isset($touchedProducts[$productId]) ? Product::find($productId) : $products->get($productId);
                    $explicitCost = $item->unit_cost ?? 0;
                    $unitCost = DecimalMath::compare($explicitCost, 0) > 0 ? $explicitCost : ($product?->average_cost ?? 0);
                    $lot = $this->fifo->receive(
                        $productId, $warehouseLocationId, $diff,
                        $locked->id, 'adjust_in', unitCost: $unitCost,
                    );
                    $valueChange += (float) $diff * (float) $lot->unit_cost;
                    if (DecimalMath::compare($explicitCost, 0) > 0) {
                        $product?->update([
                            'average_cost' => $explicitCost,
                            'last_purchase_cost' => $explicitCost,
                            'last_purchase_cost_at' => now(),
                        ]);
                    }
                } elseif (DecimalMath::compare($diff, 0) < 0) {
                    // ของขาด/เสียหาย: ตัด Lot จริงตาม FIFO เพื่อให้ stock_lots สะท้อนของที่หายไปจริง
                    // (ไม่ทำแบบเดิมที่แก้ stock_balances ตรงๆ โดยไม่แตะ stock_lots เลย)
                    // มูลค่าที่หายไปคิดจาก Lot ที่ถูกตัดจริง ไม่ใช่ต้นทุนเฉลี่ยปัจจุบัน
                    $allocations = $this->fifo->issue(
                        $productId, $warehouseLocationId, DecimalMath::absoluteDifference($diff, 0),
                        $locked->id, 'adjust_out', allowNegative: true,
                    );
                    $valueChange -= $this->allocatedValue($allocations);
                }
                if (DecimalMath::compare($diff, 0) !== 0) {
                    $touchedBalances[$balanceKey] = true;
                    $touchedProducts[$productId] = true;
                }
            }
            $locked->update([
                'status' => 'active',
                'remark' => trim(($locked->remark ? $locked->remark.' | ' : '').'อนุมัติโดย '.$user?->name),
            ]);
            DB::table('stock_counts')->where('posted_document_id', $locked->id)->update([
                'status' => 'posted', 'confirmed_by' => $userId, 'confirmed_at' => now(), 'updated_at' => now(),
            ]);

            // มูลค่าสต๊อกเปลี่ยนแล้วต้องสะท้อนในบัญชีทันที ไม่งั้นปิดงวดกระทบยอดไม่ได้
            $this->glPosting->postInventoryAdjustment($locked, $valueChange, 'ปรับปรุงสินค้าคงเหลือ');

            return $locked->fresh();
        });
    }

    /** มูลค่ารวมของ Lot ที่ถูกตัดไปจริง (FIFO คืนมาเป็นคู่ lot + qty) */
    private function allocatedValue(\Illuminate\Support\Collection $allocations): float
    {
        return (float) $allocations->sum(
            fn (array $allocation) => (float) $allocation['qty'] * (float) $allocation['lot']->unit_cost
        );
    }
}
