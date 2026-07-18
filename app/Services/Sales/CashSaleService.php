<?php

namespace App\Services\Sales;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\Product;
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
        private readonly \App\Services\Accounting\GlPostingService $glPosting,
        private readonly FifoStockService $fifo,
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
            $policies = Product::whereIn('id', $items->pluck('product_id')->unique())
                ->pluck('negative_stock_policy', 'id');
            foreach ($items as $item) {
                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $item['product_id'],
                    'warehouse_location_id' => $branch->default_warehouse_location_id,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                ]);

                $this->fifo->issue(
                    (int) $item['product_id'],
                    (int) $branch->default_warehouse_location_id,
                    (float) $item['qty'],
                    $document->id,
                    allowNegative: (bool) ($data['allow_negative_stock'] ?? false)
                        && ($policies[(int) $item['product_id']] ?? 'allow') === 'allow',
                );
            }

            $this->glPosting->postCashSale($document);

            return $document->fresh();
        });
    }
}
