<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes only legacy customer stubs which carry no usable master data and no
 * relation to any other business record. Default mode is a read-only preview.
 */
class PruneEmptyCustomers extends Command
{
    protected $signature = 'erp:prune-empty-customers
        {--purge : Permanently delete eligible records; omitted means dry-run}
        {--confirm-database= : Required database name when using --purge}
        {--force : Skip the final interactive confirmation}';

    protected $description = 'Preview or permanently remove empty orphan customer records';

    /** Child tables whose empty rows may be removed by the customer FK cascade. */
    private const CASCADE_CHILDREN = ['customer_addresses', 'customer_contacts'];

    public function handle(): int
    {
        $purge = (bool) $this->option('purge');
        $database = DB::connection()->getDatabaseName();

        if ($purge && $this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        $references = $this->customerReferences();
        $candidates = $this->candidates()->get();
        $eligible = [];
        $blocked = [];

        foreach ($candidates as $customer) {
            $table = $this->firstReference($customer->id, $references);
            if ($table !== null) {
                $blocked[] = [$customer->code, $table];
                continue;
            }
            $eligible[] = $customer;
        }

        $this->line("ฐานข้อมูล: {$database}");
        $this->line('เกณฑ์: ชื่อว่าง/เท่ารหัส, ไม่มีชื่อรอง/เลขภาษี/วงเงิน, ไม่มีที่อยู่หรือผู้ติดต่อที่มีค่า');
        $this->line('สายการขาย/ผู้ดูแลไม่กันการลบตามคำสั่งเจ้าของ แต่เอกสารและรายการบัญชียังกันการลบเสมอ');
        $this->line(sprintf('พบผู้สมัครตรวจ: %s · ลบได้จริง: %s · ยังมีข้อมูลอ้างอิง: %s',
            number_format($candidates->count()), number_format(count($eligible)), number_format(count($blocked))));

        if ($eligible !== []) {
            $this->table(['รหัส', 'ชื่อต้นทาง'], collect($eligible)->take(50)->map(fn (Customer $customer) => [$customer->code, $customer->name_th])->all());
        }
        if ($blocked !== []) {
            $this->table(['รหัสที่ยังเก็บไว้', 'ตารางที่อ้างถึง'], array_slice($blocked, 0, 50));
        }

        if (! $purge) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }

        if ($eligible === []) {
            $this->info('ไม่มีลูกค้าว่างเปล่าที่ลบได้');

            return self::SUCCESS;
        }

        $this->warn(sprintf('จะลบถาวรลูกค้าว่างเปล่า %s รายจากฐาน %s', number_format(count($eligible)), $database));
        if (! $this->option('force') && ! $this->confirm('ยืนยันลบลูกค้าว่างเปล่าถาวร?', false)) {
            $this->info('ยกเลิก ไม่มีข้อมูลถูกลบ');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($eligible): void {
                foreach ($eligible as $customer) {
                    AuditLog::create([
                        'action' => 'purge',
                        'table_name' => 'customers',
                        'record_id' => $customer->id,
                        'old_values' => ['code' => $customer->code, 'name_th' => $customer->name_th],
                        'new_values' => ['reason' => 'empty_orphan_customer_cleanup'],
                    ]);
                    $customer->forceDelete();
                }
            });
        } catch (Throwable $exception) {
            $this->error('ลบไม่สำเร็จ ไม่มีข้อมูลถูกลบ (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('ลบลูกค้าว่างเปล่าถาวรแล้ว %s ราย', number_format(count($eligible))));

        return self::SUCCESS;
    }

    private function candidates()
    {
        return Customer::withTrashed()
            ->where(fn ($query) => $query
                ->whereRaw("trim(coalesce(name_th, '')) = ''")
                ->orWhereRaw('trim(name_th) = trim(code)'))
            ->whereRaw("trim(coalesce(name_en, '')) = ''")
            ->whereRaw("trim(coalesce(tax_id, '')) = ''")
            ->where('credit_limit', 0)
            ->whereNull('pending_credit_limit')
            ->whereDoesntHave('addresses', fn ($query) => $query->whereRaw("trim(coalesce(address_line, '')) <> ''"))
            ->whereDoesntHave('contacts', fn ($query) => $query->where(fn ($contact) => $contact
                ->whereRaw("trim(coalesce(name, '')) <> ''")
                ->orWhereRaw("trim(coalesce(phone, '')) <> ''")
                ->orWhereRaw("trim(coalesce(email, '')) <> ''")))
            ->orderBy('code');
    }

    /** @return array<int, array{table:string,column:string}> */
    private function customerReferences(): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
            $references = [];
            foreach ($tables as $row) {
                $table = (string) $row->name;
                foreach (DB::select("PRAGMA foreign_key_list('".str_replace("'", "''", $table)."')") as $foreignKey) {
                    if ($foreignKey->table === 'customers' && $foreignKey->to === 'id') {
                        $references[] = ['table' => $table, 'column' => (string) $foreignKey->from];
                    }
                }
            }

            return $this->filterCascadeChildren($references);
        }

        $rows = DB::select(<<<'SQL'
            SELECT kcu.table_name, kcu.column_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
              ON ccu.constraint_name = tc.constraint_name
             AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = current_schema()
              AND ccu.table_name = 'customers'
              AND ccu.column_name = 'id'
            SQL);

        return $this->filterCascadeChildren(array_map(fn ($row) => [
            'table' => (string) $row->table_name,
            'column' => (string) $row->column_name,
        ], $rows));
    }

    /** @param array<int, array{table:string,column:string}> $references */
    private function filterCascadeChildren(array $references): array
    {
        return array_values(array_filter($references, fn (array $reference) => ! in_array($reference['table'], self::CASCADE_CHILDREN, true)));
    }

    /** @param array<int, array{table:string,column:string}> $references */
    private function firstReference(int $customerId, array $references): ?string
    {
        foreach ($references as $reference) {
            if (DB::table($reference['table'])->where($reference['column'], $customerId)->exists()) {
                return $reference['table'];
            }
        }

        return null;
    }
}
