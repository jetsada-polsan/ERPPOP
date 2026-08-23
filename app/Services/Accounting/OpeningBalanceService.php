<?php

namespace App\Services\Accounting;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\GlJournal;
use App\Models\OpeningBalanceRun;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ยกยอดตั้งต้นเข้าระบบ: สต๊อก ลูกหนี้ เจ้าหนี้ เงินสด/ธนาคาร
 *
 * หลักที่ยึด
 *   - ทุกชุดลง GL เสมอ ของที่มีอยู่จริงต้องมีมูลค่าในงบดุลด้วย
 *   - อีกขาลงบัญชีพัก 3030 ไม่ใช่กำไรสะสม เพื่อให้เห็นว่ายกมาแล้วเท่าไรและตรวจสอบได้
 *   - ทั้งชุดสำเร็จหรือไม่สำเร็จพร้อมกัน ยกยอดครึ่ง ๆ อันตรายกว่าไม่ได้ยก
 *   - ยกซ้ำไม่ได้ ชนิด+สาขา+วันที่ ทำได้ครั้งเดียว (unique ที่ตาราง)
 *   - ตรวจข้อมูลให้ครบก่อนเขียนอะไรลงฐานแม้แต่แถวเดียว
 */
class OpeningBalanceService
{
    public const KINDS = ['stock', 'ar', 'ap', 'cash'];

    /**
     * ตรวจข้อมูลทั้งชุดโดยไม่เขียนอะไรลงฐาน
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{lines:int, total:float, errors:array<int, string>, preview:array<int, array<string, mixed>>}
     */
    public function validate(string $kind, int $branchId, array $rows): array
    {
        $this->assertKind($kind);

        $errors = [];
        $preview = [];
        $total = 0.0;

        foreach ($rows as $index => $row) {
            $line = $index + 1;
            try {
                $resolved = match ($kind) {
                    'stock' => $this->resolveStockRow($row, $branchId),
                    'ar' => $this->resolveReceivableRow($row),
                    'ap' => $this->resolvePayableRow($row),
                    'cash' => $this->resolveCashRow($row),
                };
                $total += $resolved['amount'];
                $preview[] = $resolved;
            } catch (RuntimeException $exception) {
                $errors[] = "บรรทัด {$line}: ".$exception->getMessage();
            }
        }

        return ['lines' => count($preview), 'total' => round($total, 2), 'errors' => $errors, 'preview' => $preview];
    }

    /**
     * ยกยอดจริง — ตรวจก่อน ถ้ามีข้อผิดพลาดแม้แต่บรรทัดเดียวจะไม่เขียนอะไรเลย
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{run:OpeningBalanceRun, lines:int, total:float}
     */
    public function post(string $kind, int $branchId, string $asOf, array $rows, ?int $userId = null, ?string $sourceName = null): array
    {
        $this->assertKind($kind);

        if (OpeningBalanceRun::where('kind', $kind)->where('branch_id', $branchId)->whereDate('as_of_date', $asOf)->exists()) {
            throw new RuntimeException("ยกยอด {$kind} ของสาขานี้ ณ {$asOf} ไปแล้ว — ยกซ้ำไม่ได้");
        }

        $checked = $this->validate($kind, $branchId, $rows);
        if ($checked['errors'] !== []) {
            throw new RuntimeException('ข้อมูลไม่ผ่านการตรวจ '.count($checked['errors']).' บรรทัด: '.implode(' · ', array_slice($checked['errors'], 0, 3)));
        }
        if ($checked['lines'] === 0) {
            throw new RuntimeException('ไม่มีข้อมูลให้ยกยอด');
        }

        return DB::transaction(function () use ($kind, $branchId, $asOf, $checked, $userId, $sourceName) {
            $amount = match ($kind) {
                'stock' => $this->writeStock($checked['preview'], $asOf),
                'ar' => $this->writeReceivables($checked['preview'], $branchId, $asOf, $userId),
                'ap' => $this->writePayables($checked['preview'], $asOf),
                'cash' => $this->writeCash($checked['preview'], $branchId, $asOf, $userId),
            };

            $this->postJournal($kind, $amount, $asOf);

            $run = OpeningBalanceRun::create([
                'kind' => $kind,
                'branch_id' => $branchId,
                'as_of_date' => $asOf,
                'line_count' => $checked['lines'],
                'total_amount' => $amount,
                'source_name' => $sourceName,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return ['run' => $run, 'lines' => $checked['lines'], 'total' => $amount];
        });
    }

    /** ยอดคงเหลือในบัญชีพัก — ยกครบแล้วตัวเลขนี้คือมูลค่าสุทธิที่ยกมา */
    public function suspenseBalance(): float
    {
        $account = $this->account(ChartOfAccount::ROLE_OPENING_BALANCE);

        return round((float) GlJournal::where('account_id', $account->id)->sum('credit')
            - (float) GlJournal::where('account_id', $account->id)->sum('debit'), 2);
    }

    // ---------- ตรวจและแปลงข้อมูลรายบรรทัด ----------

    /** @return array<string, mixed> */
    private function resolveStockRow(array $row, int $branchId): array
    {
        $product = Product::where('sku_code', trim((string) ($row['sku'] ?? '')))->first();
        if (! $product) {
            throw new RuntimeException('ไม่พบสินค้า sku='.($row['sku'] ?? '(ว่าง)'));
        }

        $location = $this->resolveLocation(trim((string) ($row['location'] ?? '')), $branchId);

        $qty = (float) ($row['qty'] ?? 0);
        $unitCost = (float) ($row['unit_cost'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('จำนวนต้องมากกว่าศูนย์');
        }
        if ($unitCost < 0) {
            throw new RuntimeException('ต้นทุนต่อหน่วยติดลบไม่ได้');
        }

        return [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'amount' => round($qty * $unitCost, 2),
            'label' => $product->sku_code,
        ];
    }

    /**
     * หาพื้นที่เก็บจากรหัส แล้วตรวจว่าเป็นของสาขานั้นจริง
     *
     * ระบบนี้ผูกสาขากับพื้นที่เก็บสองทาง: warehouses.branch_id กับ
     * branches.default_warehouse_location_id — และบนฐานจริงตอนนี้ warehouses.branch_id
     * เป็น NULL ทั้งหมด ความเป็นเจ้าของอยู่ที่ทางหลังล้วน ๆ
     * ถ้าเช็คทางเดียวจะยกสต๊อกเข้าไม่ได้เลยสักบรรทัด
     */
    private function resolveLocation(string $code, int $branchId): WarehouseLocation
    {
        if ($code === '') {
            throw new RuntimeException('ต้องระบุรหัสพื้นที่เก็บ');
        }

        $location = WarehouseLocation::where('code', $code)->first();
        if (! $location) {
            throw new RuntimeException("ไม่พบพื้นที่เก็บ code={$code}");
        }

        $ownedByWarehouse = Warehouse::where('id', $location->warehouse_id)->where('branch_id', $branchId)->exists();
        $isBranchDefault = Branch::where('id', $branchId)->where('default_warehouse_location_id', $location->id)->exists();

        if (! $ownedByWarehouse && ! $isBranchDefault) {
            throw new RuntimeException("พื้นที่เก็บ {$code} ไม่ได้เป็นของสาขานี้");
        }

        return $location;
    }

    /** @return array<string, mixed> */
    private function resolveReceivableRow(array $row): array
    {
        $customer = Customer::where('code', trim((string) ($row['customer'] ?? '')))->first();
        if (! $customer) {
            throw new RuntimeException('ไม่พบลูกค้า code='.($row['customer'] ?? '(ว่าง)'));
        }

        return [
            'customer_id' => $customer->id,
            'document_no' => $this->requireText($row, 'document_no', 'เลขที่เอกสาร'),
            'document_date' => $this->requireText($row, 'document_date', 'วันที่เอกสาร'),
            'due_date' => trim((string) ($row['due_date'] ?? '')) ?: null,
            'amount' => $this->requireAmount($row),
            'label' => $customer->code,
        ];
    }

    /** @return array<string, mixed> */
    private function resolvePayableRow(array $row): array
    {
        $supplier = Supplier::where('code', trim((string) ($row['supplier'] ?? '')))->first();
        if (! $supplier) {
            throw new RuntimeException('ไม่พบผู้ขาย code='.($row['supplier'] ?? '(ว่าง)'));
        }

        return [
            'supplier_id' => $supplier->id,
            'document_no' => $this->requireText($row, 'document_no', 'เลขที่เอกสาร'),
            'document_date' => $this->requireText($row, 'document_date', 'วันที่เอกสาร'),
            'due_date' => trim((string) ($row['due_date'] ?? '')) ?: null,
            'amount' => $this->requireAmount($row),
            'label' => $supplier->code,
        ];
    }

    /** @return array<string, mixed> */
    private function resolveCashRow(array $row): array
    {
        $type = strtolower(trim((string) ($row['type'] ?? 'cash')));
        if (! in_array($type, ['cash', 'bank'], true)) {
            throw new RuntimeException("ประเภทต้องเป็น cash หรือ bank เท่านั้น (ได้ {$type})");
        }

        return [
            'type' => $type,
            'description' => trim((string) ($row['description'] ?? '')) ?: 'ยอดยกมา',
            'amount' => $this->requireAmount($row),
            'label' => $type,
        ];
    }

    // ---------- เขียนลงฐาน ----------

    /** @param  array<int, array<string, mixed>>  $rows */
    private function writeStock(array $rows, string $asOf): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            DB::table('stock_lots')->insert([
                'product_id' => $row['product_id'],
                'warehouse_location_id' => $row['location_id'],
                'lot_number' => 'OPEN-'.str_replace('-', '', $asOf),
                'received_date' => $asOf,
                'initial_qty' => $row['qty'],
                'remaining_qty' => $row['qty'],
                'unit_cost' => $row['unit_cost'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $balance = StockBalance::firstOrNew([
                'product_id' => $row['product_id'],
                'warehouse_location_id' => $row['location_id'],
            ]);
            $balance->on_hand_qty = (float) ($balance->on_hand_qty ?? 0) + $row['qty'];
            $balance->reserved_qty = (float) ($balance->reserved_qty ?? 0);
            $balance->save();

            $total += $row['amount'];
        }

        return round($total, 2);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function writeReceivables(array $rows, int $branchId, string $asOf, ?int $userId): float
    {
        $typeId = DocumentType::where('code', 'OPENING_AR')->value('id');
        if (! $typeId) {
            throw new RuntimeException('ยังไม่มีชนิดเอกสาร OPENING_AR');
        }

        $total = 0.0;
        foreach ($rows as $row) {
            $document = Document::create([
                'doc_number' => $row['document_no'],
                'document_type_id' => $typeId,
                'branch_id' => $branchId,
                'customer_id' => $row['customer_id'],
                'doc_date' => $row['document_date'],
                'status' => 'active',
                'total_amount' => $row['amount'],
                'subtotal_amount' => $row['amount'],
                'created_by' => $userId,
            ]);

            DB::table('customer_open_items')->insert([
                'customer_id' => $row['customer_id'],
                'document_id' => $document->id,
                'gross_amount' => $row['amount'],
                'net_amount' => $row['amount'],
                'paid_amount' => 0,
                'balance_amount' => $row['amount'],
                'due_date' => $row['due_date'],
                'status' => 'open',
                'created_at' => now(),
            ]);

            $total += $row['amount'];
        }

        return round($total, 2);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function writePayables(array $rows, string $asOf): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            DB::table('supplier_open_items')->insert([
                'supplier_id' => $row['supplier_id'],
                'document_no' => $row['document_no'],
                'document_date' => $row['document_date'],
                'due_date' => $row['due_date'],
                'original_amount' => $row['amount'],
                'paid_amount' => 0,
                'balance_amount' => $row['amount'],
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $total += $row['amount'];
        }

        return round($total, 2);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function writeCash(array $rows, int $branchId, string $asOf, ?int $userId): float
    {
        $total = 0.0;
        foreach ($rows as $index => $row) {
            $total += $row['amount'];
            DB::table('cash_books')->insert([
                'branch_id' => $branchId,
                'entry_date' => $asOf,
                'description' => $row['description'],
                'cash_in' => $row['amount'],
                'cash_out' => 0,
                'running_balance' => $total,
                'source_type' => 'opening',
                'source_key' => "opening:{$branchId}:{$asOf}:{$index}",
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return round($total, 2);
    }

    /**
     * ลง GL คู่เดียวต่อการยกยอดหนึ่งชุด
     *
     * เจ้าหนี้เป็นหนี้สิน ยกมาแล้วต้อง credit ส่วนที่เหลือเป็นสินทรัพย์ ยกมาแล้ว debit
     * อีกขาไปที่บัญชีพักเสมอ
     */
    private function postJournal(string $kind, float $amount, string $asOf): void
    {
        if (round($amount, 2) == 0.0) {
            return;
        }

        $suspense = $this->account(ChartOfAccount::ROLE_OPENING_BALANCE);
        $isLiability = $kind === 'ap';
        $counterpart = $this->account(match ($kind) {
            'stock' => ChartOfAccount::ROLE_INVENTORY,
            'ar' => ChartOfAccount::ROLE_AR,
            'ap' => ChartOfAccount::ROLE_AP,
            'cash' => ChartOfAccount::ROLE_CASH,
        });

        $remark = 'ยอดยกมา '.$kind;
        GlJournal::insert([
            [
                'account_id' => $isLiability ? $suspense->id : $counterpart->id,
                'debit' => $amount, 'credit' => 0, 'entry_date' => $asOf, 'remark' => $remark,
            ],
            [
                'account_id' => $isLiability ? $counterpart->id : $suspense->id,
                'debit' => 0, 'credit' => $amount, 'entry_date' => $asOf, 'remark' => $remark,
            ],
        ]);
    }

    private function account(string $role): ChartOfAccount
    {
        $account = ChartOfAccount::where('default_role', $role)->first();
        if (! $account) {
            throw new RuntimeException("ยังไม่ได้ผูกบัญชีเริ่มต้น [{$role}] ยกยอดไม่ได้");
        }

        return $account;
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('ชนิดยอดยกมาต้องเป็น '.implode('/', self::KINDS));
        }
    }

    private function requireText(array $row, string $key, string $label): string
    {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException("ต้องระบุ{$label}");
        }

        return $value;
    }

    private function requireAmount(array $row): float
    {
        $amount = round((float) ($row['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('ยอดเงินต้องมากกว่าศูนย์');
        }

        return $amount;
    }
}
