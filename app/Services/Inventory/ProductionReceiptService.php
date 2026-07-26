<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Sales\DocumentNumberGenerator;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Receive finished goods and allocate the actual FIFO cost of recipe inputs.
 * This keeps production orders that do not use a separate requisition flow
 * fully traceable from raw-material lots to the finished-goods lot.
 */
class ProductionReceiptService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly FifoStockService $fifo,
        private readonly CostingService $costing,
    ) {}

    public function receive(ProductionOrder $order, int|float|string $qty, ?string $remark = null): Document
    {
        if (DecimalMath::compare($qty, 0) <= 0) {
            throw new RuntimeException('จำนวนรับเข้าต้องมากกว่า 0');
        }
        if ($order->status === 'completed') {
            throw new RuntimeException('ใบสั่งผลิตนี้ปิดงานแล้ว');
        }
        $order->loadMissing(['recipe.items.product', 'finishedProduct']);
        if (! $order->recipe || $order->recipe->items->isEmpty()) {
            throw new RuntimeException('ใบสั่งผลิตต้องมีสูตรและวัตถุดิบก่อนรับผลผลิต เพื่อคำนวณต้นทุนจริง');
        }
        if ((int) $order->recipe->finished_product_id !== (int) $order->finished_product_id) {
            throw new RuntimeException('สินค้าสำเร็จรูปในใบสั่งผลิตไม่ตรงกับสูตร');
        }
        $outstanding = DecimalMath::subtract($order->planned_qty, $order->produced_qty, DecimalMath::QUANTITY_SCALE);
        if (DecimalMath::compare($qty, $outstanding) > 0) {
            throw new RuntimeException('จำนวนรับผลผลิตเกินจำนวนคงค้างของใบสั่งผลิต');
        }

        $branchId = $order->branch_id ?? Branch::orderBy('id')->value('id');
        $locationId = $order->warehouse_location_id
            ?? Branch::find($branchId)?->default_warehouse_location_id;
        if (! $locationId) {
            throw new RuntimeException('ใบสั่งผลิตนี้ไม่ได้ระบุคลังรับเข้า และสาขายังไม่มีคลังหลัก');
        }

        $documentType = DocumentType::where('code', 'PRODUCTION_RECEIPT')->firstOrFail();

        return DB::transaction(function () use ($order, $qty, $remark, $branchId, $locationId, $documentType) {
            $recipe = $order->recipe;
            $factor = DecimalMath::divide($qty, $recipe->output_qty, DecimalMath::QUANTITY_SCALE);
            $rawItems = $recipe->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'qty' => DecimalMath::multiply($item->qty, $factor, DecimalMath::QUANTITY_SCALE),
            ]);
            $products = Product::whereIn('id', $rawItems->pluck('product_id'))->get()->keyBy('id');

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branchId,
                'doc_number' => $this->numbers->next('PRODUCTION_RECEIPT', $branchId),
                'doc_date' => now()->toDateString(),
                'reference' => $order->doc_no,
                'status' => 'active',
                'total_items' => $rawItems->count() + 1,
                'total_amount' => 0,
                'remark' => 'รับจากใบสั่งผลิต '.$order->doc_no.($remark ? ' | '.$remark : ''),
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => DecimalMath::add(
                    DecimalMath::sum($rawItems->pluck('qty'), DecimalMath::QUANTITY_SCALE),
                    $qty,
                    DecimalMath::QUANTITY_SCALE,
                ),
                'total_items' => $rawItems->count() + 1,
            ]);

            $seq = 1;
            $costAmounts = [];
            $sourceAllocations = collect();
            foreach ($rawItems as $rawItem) {
                $product = $products->get((int) $rawItem['product_id']);
                $rawQty = $rawItem['qty'];
                $fallbackCost = $product?->average_cost ?? 0;
                $allocations = $this->fifo->issue(
                    (int) $rawItem['product_id'],
                    (int) $locationId,
                    $rawQty,
                    $document->id,
                    'transform_out',
                );
                $sourceAllocations = $sourceAllocations->concat($allocations);
                $unitCost = $this->costing->unitCostFromAllocations($allocations, $rawQty, $fallbackCost);
                $costAmount = DecimalMath::multiply($rawQty, $unitCost);
                $costAmounts[] = $costAmount;

                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $rawItem['product_id'],
                    'warehouse_location_id' => $locationId,
                    'qty' => DecimalMath::multiply($rawQty, -1, DecimalMath::QUANTITY_SCALE),
                    'unit_price' => $unitCost,
                    'unit_cost' => $unitCost,
                    'cost_amount' => $costAmount,
                ]);
            }

            $totalCost = DecimalMath::sum($costAmounts);
            if (DecimalMath::compare($totalCost, 0) <= 0) {
                throw new RuntimeException('วัตถุดิบไม่มีต้นทุน กรุณาตรวจ Lot รับเข้าก่อนรับผลผลิต');
            }
            $outputUnitCost = DecimalMath::divide($totalCost, $qty);
            StockDocumentItem::create([
                'stock_document_id' => $stockDocument->id,
                'seq' => $seq,
                'product_id' => $order->finished_product_id,
                'warehouse_location_id' => $locationId,
                'qty' => $qty,
                'unit_price' => $outputUnitCost,
                'unit_cost' => $outputUnitCost,
                'cost_amount' => $totalCost,
            ]);

            $this->costing->recordManufacturedReceipt((int) $order->finished_product_id, $qty, $outputUnitCost);
            $outputLot = $this->fifo->receive(
                (int) $order->finished_product_id,
                (int) $locationId,
                $qty,
                $document->id,
                'transform_in',
                receivedDate: now()->toDateString(),
                unitCost: $outputUnitCost,
            );
            foreach ($sourceAllocations->groupBy(fn ($allocation) => $allocation['lot']->id) as $inputLotId => $lotAllocations) {
                DB::table('stock_lot_lineages')->insert([
                    'output_lot_id' => $outputLot->id,
                    'input_lot_id' => $inputLotId,
                    'input_qty' => DecimalMath::sum($lotAllocations->pluck('qty'), DecimalMath::QUANTITY_SCALE),
                    'document_id' => $document->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $document->update(['total_amount' => $totalCost]);
            $produced = DecimalMath::add($order->produced_qty, $qty, DecimalMath::QUANTITY_SCALE);
            $order->update([
                'produced_qty' => $produced,
                'status' => DecimalMath::compare($produced, $order->planned_qty) >= 0 ? 'completed' : 'in_progress',
            ]);

            return $document->fresh();
        });
    }
}
