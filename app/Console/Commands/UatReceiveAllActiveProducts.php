<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Document;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseService;
use App\Support\DecimalMath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UatReceiveAllActiveProducts extends Command
{
    protected $signature = 'erp:uat-receive-all-active-products
        {--branch=B001 : รหัสสาขารับสินค้า}
        {--supplier=UAT-POS-TEST : รหัสผู้ขายสำหรับเอกสาร UAT}
        {--actor= : username ของผู้ดูแลระบบผู้บันทึกเอกสาร (จำเป็นเมื่อ --execute)}
        {--reference= : เลขอ้างอิง UAT ที่ไม่ซ้ำ (ค่าเริ่มต้นสร้างจากสาขาและวันที่)}
        {--lots= : CSV lot metadata: sku_code,expiry_date,manufacture_date,lot_number}
        {--execute : บันทึกใบซื้อจริง เพิ่มสต๊อก ตั้งเจ้าหนี้ และลง GL}
        {--max-total= : เพดานยอดซื้อรวม (บาท) จำเป็นเมื่อ --execute}
        {--confirm= : ต้องระบุ RECEIVE-ALL-ACTIVE-PRODUCTS เพื่อยืนยันการบันทึกจริง}';

    protected $description = 'Preview or receive only the stock shortfall needed to sell every active product unit once for UAT';

    public function handle(PurchaseService $purchases): int
    {
        try {
            $branch = Branch::query()->where('code', trim((string) $this->option('branch')))
                ->where('is_active', true)->first();
            if (! $branch) {
                throw new RuntimeException('ไม่พบสาขาที่เปิดใช้งานตามรหัสที่ระบุ');
            }
            if (! $branch->default_warehouse_location_id) {
                throw new RuntimeException("สาขา {$branch->code} ยังไม่มีคลังเริ่มต้น");
            }

            $supplier = Supplier::query()->where('code', trim((string) $this->option('supplier')))
                ->where('is_active', true)->first();
            if (! $supplier) {
                throw new RuntimeException('ไม่พบผู้ขาย UAT ที่เปิดใช้งานตามรหัสที่ระบุ');
            }

            $reference = trim((string) ($this->option('reference') ?: 'UAT-ALLSKU-'.$branch->code.'-'.now()->format('Ymd')));
            if (strlen($reference) > 100) {
                throw new RuntimeException('reference ยาวเกิน 100 ตัวอักษร');
            }
            if (Document::query()->where('branch_id', $branch->id)->where('reference', $reference)->exists()) {
                throw new RuntimeException("มีเอกสารอ้างอิง {$reference} อยู่แล้ว ป้องกันการรับซ้ำ");
            }

            $products = Product::query()->where('is_active', true)
                ->with(['baseUnit', 'barcodes' => fn ($query) => $query->where('is_active', true)->with('unit')])
                ->orderBy('sku_code')->get();
            $productIds = $products->modelKeys();
            $locationId = (int) $branch->default_warehouse_location_id;
            $balances = StockBalance::query()->where('warehouse_location_id', $locationId)
                ->whereIn('product_id', $productIds)->get()->keyBy('product_id');
            $lots = StockLot::query()->where('warehouse_location_id', $locationId)
                ->whereIn('product_id', $productIds)->where('remaining_qty', '>', 0)->get()->groupBy('product_id');
            $lotMetadata = $this->readLotMetadata((string) $this->option('lots'));

            $items = [];
            $missingCost = [];
            $missingLotData = [];
            $alreadyCovered = 0;
            $targetTotalQty = '0';
            $currentAvailableQty = '0';
            $estimatedTotal = '0';

            foreach ($products as $product) {
                $targetQty = $this->targetBaseQuantity($product);
                $targetTotalQty = DecimalMath::add($targetTotalQty, $targetQty, DecimalMath::QUANTITY_SCALE);
                $balance = $balances->get($product->id);
                $onHand = $balance?->on_hand_qty ?? 0;
                $reserved = $balance?->reserved_qty ?? 0;
                $availableByBalance = DecimalMath::compare(DecimalMath::subtract($onHand, $reserved), 0) > 0
                    ? DecimalMath::subtract($onHand, $reserved)
                    : '0';
                $productLots = $lots->get($product->id, collect());
                $allLotQty = DecimalMath::sum($productLots->pluck('remaining_qty'), DecimalMath::QUANTITY_SCALE);
                $usableLotQty = DecimalMath::sum($productLots->filter(fn (StockLot $lot) => $lot->quality_status === 'available'
                    && (! $product->tracks_expiry || ($product->expiry_sale_policy ?? 'block') !== 'block'
                        || ! $lot->expiry_date || $lot->expiry_date->toDateString() >= today()->toDateString())
                )->pluck('remaining_qty'), DecimalMath::QUANTITY_SCALE);
                // Balance quantities without a corresponding lot are treated like the opening lots created by FIFO on first issue.
                $untrackedLotQty = DecimalMath::compare($onHand, $allLotQty) > 0
                    ? DecimalMath::subtract($onHand, $allLotQty, DecimalMath::QUANTITY_SCALE)
                    : '0';
                $availableByLots = DecimalMath::add($usableLotQty, $untrackedLotQty, DecimalMath::QUANTITY_SCALE);
                $available = DecimalMath::compare($availableByBalance, $availableByLots) < 0 ? $availableByBalance : $availableByLots;
                $currentAvailableQty = DecimalMath::add($currentAvailableQty, $available, DecimalMath::QUANTITY_SCALE);
                $shortfall = DecimalMath::compare($targetQty, $available) > 0
                    ? DecimalMath::subtract($targetQty, $available, DecimalMath::QUANTITY_SCALE)
                    : '0';
                if (DecimalMath::compare($shortfall, 0) <= 0) {
                    $alreadyCovered++;

                    continue;
                }

                $unitCost = DecimalMath::compare($product->last_purchase_cost ?? 0, 0) > 0
                    ? (string) $product->last_purchase_cost
                    : (DecimalMath::compare($product->average_cost ?? 0, 0) > 0 ? (string) $product->average_cost : null);
                if ($unitCost === null) {
                    $missingCost[] = $product->sku_code.' '.$product->name_th;

                    continue;
                }

                $lineAmount = DecimalMath::multiply($shortfall, $unitCost);
                $estimatedTotal = DecimalMath::add($estimatedTotal, $lineAmount);
                $lotInfo = $lotMetadata[$product->sku_code] ?? null;
                if ($product->tracks_expiry && empty($lotInfo['expiry_date'])) {
                    $missingLotData[] = $product->sku_code.' '.$product->name_th;
                }

                $items[] = [
                    'product_id' => (int) $product->id,
                    'sku_code' => (string) $product->sku_code,
                    'name' => (string) $product->name_th,
                    'qty' => $shortfall,
                    'unit_price' => $unitCost,
                    'lot_number' => $lotInfo['lot_number'] ?? null,
                    'manufacture_date' => $lotInfo['manufacture_date'] ?? null,
                    'expiry_date' => $lotInfo['expiry_date'] ?? null,
                ];
            }

            $this->info('โหมด '.($this->option('execute') ? 'บันทึกรับเข้าจริง' : 'ตรวจสอบอย่างเดียว (dry-run)'));
            $this->line("สาขา: {$branch->code} — {$branch->name_th}");
            $this->line("ผู้ขาย: {$supplier->code} — {$supplier->name_th}");
            $this->line("อ้างอิง: {$reference}");
            $this->line('นโยบาย: สินค้าเปิดใช้ทั้งหมด, เติมเฉพาะยอดขาดให้ขายได้ 1 ครั้งต่อหน่วยขายที่มี barcode; ซื้อเชื่อ, ไม่เคลมภาษีซื้อ, ใช้ทุนรับเข้าล่าสุดหรือทุนเฉลี่ย');
            $this->line('สินค้าเปิดใช้: '.number_format($products->count()));
            $this->line('สินค้าในคลังที่มีของขายได้พอแล้ว: '.number_format($alreadyCovered));
            $this->line('บรรทัดที่จะรับเพิ่ม: '.number_format(count($items)));
            $this->line('เป้าหมายรวม: '.number_format((float) $targetTotalQty, 4).' หน่วยฐาน; มีของขายได้แล้ว '.number_format((float) $currentAvailableQty, 4));
            $this->line('จำนวนรับเพิ่ม: '.number_format((float) DecimalMath::sum(array_column($items, 'qty'), DecimalMath::QUANTITY_SCALE), 4).' หน่วยฐาน');
            // Documents retain four decimal places; use that stored precision for the cap.
            $estimatedDocumentTotal = DecimalMath::round($estimatedTotal, DecimalMath::MONEY_SCALE);
            $this->line('ประมาณยอดใบซื้อเชื่อ/เจ้าหนี้: ฿'.number_format((float) $estimatedDocumentTotal, 2)
                .' (ยอดเอกสาร '.number_format((float) $estimatedDocumentTotal, DecimalMath::MONEY_SCALE).')');

            if ($missingCost !== []) {
                $this->warn('สินค้าทุนเป็นศูนย์และต้องกำหนดต้นทุนก่อนรับ '.count($missingCost).' รายการ:');
                $this->line(implode("\n", array_slice($missingCost, 0, 30)));
            }
            if ($missingLotData !== []) {
                $this->warn('สินค้าควบคุมวันหมดอายุที่ยังขาด lot metadata '.count($missingLotData).' รายการ:');
                $this->line(implode("\n", array_slice($missingLotData, 0, 30)));
                $this->line('ให้เตรียม CSV หัวคอลัมน์ sku_code,expiry_date,manufacture_date,lot_number แล้วระบุ --lots=/path/to/lots.csv');
            }
            if ($items !== []) {
                $this->table(['SKU', 'สินค้า', 'รับ (หน่วยฐาน)', 'ทุน/หน่วย'], array_map(fn ($item) => [
                    $item['sku_code'], $item['name'], $item['qty'], $item['unit_price'],
                ], array_slice($items, 0, 12)));
            }

            if (! $this->option('execute')) {
                return self::SUCCESS;
            }

            $this->assertExecutionApproved($branch, $supplier, $reference, $estimatedDocumentTotal, $missingCost, $missingLotData);
            if ($items === []) {
                $this->info('ไม่มีสินค้าขาด ไม่สร้างเอกสาร');

                return self::SUCCESS;
            }

            $actor = User::query()->where('username', trim((string) $this->option('actor')))
                ->where('is_active', true)->first();
            if (! $actor || ! $actor->hasPermission('system.admin') || ! $actor->hasPermission('purchases.create')
                || ! $actor->canAccessBranch((int) $branch->id, 'purchases.create')) {
                throw new RuntimeException('ผู้บันทึกต้องเป็นผู้ดูแลระบบที่เปิดใช้งานและมีสิทธิ์บันทึกใบซื้อของสาขานี้');
            }

            Auth::login($actor);
            $document = DB::transaction(function () use ($branch, $reference, $purchases, $supplier, $actor, $items) {
                // Concurrent workflow dispatches must not create duplicate UAT receipts.
                Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
                if (Document::query()->where('branch_id', $branch->id)->where('reference', $reference)->exists()) {
                    throw new RuntimeException("มีเอกสารอ้างอิง {$reference} อยู่แล้ว ป้องกันการรับซ้ำ");
                }

                return $purchases->create([
                    'supplier_id' => (int) $supplier->id,
                    'branch_id' => (int) $branch->id,
                    'created_by' => (int) $actor->id,
                    'is_credit' => true,
                    'prices_include_vat' => true,
                    'claim_input_vat' => false,
                    'reference' => $reference,
                    'remark' => 'UAT: เติมสต๊อกสำหรับทดสอบการขายสินค้าและหน่วยขายทุกแบบ; ไม่ใช่ใบกำกับภาษีผู้ขายจริง',
                    'items' => array_map(fn ($item) => [
                        'product_id' => $item['product_id'],
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                        'lot_number' => $item['lot_number'],
                        'manufacture_date' => $item['manufacture_date'],
                        'expiry_date' => $item['expiry_date'],
                    ], $items),
                ]);
            });

            $this->newLine();
            $this->info("รับสินค้าแล้ว: {$document->doc_number}");
            $this->line('ยอดใบซื้อ/เจ้าหนี้: ฿'.number_format((float) $document->total_amount, 2));
            $this->line('รับสินค้าเข้าคลังและลง GL ผ่าน PurchaseService ตามปกติ');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function targetBaseQuantity(Product $product): string
    {
        $factorByUnit = [];
        foreach ($product->barcodes as $barcode) {
            $unitId = (int) $barcode->unit_id;
            $factor = DecimalMath::compare($barcode->unit_factor ?? 0, 0) > 0
                ? (string) $barcode->unit_factor
                : (string) ($barcode->unit?->qty_per_base_unit ?: 1);
            if (! isset($factorByUnit[$unitId]) || DecimalMath::compare($factor, $factorByUnit[$unitId]) > 0) {
                $factorByUnit[$unitId] = $factor;
            }
        }

        if ($product->base_unit_id && ! isset($factorByUnit[(int) $product->base_unit_id])) {
            $factorByUnit[(int) $product->base_unit_id] = '1';
        }
        if ($factorByUnit === []) {
            return '1';
        }

        return DecimalMath::sum($factorByUnit, DecimalMath::QUANTITY_SCALE);
    }

    /** @return array<string, array{lot_number:?string, manufacture_date:?string, expiry_date:string}> */
    private function readLotMetadata(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [];
        }
        if (! is_file($path) || ! is_readable($path) || ($handle = fopen($path, 'rb')) === false) {
            throw new RuntimeException('อ่านไฟล์ lot metadata ไม่ได้');
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw new RuntimeException('ไฟล์ lot metadata ว่าง');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $indexes = array_flip(array_map(fn ($value) => trim((string) $value), $header));
            foreach (['sku_code', 'expiry_date'] as $required) {
                if (! array_key_exists($required, $indexes)) {
                    throw new RuntimeException("ไฟล์ lot metadata ต้องมีคอลัมน์ {$required}");
                }
            }

            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                $sku = trim((string) ($values[$indexes['sku_code']] ?? ''));
                $expiry = trim((string) ($values[$indexes['expiry_date']] ?? ''));
                if ($sku === '' || $expiry === '') {
                    continue;
                }
                if (isset($rows[$sku])) {
                    throw new RuntimeException("ไฟล์ lot metadata มี SKU ซ้ำ: {$sku}");
                }
                foreach (['expiry_date', 'manufacture_date'] as $field) {
                    if (! empty($values[$indexes[$field] ?? -1] ?? null)) {
                        $date = trim((string) $values[$indexes[$field]]);
                        [$year, $month, $day] = array_pad(array_map('intval', explode('-', $date)), 3, 0);
                        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                            || ! checkdate($month, $day, $year)) {
                            throw new RuntimeException("วันที่ {$field} ของ SKU {$sku} ต้องเป็น YYYY-MM-DD");
                        }
                        if ($field === 'expiry_date' && $date < today()->toDateString()) {
                            throw new RuntimeException("วันหมดอายุของ SKU {$sku} ต้องไม่ผ่านไปแล้ว");
                        }
                    }
                }
                $manufacture = trim((string) ($values[$indexes['manufacture_date'] ?? -1] ?? '')) ?: null;
                if ($manufacture && $expiry < $manufacture) {
                    throw new RuntimeException("วันหมดอายุก่อนวันผลิตของ SKU {$sku}");
                }
                $rows[$sku] = [
                    'lot_number' => trim((string) ($values[$indexes['lot_number'] ?? -1] ?? '')) ?: null,
                    'manufacture_date' => $manufacture,
                    'expiry_date' => $expiry,
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string>  $missingCost
     * @param  array<int, string>  $missingLotData
     */
    private function assertExecutionApproved(Branch $branch, Supplier $supplier, string $reference, string $estimatedTotal, array $missingCost, array $missingLotData): void
    {
        if ($this->option('confirm') !== 'RECEIVE-ALL-ACTIVE-PRODUCTS') {
            throw new RuntimeException('ต้องระบุ --confirm=RECEIVE-ALL-ACTIVE-PRODUCTS เพื่อบันทึกเอกสารจริง');
        }
        if (trim((string) $this->option('actor')) === '') {
            throw new RuntimeException('ต้องระบุ --actor=<admin-username> เพื่อระบุผู้สร้างเอกสารและ audit trail');
        }
        $maxTotal = $this->option('max-total');
        if (! is_numeric($maxTotal) || DecimalMath::compare($maxTotal, 0) <= 0) {
            throw new RuntimeException('ต้องระบุ --max-total เป็นเพดานยอดซื้อรวมที่มากกว่า 0');
        }
        if (DecimalMath::compare($estimatedTotal, $maxTotal) > 0) {
            throw new RuntimeException('ยอดประมาณ ฿'.number_format((float) $estimatedTotal, 2)
                .' เกินเพดาน ฿'.number_format((float) $maxTotal, 2).' จึงไม่บันทึกเอกสาร');
        }
        if ($missingCost !== []) {
            throw new RuntimeException('มีสินค้าที่ไม่มีทุนรับเข้า ห้ามเดาทุน; กรุณาเติมต้นทุนให้ครบก่อน');
        }
        if ($missingLotData !== []) {
            throw new RuntimeException('สินค้าควบคุมวันหมดอายุยังไม่มี lot metadata; กรุณาเตรียมไฟล์ --lots ก่อน');
        }

        $this->warn('กำลังจะบันทึกใบซื้อเชื่อจริง เพิ่มสต๊อก และตั้งเจ้าหนี้ให้ผู้ขาย '.$supplier->code
            .' ที่สาขา '.$branch->code.' อ้างอิง '.$reference.' รวมประมาณ ฿'.number_format((float) $estimatedTotal, 2));
    }
}
