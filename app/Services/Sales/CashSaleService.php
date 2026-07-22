<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Accounting\GlPostingService;
use App\Services\Inventory\CostingService;
use App\Services\Inventory\FifoStockService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a direct cash sale (ใบขายสด): stock leaves immediately, no AR entry.
 * The fish-market equivalent of CreditSaleService but for walk-in / cash customers
 * where no booking exists and no debt is opened.
 */
class CashSaleService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly GlPostingService $glPosting,
        private readonly FifoStockService $fifo,
        private readonly CostingService $costing,
    ) {}

    /**
     * @param  array{branch_id:int, customer_id:?int, remark:?string, allow_negative_stock?:bool, items: array<int, array{product_id:int, qty:float, unit_price:float}>}  $data
     */
    public function create(array $data): Document
    {
        if (empty($data['items'])) {
            throw new RuntimeException('ต้องมีรายการสินค้าอย่างน้อย 1 รายการ');
        }

        $branch = Branch::findOrFail($data['branch_id']);
        if ($branch->default_warehouse_location_id === null) {
            throw new RuntimeException("สาขา {$branch->name_th} ยังไม่ได้กำหนดคลังสินค้าเริ่มต้น");
        }

        $documentType = DocumentType::where('code', 'CASH_SALE')->firstOrFail();

        return DB::transaction(function () use ($data, $branch, $documentType) {
            $items = collect($data['items']);
            $totalAmount = $items->sum(fn ($i) => $i['qty'] * $i['unit_price']);

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->next('CASH_SALE', $branch->id),
                'doc_date' => now()->toDateString(),
                'customer_id' => $data['customer_id'] ?? null,
                'status' => 'active',
                'total_items' => $items->count(),
                'total_amount' => $totalAmount,
                'remark' => $data['remark'] ?? null,
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => $items->sum('qty'),
                'total_items' => $items->count(),
            ]);

            $seq = 1;
            $products = Product::whereIn('id', $items->pluck('product_id')->unique())->get()->keyBy('id');
            $policies = $products->pluck('negative_stock_policy', 'id');
            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $qty = (float) $item['qty'];
                $fallbackCost = (float) ($products->get($productId)?->average_cost ?? 0);

                // ตัด FIFO lot จริงก่อน แล้วคิดต้นทุนขายจาก Lot ที่ถูกตัดจริง (ไม่ใช่ต้นทุนเฉลี่ย)
                // เพื่อให้ COGS ตรงกับมูลค่าสต๊อกปลายงวดที่ InventoryCostCloseService คำนวณจาก stock_lots
                $allocations = $this->fifo->issue(
                    $productId,
                    (int) $branch->default_warehouse_location_id,
                    $qty,
                    $document->id,
                    allowNegative: (bool) ($data['allow_negative_stock'] ?? false)
                        && ($policies[$productId] ?? 'allow') === 'allow',
                );
                $unitCost = $this->costing->unitCostFromAllocations($allocations, $qty, $fallbackCost);

                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $productId,
                    'warehouse_location_id' => $branch->default_warehouse_location_id,
                    'qty' => $qty,
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $unitCost,
                    'cost_amount' => round($qty * $unitCost, 4),
                ]);
            }

            $this->glPosting->postCashSale($document);

            return $document->fresh();
        });
    }
}
