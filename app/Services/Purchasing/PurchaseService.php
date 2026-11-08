<?php

namespace App\Services\Purchasing;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\TradeAgreement;
use App\Services\Accounting\GlPostingService;
use App\Services\Inventory\CostingService;
use App\Services\Inventory\FifoStockService;
use App\Services\Sales\DocumentNumberGenerator;
use App\Support\DecimalMath;
use App\Support\TradeTax;
use Carbon\Carbon;
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
        private readonly GlPostingService $glPosting,
        private readonly SupplierOpenItemService $openItems,
        private readonly CostingService $costing,
        private readonly FifoStockService $fifo,
    ) {}

    /**
     * @param  array{supplier_id:int, branch_id:int, is_credit:bool, prices_include_vat?:bool, claim_input_vat?:bool, remark:?string, items: array<int, array{product_id:int, qty:float, unit_price:float}>}  $data
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
        $pricesIncludeVat = (bool) ($data['prices_include_vat'] ?? true);
        $claimInputVat = (bool) ($data['claim_input_vat'] ?? false);
        // Cash vs credit purchase share the same document_type (PURCHASE); the
        // distinction only affects whether a supplier_ledger debt entry is opened.
        $documentType = DocumentType::where('code', 'PURCHASE')->firstOrFail();

        return DB::transaction(function () use ($data, $branch, $documentType, $isCredit, $pricesIncludeVat, $claimInputVat) {
            $items = collect($data['items']);
            $products = Product::whereIn('id', $items->pluck('product_id'))->get()->keyBy('id');
            $items = $items->map(function (array $item) use ($products): array {
                $product = $products->get((int) $item['product_id']);
                if ($product?->tracks_expiry && empty($item['expiry_date'])
                    && ! empty($item['manufacture_date']) && $product->shelf_life_days) {
                    $item['expiry_date'] = Carbon::parse($item['manufacture_date'])
                        ->addDays((int) $product->shelf_life_days)->toDateString();
                }
                if ($product?->tracks_expiry && empty($item['expiry_date'])) {
                    throw new RuntimeException('สินค้าที่ควบคุมวันหมดอายุต้องระบุวันหมดอายุตอนรับเข้า');
                }
                if (! empty($item['manufacture_date']) && ! empty($item['expiry_date'])
                    && Carbon::parse($item['expiry_date'])->lt(Carbon::parse($item['manufacture_date']))) {
                    throw new RuntimeException('วันหมดอายุต้องไม่ก่อนวันผลิต');
                }

                return $item;
            });
            $docDate = $data['doc_date'] ?? now()->toDateString();
            $vatRate = TradeAgreement::standardRate($docDate);
            $tradeTax = null;
            if (array_key_exists('trade_agreement_id', $data)) {
                $supplier = Supplier::findOrFail($data['supplier_id']);
                $agreements = $supplier->tradeAgreements()->effectiveOn($docDate);
                $selectedId = $data['trade_agreement_id'];
                $agreement = $selectedId ? $agreements->whereKey($selectedId)->first() : $agreements->first();
                if ($selectedId && ! $agreement) {
                    throw new RuntimeException('ข้อตกลงที่เลือกใช้ไม่ได้กับผู้จำหน่ายหรือวันที่เอกสาร');
                }

                if ($agreement) {
                    $tradeTax = $agreement->taxSnapshot($vatRate);
                    $pricesIncludeVat = $tradeTax['prices_include_vat'];
                    $vatRate = $tradeTax['vat_rate'];
                    $claimInputVat = $tradeTax['vat_mode'] === 'standard' && $claimInputVat;
                }
            }
            $calculated = $items->map(function (array $item) use ($products, $pricesIncludeVat, $claimInputVat, $vatRate, $tradeTax): array {
                $product = $products->get((int) $item['product_id']);
                $qty = $item['qty'];
                $enteredPrice = $item['unit_price'];
                $unitCost = $this->costing->purchaseUnitCost($product, $enteredPrice, $pricesIncludeVat, $claimInputVat, $vatRate);
                $costAmount = DecimalMath::multiply($qty, $unitCost);
                $vatAmount = $product->is_vat && $claimInputVat
                    ? ($pricesIncludeVat
                        ? DecimalMath::subtract(DecimalMath::multiply($qty, $enteredPrice), $costAmount)
                        : DecimalMath::divide(DecimalMath::multiply($costAmount, $vatRate), 100))
                    : '0.00000000';
                $taxable = $product->is_vat && ($tradeTax['vat_mode'] ?? 'standard') !== 'none';
                $invoice = TradeTax::line($qty, $enteredPrice, $pricesIncludeVat, $taxable ? $vatRate : '0');
                $invoiceAmount = $product->is_vat && ! $pricesIncludeVat
                    ? DecimalMath::divide(
                        DecimalMath::multiply(DecimalMath::multiply($qty, $enteredPrice), DecimalMath::add(100, $vatRate)),
                        100,
                    )
                    : DecimalMath::multiply($qty, $enteredPrice);

                return $item + [
                    '_unit_cost' => $unitCost,
                    '_cost_amount' => $costAmount,
                    '_vat_amount' => $vatAmount,
                    '_invoice_amount' => $invoiceAmount,
                    '_tax_base' => $taxable ? $invoice['base'] : '0',
                    '_invoice_vat' => $invoice['vat'],
                    '_exempt_amount' => $taxable ? '0' : $invoiceAmount,
                ];
            });
            $totalQty = DecimalMath::sum($calculated->pluck('qty'), DecimalMath::QUANTITY_SCALE);
            $subtotalAmount = DecimalMath::sum($calculated->pluck('_cost_amount'));
            $totalAmount = DecimalMath::sum($calculated->pluck('_invoice_amount'));
            $vatAmount = $claimInputVat
                ? DecimalMath::subtract($totalAmount, $subtotalAmount)
                : '0.00000000';

            if ($tradeTax) {
                $tradeTax += [
                    'taxable_base' => DecimalMath::sum($calculated->pluck('_tax_base'), 2),
                    'exempt_amount' => DecimalMath::sum($calculated->pluck('_exempt_amount'), 2),
                    'vat_amount' => DecimalMath::sum($calculated->pluck('_invoice_vat'), 2),
                ];
            }

            $document = Document::create([
                'document_type_id' => $documentType->id,
                'branch_id' => $branch->id,
                'doc_number' => $this->numbers->next('PURCHASE', $branch->id, $docDate),
                'doc_date' => $docDate,
                'supplier_id' => $data['supplier_id'],
                'reference' => $data['reference'] ?? null,
                'status' => 'active',
                'total_items' => $items->count(),
                'total_amount' => $totalAmount,
                'subtotal_amount' => $subtotalAmount,
                'vat_amount' => $vatAmount,
                'trade_tax' => $tradeTax,
                'prices_include_vat' => $pricesIncludeVat,
                'claim_input_vat' => $claimInputVat,
                'remark' => $data['remark'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $stockDocument = StockDocument::create([
                'document_id' => $document->id,
                'total_qty' => $totalQty,
                'total_items' => $items->count(),
            ]);

            $seq = 1;
            foreach ($calculated as $item) {
                StockDocumentItem::create([
                    'stock_document_id' => $stockDocument->id,
                    'seq' => $seq++,
                    'product_id' => $item['product_id'],
                    'warehouse_location_id' => $branch->default_warehouse_location_id,
                    'qty' => $item['qty'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $item['_unit_cost'],
                    'cost_amount' => $item['_cost_amount'],
                    'vat_amount' => $item['_vat_amount'],
                    'lot_no' => $item['lot_number'] ?? null,
                    'manufacture_date' => $item['manufacture_date'] ?? null,
                    'expire_date' => $item['expiry_date'] ?? null,
                ]);

                // อัปเดตต้นทุนเฉลี่ย "ก่อน" เพิ่มสต๊อก (ใช้ยอดคงเหลือก่อนรับถัวเฉลี่ย)
                $this->costing->recordPurchase($item['product_id'], $item['qty'], $item['_unit_cost']);

                $this->fifo->receive(
                    (int) $item['product_id'], (int) $branch->default_warehouse_location_id,
                    (float) $item['qty'], $document->id, 'in',
                    $item['lot_number'] ?? null, now()->toDateString(), $item['expiry_date'] ?? null,
                    (float) $item['_unit_cost'], $item['manufacture_date'] ?? null
                );

                ProductSupplier::updateOrCreate(
                    ['product_id' => $item['product_id'], 'supplier_id' => $data['supplier_id']],
                    ['last_purchase_price' => $item['unit_price']],
                );
            }

            if ($isCredit) {
                $previousBalance = SupplierLedger::where('supplier_id', $data['supplier_id'])
                    ->orderByDesc('id')
                    ->value('balance_after') ?? 0;

                SupplierLedger::create([
                    'supplier_id' => $data['supplier_id'],
                    'document_id' => $document->id,
                    'entry_type' => 'credit',
                    'amount' => $totalAmount,
                    'balance_after' => DecimalMath::add($previousBalance, $totalAmount),
                    'entry_date' => now()->toDateString(),
                ]);

                // ledger บอกยอดรวมต่อผู้ขาย แต่ AP aging ต้องรู้ว่าเงินก้อนไหนมาจากใบไหน
                $this->openItems->openFromPurchase(
                    $document,
                    (float) $totalAmount,
                    $data['payment_terms'] ?? $tradeTax['payment_terms'] ?? null,
                    $data['due_date'] ?? (isset($tradeTax['credit_days'])
                        ? Carbon::parse($docDate)->addDays($tradeTax['credit_days'])->toDateString()
                        : null),
                );
            }

            $this->glPosting->postPurchase($document, $isCredit);

            return $document->fresh();
        });
    }
}
