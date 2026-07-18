<?php

namespace App\Services\Purchasing;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Inventory\FifoStockService;
use App\Models\SupplierLedger;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Receives goods into stock from a supplier (ใบซื้อเชื่อ/ใบซื้อสด): the inverse of
 * BookingService/CreditSaleService - this is the "stock IN" side of the business
 * that was previously missing (everything else only ever cut stock). Cash
 * purchases (is_credit=false) just move stock; credit purchases also open an AP
 * debt via supplier_ledger (running balance, mirroring customer_open_items'
 * intent but using the simpler ledger model that already existed for suppliers).
 */
class PurchaseService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly \App\Services\Accounting\GlPostingService $glPosting,
        private readonly \App\Services\Inventory\CostingService $costing,
        private readonly FifoStockService $fifo,
    ) {}

    /**
     * @param  array{supplier_id:int, branch_id:int, is_credit:bool, remark:?string, items: array<int, array{product_id:int, qty:float, unit_price:float}>}  $data
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

        $isCredit = (bool) ($data['is_credit'] ?? true);
        // Cash vs credit purchase share the same document_type (PURCHASE); the
        // distinction only affects whether a supplier_ledger debt entry is opened.
        $documentType = DocumentType::where('code', 'PURCHASE')->firstOrFail();

        return DB::transaction(function () use ($data, $branch, $documentType, $isCredit) {
            $items = collect($data['items']);
            $expiryByProduct = Product::whereIn('id', $items->pluck('product_id'))
                ->pluck('tracks_expiry', 'id');
            foreach ($items as $item) {
                if (($expiryByProduct[$item['product_id']] ?? false)
                    && array_key_exists('expiry_date', $item) && empty($item['expiry_date'])) {
                    throw new RuntimeException('สินค้าที่ควบคุมวันหมดอายุต้องระบุวันหมดอายุตอนรับเข้า');
                }
            }
            $totalQty = $items->sum('qty');
            $totalAmount = $items->sum(fn ($i) => $i['qty'] * $i['unit_price']);

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->next('PURCHASE', $branch->id),
                'doc_date' => now()->toDateString(),
                'supplier_id' => $data['supplier_id'],
                'status' => 'active',
                'total_items' => $items->count(),
                'total_amount' => $totalAmount,
                'remark' => $data['remark'] ?? null,
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

                // อัปเดตต้นทุนเฉลี่ย "ก่อน" เพิ่มสต๊อก (ใช้ยอดคงเหลือก่อนรับถัวเฉลี่ย)
                $this->costing->recordPurchase($item['product_id'], (float) $item['qty'], (float) $item['unit_price']);

                $this->fifo->receive(
                    (int) $item['product_id'], (int) $branch->default_warehouse_location_id,
                    (float) $item['qty'], $document->id, 'in',
                    $item['lot_number'] ?? null, now()->toDateString(), $item['expiry_date'] ?? null,
                    (float) $item['unit_price']
                );

                ProductSupplier::updateOrCreate(
                    ['product_id' => $item['product_id'], 'supplier_id' => $data['supplier_id']],
                    ['last_purchase_price' => $item['unit_price']],
                );
            }

            if ($isCredit) {
                $previousBalance = (float) (SupplierLedger::where('supplier_id', $data['supplier_id'])
                    ->orderByDesc('id')
                    ->value('balance_after') ?? 0);

                SupplierLedger::create([
                    'supplier_id' => $data['supplier_id'],
                    'document_id' => $document->id,
                    'entry_type' => 'credit',
                    'amount' => $totalAmount,
                    'balance_after' => $previousBalance + $totalAmount,
                    'entry_date' => now()->toDateString(),
                ]);
            }

            $this->glPosting->postPurchase($document, $isCredit);

            return $document->fresh();
        });
    }
}
