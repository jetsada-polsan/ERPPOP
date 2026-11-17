<?php

namespace App\Services\Bplus;

use App\Models\ChartOfAccount;
use App\Models\Document;
use App\Services\Accounting\GlPostingService;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * สร้างเอกสาร ERP จริงจากสำเนา BPlus (bplus_sync_documents) ของสายขายหนึ่งสาย
 * ใบจอง -> ใบจอง, DS/DSN -> ใบขายเชื่อ + ลูกหนี้, RR -> ใบเสร็จรับเงิน + ตัดชำระ
 *
 * ตั้งใจ: ไม่ตัดสต๊อก ไม่จองสต๊อก ไม่ลง GL (บัญชีทำจาก BPlus)
 * ยอดเงินและ VAT ใช้ตัวเลขจากบิล BPlus ตรงๆ
 * รันซ้ำได้: เอกสารผูกกับ documents.source_key = DI_KEY ของ BPlus ใบเดิมถูกอัปเดต ไม่สร้างซ้ำ
 */
class BplusDocumentMaterializer
{
    public const SOURCE = 'bplus';

    private array $ctx = [];

    /** @var array<string, string> ref => ปัญหา */
    private array $problems = [];

    private GlPostingService $glPostingService;

    public function __construct(?GlPostingService $glPostingService = null)
    {
        $this->glPostingService = $glPostingService ?? app(GlPostingService::class);
    }

    /** ตรวจก่อนบันทึก: ลูกค้า/สินค้าที่ ERP ยังไม่มี และสาขา/เล่มที่จะใช้ */
    public function check(string $route, array $documents, array $incomingCustomers = [], array $incomingProducts = []): array
    {
        $ctx = $this->context($route, false);

        $incomingCustomerKeys = collect($incomingCustomers)
            ->flatMap(fn ($c) => array_filter([
                isset($c['ar_key']) ? (string) $c['ar_key'] : null,
                isset($c['customer_key']) ? (string) $c['customer_key'] : null,
                isset($c['code']) ? (string) $c['code'] : null,
                isset($c['ar_code']) ? (string) $c['ar_code'] : null,
                isset($c['customer_code']) ? (string) $c['customer_code'] : null,
            ]))
            ->map('strval')
            ->flip();

        $incomingProductSkus = collect($incomingProducts)
            ->map(fn ($p) => (string) ($p['sku_code'] ?? ''))
            ->filter()
            ->flip();

        $missingCustomers = collect($documents)->whereIn('kind', ['credit_sale', 'opening_ar', 'receipt'])
            ->unique(fn ($d) => ($d['customer_key'] ?? '').'|'.($d['customer_code'] ?? ''))
            ->reject(function ($d) use ($incomingCustomerKeys) {
                $key = isset($d['customer_key']) ? (string) $d['customer_key'] : null;
                $code = isset($d['customer_code']) ? (string) $d['customer_code'] : null;
                $name = $d['customer_name'] ?? null;

                if (self::matchCustomer($key, $code, $name)) {
                    return true;
                }
                if ($key !== null && isset($incomingCustomerKeys[$key])) {
                    return true;
                }
                if ($code !== null && isset($incomingCustomerKeys[$code])) {
                    return true;
                }

                return false;
            })
            ->map(fn ($d) => ($d['customer_code'] ?? '-'))->unique()->values();

        $skus = collect($documents)->flatMap(fn ($d) => collect($d['lines'] ?? [])->pluck('sku_code'))->filter()->unique()->values();
        $knownSkus = DB::table('products')->whereIn('sku_code', $skus)->pluck('sku_code')->flip();

        $missingProducts = $skus->reject(function ($sku) use ($knownSkus, $incomingProductSkus) {
            $skuStr = (string) $sku;

            return isset($knownSkus[$skuStr]) || isset($incomingProductSkus[$skuStr]);
        })->values();

        return [
            'branch' => $ctx['branch_name'] ?? null,
            'errors' => $ctx['errors'],
            'missing_customers' => $missingCustomers->all(),
            'missing_products' => $missingProducts->all(),
        ];
    }

    /** @return array{documents: int, problems: array<string, string>} */
    public function run(string $route): array
    {
        $this->problems = [];
        $this->ctx = $this->context($route, true);

        $rows = DB::table('bplus_sync_documents')->where('route_code', $route)
            ->orderByRaw("CASE kind WHEN 'booking' THEN 1 WHEN 'credit_sale' THEN 2 WHEN 'opening_ar' THEN 3 ELSE 4 END")
            ->orderBy('doc_date')->orderBy('bplus_key')->get();

        $count = DB::transaction(function () use ($rows) {
            $count = 0;
            foreach ($rows as $row) {
                $documentId = match ($row->kind) {
                    'booking' => $this->booking($row),
                    'credit_sale' => $this->creditSale($row),
                    'opening_ar' => $this->openingAr($row),
                    'receipt' => $this->receipt($row),
                    default => null,
                };
                DB::table('bplus_sync_documents')->where('id', $row->id)->update([
                    'document_id' => $documentId,
                    'problem' => $this->problems[$row->ref] ?? null,
                ]);
                $count += $documentId ? 1 : 0;
            }
            $this->settleOpenItems();
            $this->settleBookings();

            return $count;
        });

        return ['documents' => $count, 'problems' => $this->problems];
    }

    private function context(string $route, bool $strict): array
    {
        $types = DB::table('document_types')->whereIn('code', ['BOOKING', 'CREDIT_SALE', 'RECEIPT', 'OPENING_AR'])->pluck('id', 'code');
        $area = DB::table('sales_areas')->where('code', $route)->first();
        $branchId = $area?->branch_id ?? DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');
        $branch = $branchId ? DB::table('branches')->where('id', $branchId)->first() : null;
        $books = DB::table('document_books')->whereIn('document_type_id', $types->values())->get()
            ->mapWithKeys(fn ($b) => [$b->document_type_id.':'.$b->code => $b->id]);

        $errors = [];
        foreach (['BOOKING', 'CREDIT_SALE', 'RECEIPT'] as $code) {
            if (! isset($types[$code])) {
                $errors[] = "ไม่พบประเภทเอกสาร {$code}";
            }
        }
        if (! $branch) {
            $errors[] = 'ไม่พบสาขาของสาย '.$route;
        } elseif (! $branch->default_warehouse_location_id) {
            $errors[] = "สาขา {$branch->name_th} ยังไม่ได้กำหนดคลังเริ่มต้น";
        }
        if ($strict && $errors) {
            throw new RuntimeException(implode(' · ', $errors));
        }

        return [
            'errors' => $errors,
            'types' => $types,
            'books' => $books,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name_th,
            'location_id' => $branch?->default_warehouse_location_id,
            'sales_area_id' => $area?->id,
            'salesman_id' => $area?->default_salesman_id,
            'customers' => [],
        ];
    }

    private function booking(object $row): ?int
    {
        $customerId = $this->customerId($row);
        if (! $customerId && $row->is_cancelled && ! $row->customer_code) {
            // ใบจองที่ยกเลิกใน BPlus ก่อนเปิดขาย ไม่มีลูกค้าและไม่ต้องสร้าง ไม่นับเป็นปัญหา
            return DB::table('documents')->where('source_system', self::SOURCE)->where('source_key', $row->bplus_key)->value('id');
        }
        if (! $customerId) {
            // BPlus เก็บลูกค้าไว้ที่ใบขาย ใบจองที่ยังไม่เปิดขายจึงยังไม่รู้ลูกค้า ซิงค์รอบหน้าจะสร้างให้
            $this->problems[$row->ref] = $row->customer_code
                ? 'ไม่พบลูกค้ารหัส '.$row->customer_code.($row->customer_key ? " (เลข {$row->customer_key})" : '').' ใน ERP'
                : 'ยังไม่รู้ลูกค้า (ยังไม่ได้เปิดขายใน BPlus)';

            return DB::table('documents')->where('source_system', self::SOURCE)->where('source_key', $row->bplus_key)->value('id');
        }
        $id = $this->upsertDocument($row, 'BOOKING', $row->book_code, [
            'customer_id' => $customerId,
            'total_amount' => $row->total_amount,
            'subtotal_amount' => null,
            'vat_amount' => 0,
        ]);
        $this->replaceItems($id, $row);

        $values = [
            'salesman_id' => $this->ctx['salesman_id'],
            'sales_area_id' => $this->ctx['sales_area_id'],
            'fulfillment_type' => 'pickup',
        ];
        if (DB::table('sale_bookings')->where('document_id', $id)->exists()) {
            DB::table('sale_bookings')->where('document_id', $id)->update($values);
        } else {
            DB::table('sale_bookings')->insert($values + [
                'document_id' => $id,
                'status' => 'pending',
                'delivery_status' => 'pending',
            ]);
        }

        return $id;
    }

    private function creditSale(object $row): ?int
    {
        $customerId = $this->customerId($row);
        if (! $customerId) {
            $this->problems[$row->ref] = 'ไม่พบลูกค้ารหัส '.($row->customer_code ?: '-').($row->customer_key ? " (เลข {$row->customer_key})" : '').' ใน ERP';

            return null;
        }
        $bookingId = $row->booking_ref ? $this->documentIdByNumber($row->booking_ref) : null;
        $this->adoptOpening($row);
        $total = (float) $row->total_amount;
        $vat = (float) $row->vat_amount;

        $id = $this->upsertDocument($row, 'CREDIT_SALE', $row->book_code, [
            'customer_id' => $customerId,
            'reference' => $row->booking_ref,
            'source_document_id' => $bookingId,
            'total_amount' => $total,
            'subtotal_amount' => round($total - $vat, 2),
            'vat_amount' => $vat,
        ]);
        $this->replaceItems($id, $row);

        $item = [
            'customer_id' => $customerId,
            'salesman_id' => $this->ctx['salesman_id'],
            'gross_amount' => $total,
            'vat_amount' => $vat,
            'discount_amount' => 0,
            'net_amount' => $total,
            'due_date' => $row->due_date,
        ];
        if (DB::table('customer_open_items')->where('document_id', $id)->exists()) {
            DB::table('customer_open_items')->where('document_id', $id)->update($item);
        } else {
            DB::table('customer_open_items')->insert($item + [
                'document_id' => $id,
                'paid_amount' => 0,
                'balance_amount' => $total,
                'status' => 'open',
                'created_at' => now(),
            ]);
        }

        $this->materializeCreditSaleGl($id, $row);

        return $id;
    }

    private function receipt(object $row): ?int
    {
        $customerId = $this->customerId($row);
        if (! $customerId) {
            $this->problems[$row->ref] = 'ไม่พบลูกค้ารหัส '.($row->customer_code ?: '-').($row->customer_key ? " (เลข {$row->customer_key})" : '').' ใน ERP';

            return null;
        }
        $id = $this->upsertDocument($row, 'RECEIPT', null, [
            'customer_id' => $customerId,
            'total_amount' => $row->total_amount,
            'subtotal_amount' => null,
            'vat_amount' => 0,
        ]);

        $payment = DB::table('payment_documents')->where('document_id', $id)->first();
        $paymentValues = [
            'party_type' => 'customer',
            'customer_id' => $customerId,
            'branch_id' => $this->ctx['branch_id'],
            'salesman_id' => $this->ctx['salesman_id'],
            'status' => $row->is_cancelled ? 'cancelled' : 'active',
            'rounding_amount' => 0,
        ];
        if ($payment) {
            $paymentId = $payment->id;
            DB::table('payment_documents')->where('id', $paymentId)->update($paymentValues);
        } else {
            $paymentId = DB::table('payment_documents')->insertGetId($paymentValues + ['document_id' => $id, 'created_at' => now()]);
        }

        DB::table('payment_lines')->where('payment_document_id', $paymentId)->delete();
        $seq = 1;
        foreach (['cash' => $row->cash_amount, 'transfer' => $row->transfer_amount, 'other' => $row->other_amount] as $method => $amount) {
            if ((float) $amount > 0) {
                DB::table('payment_lines')->insert([
                    'payment_document_id' => $paymentId,
                    'seq' => $seq++,
                    'method' => $method,
                    'amount' => $amount,
                ]);
            }
        }

        DB::table('payment_allocations')->where('payment_document_id', $paymentId)->delete();
        $missing = [];
        foreach (DB::table('bplus_sync_allocations')->where('bplus_sync_document_id', $row->id)->get() as $allocation) {
            $saleId = $this->documentIdByNumber($allocation->sale_ref);
            $openItemId = $saleId ? DB::table('customer_open_items')->where('document_id', $saleId)->value('id') : null;
            if (! $openItemId) {
                $missing[] = $allocation->sale_ref;

                continue;
            }
            DB::table('payment_allocations')->insert([
                'payment_document_id' => $paymentId,
                'customer_open_item_id' => $openItemId,
                'allocated_amount' => $allocation->paid_amount,
                'discount_amount' => $allocation->discount_amount,
                'wht_amount' => $allocation->wht_amount,
            ]);
        }
        if ($missing) {
            $this->problems[$row->ref] = 'บิลที่รับชำระไม่อยู่ใน ERP (ก่อนช่วงที่ดึง): '.implode(', ', $missing);
        }

        $this->materializeReceiptGl($id, $row);

        return $id;
    }

    private function upsertDocument(object $row, string $type, ?string $bookCode, array $values): int
    {
        $typeId = $this->ctx['types'][$type];
        $values += ['reference' => null, 'source_document_id' => null];
        $header = $values + [
            'document_type_id' => $typeId,
            'document_book_id' => $bookCode ? ($this->ctx['books'][$typeId.':'.$bookCode] ?? null) : null,
            'branch_id' => $this->ctx['branch_id'],
            'doc_number' => $row->ref,
            'doc_date' => $row->doc_date,
            'salesman_id' => $this->ctx['salesman_id'],
            'sales_area_id' => $this->ctx['sales_area_id'],
            'status' => $row->is_cancelled ? 'cancelled' : 'active',
            'cancelled_at' => $row->is_cancelled ? ($row->bplus_updated_at ?? now()) : null,
            'total_items' => DB::table('bplus_sync_lines')->where('bplus_sync_document_id', $row->id)->count(),
            'prices_include_vat' => true,
            'claim_input_vat' => false,
            'remark' => 'จาก BPlus ผู้คีย์ '.($row->created_by ?: '-'),
            'updated_at' => now(),
        ];

        $id = DB::table('documents')->where('source_system', self::SOURCE)->where('source_key', $row->bplus_key)->value('id');
        if ($id) {
            DB::table('documents')->where('id', $id)->update($header);

            return $id;
        }

        return DB::table('documents')->insertGetId($header + [
            'source_system' => self::SOURCE,
            'source_key' => $row->bplus_key,
            'created_at' => now(),
        ]);
    }

    private function replaceItems(int $documentId, object $row): void
    {
        $stockIds = DB::table('stock_documents')->where('document_id', $documentId)->pluck('id');
        DB::table('stock_document_items')->whereIn('stock_document_id', $stockIds)->delete();
        DB::table('stock_documents')->whereIn('id', $stockIds)->delete();

        $lines = DB::table('bplus_sync_lines')->where('bplus_sync_document_id', $row->id)->orderBy('seq')->get();
        $products = DB::table('products')->whereIn('sku_code', $lines->pluck('sku_code')->filter())->get(['id', 'sku_code', 'base_unit_id'])->keyBy('sku_code');
        $units = DB::table('product_barcodes')->whereIn('product_id', $products->pluck('id'))->where('is_active', true)
            ->get(['product_id', 'unit_id', 'unit_factor']);

        $items = [];
        $missing = [];
        foreach ($lines as $line) {
            $product = $products[$line->sku_code] ?? null;
            if (! $product) {
                $missing[] = $line->sku_code ?: '?';

                continue;
            }
            $factor = (float) $line->unit_factor ?: 1.0;
            $unitId = $units->first(fn ($u) => $u->product_id === $product->id && abs((float) $u->unit_factor - $factor) < 0.0001)?->unit_id
                ?? ($factor == 1.0 ? $product->base_unit_id : null);
            $baseQty = round((float) $line->qty * $factor, 4);
            $items[] = [
                'seq' => $line->seq,
                'product_id' => $product->id,
                'warehouse_location_id' => $this->ctx['location_id'],
                'qty' => $baseQty,
                // ราคาต่อหน่วยฐาน ให้ qty x unit_price = ยอดบรรทัด (แบบเดียวกับใบที่ ERP สร้าง)
                'unit_price' => $baseQty > 0 ? round((float) $line->amount / $baseQty, 4) : 0,
                'unit_id' => $unitId,
                'unit_qty' => $line->qty,
                'unit_factor' => $factor,
                'vat_amount' => 0,
                'billed_qty' => 0,
            ];
        }
        if ($missing) {
            $this->problems[$row->ref] = 'ไม่พบสินค้ารหัส '.implode(', ', array_unique($missing)).' ใน ERP';
        }
        if (! $items) {
            return;
        }

        $stockId = DB::table('stock_documents')->insertGetId([
            'document_id' => $documentId,
            'total_qty' => array_sum(array_column($items, 'qty')),
            'total_items' => count($items),
            'created_at' => now(),
        ]);
        foreach ($items as $item) {
            DB::table('stock_document_items')->insert($item + ['stock_document_id' => $stockId]);
        }
    }

    /** ยอดชำระ/คงค้างของลูกหนี้จากใบเสร็จ BPlus ที่ยังไม่ยกเลิก */
    private function settleOpenItems(): void
    {
        $items = DB::table('customer_open_items as o')
            ->join('documents as d', 'd.id', '=', 'o.document_id')
            ->where('d.source_system', self::SOURCE)
            ->get(['o.id', 'o.net_amount', 'd.status']);
        foreach ($items as $item) {
            $paid = (float) DB::table('payment_allocations as a')
                ->join('payment_documents as p', 'p.id', '=', 'a.payment_document_id')
                ->where('a.customer_open_item_id', $item->id)->where('p.status', 'active')
                ->sum(DB::raw('a.allocated_amount + a.discount_amount + a.wht_amount'));
            $paid = round($paid, 2);
            $balance = round((float) $item->net_amount - $paid, 2);
            $status = $item->status === 'cancelled' ? 'cancelled' : ($balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'open'));
            DB::table('customer_open_items')->where('id', $item->id)->update([
                'paid_amount' => $paid,
                'balance_amount' => max($balance, 0),
                'status' => $status,
            ]);
        }
    }

    /** สถานะใบจองตามใบขายที่เปิดจากใบจองนั้นใน BPlus */
    private function settleBookings(): void
    {
        $bookingTypeId = $this->ctx['types']['BOOKING'];
        $saleTypeId = $this->ctx['types']['CREDIT_SALE'];
        $bookings = DB::table('documents')->where('source_system', self::SOURCE)->where('document_type_id', $bookingTypeId)->get(['id', 'status']);
        foreach ($bookings as $booking) {
            $sale = DB::table('documents')->where('document_type_id', $saleTypeId)->where('source_document_id', $booking->id)
                ->where('status', 'active')->orderBy('doc_date')->orderBy('id')->first(['id', 'doc_date']);
            $values = $booking->status === 'cancelled'
                ? ['status' => 'cancelled', 'delivery_status' => 'cancelled']
                : ($sale
                    ? ['status' => 'converted_to_sale', 'confirmed_document_id' => $sale->id, 'confirmed_at' => $sale->doc_date, 'delivery_status' => 'delivered']
                    : ['status' => 'pending', 'confirmed_document_id' => null, 'confirmed_at' => null, 'delivery_status' => 'pending']);
            DB::table('sale_bookings')->where('document_id', $booking->id)->update($values);

            if ($sale) {
                $ids = DB::table('stock_documents')->where('document_id', $booking->id)->pluck('id');
                DB::table('stock_document_items')->whereIn('stock_document_id', $ids)->update(['billed_qty' => DB::raw('qty')]);
            }
        }
    }

    /**
     * ลูกค้าใน ERP ใช้ AR_KEY ของ BPlus เป็นรหัส (เช่น 8049) ไม่ใช่ AR_CODE (34140-0121)
     * จับคู่ตามลำดับ: AR_KEY -> AR_CODE -> ชื่อที่ตรงกันเพียงรายเดียว
     */
    private function customerId(object $row): ?int
    {
        $cacheKey = ($row->customer_key ?? '').'|'.($row->customer_code ?? '');
        if (array_key_exists($cacheKey, $this->ctx['customers'])) {
            return $this->ctx['customers'][$cacheKey];
        }

        return $this->ctx['customers'][$cacheKey] = self::matchCustomer($row->customer_key ?? null, $row->customer_code ?? null, $row->customer_name ?? null);
    }

    public static function matchCustomer(?string $key, ?string $code, ?string $name): ?int
    {
        foreach (array_filter([$key, $code]) as $candidate) {
            $id = DB::table('customers')->where('code', $candidate)->value('id');
            if ($id) {
                return $id;
            }
        }
        if ($name && trim($name) !== '') {
            $ids = DB::table('customers')->where('name_th', trim($name))->limit(2)->pluck('id');
            if ($ids->count() > 0) {
                return $ids->count() === 1 ? $ids->first() : null;
            }

            // BPlus กับ ERP เว้นวรรคชื่อไม่เหมือนกัน เช่น "ร้าน  ก" กับ "ร้าน ก" ถ้าตัดช่องว่างแล้วเหลือรายเดียวถือว่าคนเดียวกัน
            $spaces = [' ', "\t", "\u{00A0}", "\u{200B}"];
            $squeezed = str_replace($spaces, '', $name);
            if ($squeezed !== '') {
                $column = 'name_th';
                foreach ($spaces as $ignored) {
                    $column = "REPLACE({$column}, ?, '')";
                }
                $ids = DB::table('customers')->whereRaw("{$column} = ?", [...$spaces, $squeezed])->limit(2)->pluck('id');
                if ($ids->count() === 1) {
                    return $ids->first();
                }
            }
        }

        return null;
    }

    private function documentIdByNumber(string $number): ?int
    {
        // เลขเดียวกันอาจมีทั้งลูกหนี้ยกมาที่เลิกใช้แล้วและบิลตัวจริง ให้ใบที่ยังใช้งานมาก่อน
        return DB::table('documents')->where('source_system', self::SOURCE)->where('doc_number', $number)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('id')->value('id');
    }

    /**
     * ลูกหนี้ยกมา: บิลของสายนี้ที่ออกก่อนช่วงที่ดึง และยังค้าง ณ วันเริ่มช่วง (ยอด = ยอดค้าง ณ วันนั้น)
     * ไม่ใช่ยอดขาย จึงเป็นเอกสาร OPENING_AR ไม่เข้ารายงานขาย/ภาษีขาย
     * ลง GL: Dr ลูกหนี้ / Cr บัญชีพักยอดยกมา เพื่อให้ใบเสร็จในช่วงตัดหนี้ได้โดยลูกหนี้ไม่ติดลบ
     */
    private function openingAr(object $row): ?int
    {
        $existingId = DB::table('documents')->where('source_system', self::SOURCE)->where('source_key', $row->bplus_key)->value('id');
        // บิลตัวจริงซิงค์ไว้แล้ว (เช่น เดือนก่อน) ไม่ต้องตั้งยอดยกมาซ้ำ
        $saleTypeId = $this->ctx['types']['CREDIT_SALE'] ?? null;
        if ($saleTypeId && DB::table('documents')->where('source_system', self::SOURCE)->where('document_type_id', $saleTypeId)
            ->where('doc_number', $row->ref)->exists()) {
            return null;
        }
        if (! isset($this->ctx['types']['OPENING_AR'])) {
            $this->problems[$row->ref] = 'ไม่พบประเภทเอกสาร OPENING_AR (ลูกหนี้ยกมา)';

            return $existingId;
        }
        $customerId = $this->customerId($row);
        if (! $customerId) {
            $this->problems[$row->ref] = 'ไม่พบลูกค้ารหัส '.($row->customer_code ?: '-').($row->customer_key ? " (เลข {$row->customer_key})" : '').' ใน ERP';

            return $existingId;
        }
        $total = round((float) $row->total_amount, 2);
        $id = $this->upsertDocument($row, 'OPENING_AR', null, [
            'customer_id' => $customerId,
            'reference' => 'ลูกหนี้ยกมา',
            'total_amount' => $total,
            'subtotal_amount' => $total,
            'vat_amount' => 0,
        ]);

        $item = [
            'customer_id' => $customerId,
            'salesman_id' => $this->ctx['salesman_id'],
            'gross_amount' => $total,
            'vat_amount' => 0,
            'discount_amount' => 0,
            'net_amount' => $total,
            'due_date' => $row->due_date,
        ];
        if (DB::table('customer_open_items')->where('document_id', $id)->exists()) {
            DB::table('customer_open_items')->where('document_id', $id)->update($item);
        } else {
            DB::table('customer_open_items')->insert($item + [
                'document_id' => $id,
                'paid_amount' => 0,
                'balance_amount' => $total,
                'status' => 'open',
                'created_at' => now(),
            ]);
        }

        $document = Document::find($id);
        $ar = $this->glPostingService->role(ChartOfAccount::ROLE_AR);
        $opening = $this->glPostingService->role(ChartOfAccount::ROLE_OPENING_BALANCE);
        if (! $ar || ! $opening) {
            $this->problems[$row->ref] = 'ยังไม่ผูกบัญชี '.(! $ar ? 'ar' : 'opening_balance');
        } elseif ($document && DecimalMath::compare($total, 0) > 0) {
            try {
                $this->glPostingService->postResolved($document, [[$ar->id, $total, 0], [$opening->id, 0, $total]], 'ลูกหนี้ยกมา '.$row->ref);
            } catch (Throwable $e) {
                $this->problems[$row->ref] = $e->getMessage();
            }
        }

        return $id;
    }

    /**
     * บิลตัวจริงเข้ามาแทนลูกหนี้ยกมาของบิลเดียวกัน (เลขเอกสารซ้ำในสาขาเดียวกันไม่ได้)
     * ใช้เอกสารเดิมต่อ: เปลี่ยนเป็นใบขายเชื่อของบิลนั้น การตัดหนี้เดิมยังอยู่
     * GL ใช้ postResolved ชุดเดิม ระบบกลับรายการยอดยกมาแล้วลงรายการขายให้เอง
     */
    private function adoptOpening(object $row): void
    {
        $openingTypeId = $this->ctx['types']['OPENING_AR'] ?? null;
        if (! $openingTypeId || DB::table('documents')->where('source_system', self::SOURCE)->where('source_key', $row->bplus_key)->exists()) {
            return;
        }
        $openingId = DB::table('documents')->where('source_system', self::SOURCE)->where('document_type_id', $openingTypeId)
            ->where('doc_number', $row->ref)->value('id');
        if (! $openingId) {
            return;
        }
        DB::table('documents')->where('id', $openingId)->update([
            'source_key' => $row->bplus_key,
            'document_type_id' => $this->ctx['types']['CREDIT_SALE'],
            'updated_at' => now(),
        ]);
        DB::table('bplus_sync_documents')->where('document_id', $openingId)->where('kind', 'opening_ar')->update(['document_id' => null]);
    }

    private function materializeCreditSaleGl(int $documentId, object $row): void
    {
        $document = Document::find($documentId);
        if (! $document) {
            return;
        }

        if ($row->is_cancelled) {
            try {
                $this->glPostingService->reverseDocument($document, 'ยกเลิกจาก BPlus '.$row->ref);
            } catch (Throwable $e) {
                $this->problems[$row->ref] = $e->getMessage();
            }

            return;
        }

        $total = round((float) $row->total_amount, 2);
        $vat = round((float) $row->vat_amount, 2);
        $subtotal = DecimalMath::subtract($total, $vat, 2);

        $arAccount = $this->glPostingService->role(ChartOfAccount::ROLE_AR);
        $revAccount = $this->glPostingService->role(ChartOfAccount::ROLE_SALES_REVENUE);
        $vatAccount = DecimalMath::compare($vat, 0) > 0
            ? $this->glPostingService->role(ChartOfAccount::ROLE_VAT_OUTPUT)
            : null;

        if (! $arAccount) {
            $this->problems[$row->ref] = 'ยังไม่ผูกบัญชี ar';

            return;
        }
        if (! $revAccount) {
            $this->problems[$row->ref] = 'ยังไม่ผูกบัญชี sales_revenue';

            return;
        }
        if (DecimalMath::compare($vat, 0) > 0 && ! $vatAccount) {
            $this->problems[$row->ref] = 'ยังไม่ผูกบัญชี vat_output';

            return;
        }

        $resolved = [
            [$arAccount->id, $total, 0],
            [$revAccount->id, 0, $subtotal],
        ];
        if (DecimalMath::compare($vat, 0) > 0 && $vatAccount) {
            $resolved[] = [$vatAccount->id, 0, $vat];
        }

        try {
            $this->glPostingService->postResolved($document, $resolved, 'ขายเชื่อ '.$document->doc_number);
        } catch (Throwable $e) {
            $this->problems[$row->ref] = $e->getMessage();
        }
    }

    private function materializeReceiptGl(int $documentId, object $row): void
    {
        $document = Document::find($documentId);
        if (! $document) {
            return;
        }

        if ($row->is_cancelled) {
            try {
                $this->glPostingService->reverseDocument($document, 'ยกเลิกจาก BPlus '.$row->ref);
            } catch (Throwable $e) {
                $this->problems[$row->ref] = $e->getMessage();
            }

            return;
        }

        $totalAmount = round((float) ($row->total_amount ?? 0), 2);
        $cashAmount = round((float) ($row->cash_amount ?? 0), 2);
        $otherAmount = round((float) ($row->other_amount ?? 0), 2);
        $transferAmount = round((float) ($row->transfer_amount ?? 0), 2);
        $shortAmount = round((float) ($row->short_amount ?? 0), 2);

        $totalCash = DecimalMath::add($cashAmount, $otherAmount, 2);

        $arAccount = $this->glPostingService->role(ChartOfAccount::ROLE_AR);
        if (! $arAccount) {
            $this->problems[$row->ref] = 'ยังไม่ผูกบัญชี ar';

            return;
        }

        $cashAccount = null;
        if (DecimalMath::compare($totalCash, 0) > 0) {
            $cashAccount = $this->glPostingService->role(ChartOfAccount::ROLE_CASH);
            if (! $cashAccount) {
                $this->problems[$row->ref] = 'ยังไม่ผูกบัญชี cash';

                return;
            }
        }

        $bankAccount = null;
        if (DecimalMath::compare($transferAmount, 0) > 0) {
            $bankAccount = $this->glPostingService->bankAccountFor($this->ctx['branch_id'] ?? $document->branch_id);
            if (! $bankAccount) {
                $this->problems[$row->ref] = 'ยังไม่ผูกบัญชี bank';

                return;
            }
        }

        $roundingAccount = null;
        if (DecimalMath::compare($shortAmount, 0) > 0) {
            $roundingAccount = $this->roundingAccount();
            if (! $roundingAccount) {
                $this->problems[$row->ref] = 'ยังไม่ผูกบัญชี rounding';

                return;
            }
        }

        $resolved = [];
        if (DecimalMath::compare($totalCash, 0) > 0) {
            $resolved[] = [$cashAccount->id, $totalCash, 0];
        }
        if (DecimalMath::compare($transferAmount, 0) > 0) {
            $resolved[] = [$bankAccount->id, $transferAmount, 0];
        }
        if (DecimalMath::compare($shortAmount, 0) > 0) {
            $resolved[] = [$roundingAccount->id, $shortAmount, 0];
        }
        $resolved[] = [$arAccount->id, 0, $totalAmount];

        $sumDr = DecimalMath::sum(array_column($resolved, 1), 2);
        $sumCr = DecimalMath::sum(array_column($resolved, 2), 2);
        if (DecimalMath::compare($sumDr, $sumCr) !== 0) {
            $this->problems[$row->ref] = "ยอดเดบิต ({$sumDr}) ไม่เท่ากับเครดิต ({$sumCr})";

            return;
        }

        $remark = 'ใบเสร็จรับเงิน '.$document->doc_number;
        if (DecimalMath::compare($otherAmount, 0) > 0) {
            $remark .= ' (รวมยอดอื่น '.number_format((float) $otherAmount, 2).' เข้าเงินสด)';
        }

        try {
            $this->glPostingService->postResolved($document, $resolved, $remark);
        } catch (Throwable $e) {
            $this->problems[$row->ref] = $e->getMessage();
        }
    }

    private function roundingAccount(): ?ChartOfAccount
    {
        return ChartOfAccount::where('name_th', 'like', '%เศษสตางค์%')
            ->orWhere('name_en', 'like', '%rounding%')
            ->orWhere('default_role', 'rounding_diff')
            ->first()
            ?? $this->glPostingService->role(ChartOfAccount::ROLE_EXPENSE)
            ?? ChartOfAccount::where('code', '5020')->first();
    }

    /**
     * คำนวณหรือลงบัญชี GL ใหม่สำหรับเอกสาร BPlus ทั้งสาย
     * ใช้เมื่อสำนักงานบัญชีเปลี่ยนเลขบัญชีของแต่ละ role
     *
     * @return array{count: int, accounts: array<int, array{account_id: int, code: string, name: string, debit: string, credit: string}>, total_debit: string, total_credit: string, problems: array<string, string>}
     */
    public function repostGl(string $route, bool $dryRun = false): array
    {
        $rows = DB::table('bplus_sync_documents')
            ->where('route_code', $route)
            ->whereNotNull('document_id')
            ->whereIn('kind', ['credit_sale', 'receipt'])
            ->orderBy('doc_date')
            ->orderBy('id')
            ->get();

        $accounts = ChartOfAccount::all()->keyBy('id');
        $arAccount = $this->glPostingService->role(ChartOfAccount::ROLE_AR);
        $revAccount = $this->glPostingService->role(ChartOfAccount::ROLE_SALES_REVENUE);
        $vatAccount = $this->glPostingService->role(ChartOfAccount::ROLE_VAT_OUTPUT);
        $cashAccount = $this->glPostingService->role(ChartOfAccount::ROLE_CASH);
        $roundingAccount = $this->roundingAccount();

        $area = DB::table('sales_areas')->where('code', $route)->first();
        $branchId = $area?->branch_id ?? DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');
        $bankAccount = $this->glPostingService->bankAccountFor($branchId);

        $summary = [];
        $totalDebit = '0';
        $totalCredit = '0';
        $processedCount = 0;
        $problems = [];

        foreach ($rows as $row) {
            $document = Document::find($row->document_id);
            if (! $document) {
                continue;
            }

            if ($row->is_cancelled || $document->status === 'cancelled') {
                if (! $dryRun) {
                    try {
                        $this->glPostingService->reverseDocument($document, 'ยกเลิกจาก BPlus '.$document->doc_number);
                    } catch (Throwable $e) {
                        $problems[$row->ref] = $e->getMessage();
                    }
                }

                continue;
            }

            $resolved = [];
            $remark = '';

            if ($row->kind === 'credit_sale') {
                $total = round((float) $row->total_amount, 2);
                $vat = round((float) $row->vat_amount, 2);
                $subtotal = DecimalMath::subtract($total, $vat, 2);

                if (! $arAccount) {
                    $problems[$row->ref] = 'ยังไม่ผูกบัญชี ar';

                    continue;
                }
                if (! $revAccount) {
                    $problems[$row->ref] = 'ยังไม่ผูกบัญชี sales_revenue';

                    continue;
                }
                if (DecimalMath::compare($vat, 0) > 0 && ! $vatAccount) {
                    $problems[$row->ref] = 'ยังไม่ผูกบัญชี vat_output';

                    continue;
                }

                $resolved = [
                    [$arAccount->id, $total, 0],
                    [$revAccount->id, 0, $subtotal],
                ];
                if (DecimalMath::compare($vat, 0) > 0 && $vatAccount) {
                    $resolved[] = [$vatAccount->id, 0, $vat];
                }
                $remark = 'ขายเชื่อ '.$document->doc_number;
            } elseif ($row->kind === 'receipt') {
                $totalAmount = round((float) ($row->total_amount ?? 0), 2);
                $cashAmount = round((float) ($row->cash_amount ?? 0), 2);
                $otherAmount = round((float) ($row->other_amount ?? 0), 2);
                $transferAmount = round((float) ($row->transfer_amount ?? 0), 2);
                $shortAmount = round((float) ($row->short_amount ?? 0), 2);

                $totalCash = DecimalMath::add($cashAmount, $otherAmount, 2);

                if (! $arAccount) {
                    $problems[$row->ref] = 'ยังไม่ผูกบัญชี ar';

                    continue;
                }
                if (DecimalMath::compare($totalCash, 0) > 0 && ! $cashAccount) {
                    $problems[$row->ref] = 'ยังไม่ผูกบัญชี cash';

                    continue;
                }
                if (DecimalMath::compare($transferAmount, 0) > 0 && ! $bankAccount) {
                    $problems[$row->ref] = 'ยังไม่ผูกบัญชี bank';

                    continue;
                }
                if (DecimalMath::compare($shortAmount, 0) > 0 && ! $roundingAccount) {
                    $problems[$row->ref] = 'ยังไม่ผูกบัญชี rounding';

                    continue;
                }

                if (DecimalMath::compare($totalCash, 0) > 0) {
                    $resolved[] = [$cashAccount->id, $totalCash, 0];
                }
                if (DecimalMath::compare($transferAmount, 0) > 0) {
                    $resolved[] = [$bankAccount->id, $transferAmount, 0];
                }
                if (DecimalMath::compare($shortAmount, 0) > 0) {
                    $resolved[] = [$roundingAccount->id, $shortAmount, 0];
                }
                $resolved[] = [$arAccount->id, 0, $totalAmount];

                $sumDr = DecimalMath::sum(array_column($resolved, 1), 2);
                $sumCr = DecimalMath::sum(array_column($resolved, 2), 2);
                if (DecimalMath::compare($sumDr, $sumCr) !== 0) {
                    $problems[$row->ref] = "ยอดเดบิต ({$sumDr}) ไม่เท่ากับเครดิต ({$sumCr})";

                    continue;
                }

                $remark = 'ใบเสร็จรับเงิน '.$document->doc_number;
                if (DecimalMath::compare($otherAmount, 0) > 0) {
                    $remark .= ' (รวมยอดอื่น '.number_format((float) $otherAmount, 2).' เข้าเงินสด)';
                }
            }

            if (! empty($resolved)) {
                $processedCount++;
                foreach ($resolved as [$accId, $dr, $cr]) {
                    if (! isset($summary[$accId])) {
                        $acc = $accounts[$accId] ?? ChartOfAccount::find($accId);
                        $summary[$accId] = [
                            'account_id' => $accId,
                            'code' => $acc?->code ?? (string) $accId,
                            'name' => $acc?->name_th ?? $acc?->name_en ?? '-',
                            'debit' => '0',
                            'credit' => '0',
                        ];
                    }
                    $summary[$accId]['debit'] = DecimalMath::add($summary[$accId]['debit'], $dr, 2);
                    $summary[$accId]['credit'] = DecimalMath::add($summary[$accId]['credit'], $cr, 2);
                    $totalDebit = DecimalMath::add($totalDebit, $dr, 2);
                    $totalCredit = DecimalMath::add($totalCredit, $cr, 2);
                }

                if (! $dryRun) {
                    try {
                        $this->glPostingService->postResolved($document, $resolved, $remark);
                    } catch (Throwable $e) {
                        $problems[$row->ref] = $e->getMessage();
                    }

                    DB::table('bplus_sync_documents')->where('id', $row->id)->update([
                        'problem' => $problems[$row->ref] ?? null,
                    ]);
                }
            }
        }

        uasort($summary, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return [
            'count' => $processedCount,
            'accounts' => array_values($summary),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'problems' => $problems,
        ];
    }
}
