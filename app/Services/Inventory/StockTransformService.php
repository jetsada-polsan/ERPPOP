<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PriceTable;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchPackage;
use App\Models\ProductPrice;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** แปรรูปวัตถุดิบจริงเป็นผลผลิต โดยล็อกต้นทุนและ Lot ณ เวลาเตรียมสินค้า */
class StockTransformService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly FifoStockService $fifo,
        private readonly CostingService $costing,
        private readonly ScaleBarcodeService $barcodes,
    ) {}

    /**
     * @param array{
     *   branch_id:int, warehouse_location_id:?int, remark:?string, batch_mode?:bool,
     *   production_recipe_id?:?int, input_weight_qty?:?float,
     *   raw_items:array<int,array{product_id:int,qty:float}>,
     *   output_items:array<int,array{product_id:int,qty:float,percent:?float}>
     * } $data
     */
    public function create(array $data): Document
    {
        if (empty($data['raw_items']) || empty($data['output_items'])) {
            throw new RuntimeException('ต้องมีวัตถุดิบและผลผลิตอย่างน้อยอย่างละ 1 รายการ');
        }

        $branch = Branch::findOrFail($data['branch_id']);
        $locationId = $data['warehouse_location_id'] ?? $branch->default_warehouse_location_id;
        if (! $locationId) {
            throw new RuntimeException("สาขา {$branch->name_th} ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น");
        }

        $raw = collect($data['raw_items']);
        $outputs = collect($data['output_items']);
        $batchMode = (bool) ($data['batch_mode'] ?? false);
        if ($batchMode && $outputs->count() !== 1) {
            throw new RuntimeException('งานจัดเซ็ตแบบชั่งจริงต้องมีสินค้าผลผลิต 1 รายการ');
        }

        $productIds = $raw->pluck('product_id')->merge($outputs->pluck('product_id'))->unique();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
        if ($products->count() !== $productIds->count()) {
            throw new RuntimeException('พบสินค้าที่ไม่มีอยู่ในระบบ');
        }
        if ($raw->pluck('product_id')->intersect($outputs->pluck('product_id'))->isNotEmpty()) {
            throw new RuntimeException('สินค้าผลผลิตต้องไม่ซ้ำกับวัตถุดิบใน Batch เดียวกัน');
        }

        // ประเมินต้นทุนเบื้องต้นด้วย average_cost เพื่อกันเคสไม่มีต้นทุนเลยก่อนเปิด transaction
        // ต้นทุนจริงที่บันทึกจะคำนวณจาก Lot ที่ FIFO ตัดจริงตอนอยู่ใน transaction ด้านล่าง
        $rawEstimate = round($raw->sum(fn (array $item) => (float) $item['qty'] * (float) $products->get((int) $item['product_id'])->average_cost), 4);
        if ($rawEstimate <= 0) {
            throw new RuntimeException('วัตถุดิบยังไม่มีต้นทุนเฉลี่ย กรุณารับสินค้าและตรวจต้นทุนก่อนจัดเซ็ต');
        }

        $percents = $outputs->pluck('percent')->filter(fn ($percent) => $percent !== null && $percent !== '');
        if ($percents->isEmpty()) {
            $equal = 100 / $outputs->count();
            $outputs = $outputs->map(fn (array $item) => $item + ['percent' => $equal]);
        } elseif (abs($percents->sum() - 100) > 0.01 || $percents->count() !== $outputs->count()) {
            throw new RuntimeException('%ปันทุนของผลผลิตต้องกรอกครบทุกแถวและรวมกันได้ 100%');
        }

        $documentType = DocumentType::where('code', 'STOCK_TRANSFORM')->firstOrFail();

        return DB::transaction(function () use ($data, $branch, $locationId, $raw, $outputs, $rawEstimate, $documentType, $batchMode, $products) {
            // สร้างหัวเอกสารก่อนด้วยยอดประมาณ (average_cost) เพื่อให้มี document_id ผูก movement การตัด
            // วัตถุดิบได้ตั้งแต่ต้น แล้วค่อยแก้ total_amount ให้ตรงต้นทุนจริงหลังตัด FIFO lot เสร็จ
            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->next('STOCK_TRANSFORM', $branch->id),
                'doc_date' => now()->toDateString(),
                'status' => 'active',
                'total_items' => $raw->count() + $outputs->count(),
                'total_amount' => $rawEstimate,
                'remark' => $data['remark'] ?? null,
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => $raw->sum('qty') + $outputs->sum('qty'),
                'total_items' => $raw->count() + $outputs->count(),
            ]);

            // ตัด FIFO lot วัตถุดิบจริงก่อน แล้วคิดต้นทุนจาก Lot ที่ถูกตัดจริง (ไม่ใช่ต้นทุนเฉลี่ย)
            // เพื่อให้ต้นทุนที่ปันส่วนไปยังผลผลิตตรงกับมูลค่าวัตถุดิบที่หายไปจริงจาก stock_lots
            $seq = 1;
            $sourceAllocations = collect();
            $raw = $raw->map(function (array $item) use ($locationId, $document, $products, &$sourceAllocations, $stockDocument, &$seq) {
                $productId = (int) $item['product_id'];
                $qty = (float) $item['qty'];
                $fallbackCost = (float) $products->get($productId)->average_cost;
                $allocations = $this->fifo->issue($productId, (int) $locationId, $qty, $document->id, 'transform_out');
                $sourceAllocations = $sourceAllocations->concat($allocations);
                $unitCost = $this->costing->unitCostFromAllocations($allocations, $qty, $fallbackCost);
                $costAmount = round($qty * $unitCost, 4);

                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id, 'seq' => $seq++,
                    'product_id' => $productId, 'warehouse_location_id' => $locationId,
                    'qty' => -$qty, 'unit_price' => $unitCost,
                    'unit_cost' => $unitCost, 'cost_amount' => $costAmount,
                ]);

                return $item + ['unit_cost' => $unitCost, 'cost_amount' => $costAmount];
            });
            $rawTotal = round($raw->sum('cost_amount'), 4);
            if ($rawTotal <= 0) {
                throw new RuntimeException('วัตถุดิบยังไม่มีต้นทุนเฉลี่ย กรุณารับสินค้าและตรวจต้นทุนก่อนจัดเซ็ต');
            }
            $document->update(['total_amount' => $rawTotal]);

            foreach ($outputs as $item) {
                $qty = (float) $item['qty'];
                $allocated = round($rawTotal * (float) $item['percent'] / 100, 4);
                $unitCost = round($allocated / $qty, 4);
                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id, 'seq' => $seq++,
                    'product_id' => $item['product_id'], 'warehouse_location_id' => $locationId,
                    'qty' => $qty, 'unit_price' => $unitCost, 'unit_cost' => $unitCost, 'cost_amount' => $allocated,
                ]);
                $this->costing->recordManufacturedReceipt((int) $item['product_id'], $qty, $unitCost);
                $outputLot = $this->fifo->receive((int) $item['product_id'], (int) $locationId, $qty, $document->id, 'transform_in', receivedDate: now()->toDateString(), unitCost: $unitCost);
                foreach ($sourceAllocations as $allocation) {
                    DB::table('stock_lot_lineages')->insert([
                        'output_lot_id' => $outputLot->id, 'input_lot_id' => $allocation['lot']->id,
                        'input_qty' => round((float) $allocation['qty'] * (float) $item['percent'] / 100, 4),
                        'document_id' => $document->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            if ($batchMode) {
                $output = $outputs->first();
                $outputProduct = $products->get((int) $output['product_id']);
                $inputWeight = (float) ($data['input_weight_qty'] ?? $raw->sum('qty'));
                $outputWeight = (float) $output['qty'];
                $plu = $this->scalePlu($outputProduct);
                $sellingPrice = $this->sellingPrice($outputProduct->id);
                $vatRate = (float) (DB::table('vat_rates')->where('effective_from', '<=', now()->toDateString())
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
                    ->orderByDesc('effective_from')->value('rate_percent') ?? 7);
                $netSellingPrice = $outputProduct->is_vat ? $sellingPrice * 100 / (100 + $vatRate) : $sellingPrice;
                $outputUnitCost = round($rawTotal / $outputWeight, 4);
                $profit = round($netSellingPrice - $outputUnitCost, 4);
                ProductionBatch::create([
                    'document_id' => $document->id,
                    'production_recipe_id' => $data['production_recipe_id'] ?? null,
                    'output_product_id' => $outputProduct->id,
                    'input_weight_qty' => $inputWeight,
                    'output_weight_qty' => $outputWeight,
                    'loss_weight_qty' => round($inputWeight - $outputWeight, 4),
                    'yield_percent' => $inputWeight > 0 ? round($outputWeight * 100 / $inputWeight, 4) : 0,
                    'total_input_cost' => $rawTotal,
                    'output_unit_cost' => $outputUnitCost,
                    'selling_unit_price' => $sellingPrice,
                    'net_selling_unit_price' => round($netSellingPrice, 4),
                    'estimated_profit_per_unit' => $profit,
                    'estimated_margin_percent' => $netSellingPrice > 0 ? round($profit * 100 / $netSellingPrice, 4) : 0,
                    'scale_plu' => $plu,
                    'prepared_by' => auth()->id(),
                ]);
            }

            return $document->fresh();
        });
    }

    /** @param array<int,float|int|string> $weights */
    public function addPackages(ProductionBatch $batch, array $weights): void
    {
        if (! $batch->scale_plu) {
            throw new RuntimeException('สินค้าผลผลิตยังไม่มี PLU เครื่องชั่ง 800xxx/801xxx');
        }
        $weights = collect($weights)->map(fn ($weight) => round((float) $weight, 4))->filter(fn ($weight) => $weight > 0);
        $existingWeight = (float) $batch->packages()->sum('weight_qty');
        if ($existingWeight + $weights->sum() > (float) $batch->output_weight_qty + 0.0001) {
            throw new RuntimeException('น้ำหนักรวมของป้ายมากกว่าน้ำหนักผลผลิตจริงของ Batch');
        }

        $unitPrice = (float) $batch->selling_unit_price;
        if ($unitPrice <= 0) {
            throw new RuntimeException('สินค้าผลผลิตยังไม่ได้ตั้งราคาขายต่อกิโลกรัม');
        }

        DB::transaction(function () use ($batch, $weights, $unitPrice) {
            $seq = (int) $batch->packages()->max('seq');
            foreach ($weights as $weight) {
                $total = round($weight * $unitPrice, 2);
                ProductionBatchPackage::create([
                    'production_batch_id' => $batch->id, 'seq' => ++$seq,
                    'weight_qty' => $weight, 'unit_price' => $unitPrice, 'total_price' => $total,
                    'barcode' => $this->barcodes->fromTotalPrice((string) $batch->scale_plu, $total),
                ]);
            }
        });
    }

    private function scalePlu(Product $product): ?string
    {
        $plu = $product->barcodes()->where('is_active', true)->pluck('barcode')
            ->first(fn ($barcode) => preg_match('/^80[01][0-9]{3}$/', (string) $barcode) === 1);

        return $plu ?: (preg_match('/^80[01][0-9]{3}$/', $product->sku_code) === 1 ? $product->sku_code : null);
    }

    private function sellingPrice(int $productId): float
    {
        $tableId = PriceTable::where('is_default', true)->value('id');
        $price = $tableId ? ProductPrice::where('price_table_id', $tableId)->where('product_id', $productId)
            ->whereNull('unit_id')->where('is_active', true)->value('price') : null;

        return (float) ($price ?? Product::find($productId)?->default_price ?? 0);
    }
}
