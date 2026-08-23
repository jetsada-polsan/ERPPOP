<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps legacy customer addresses but removes incomplete records from normal
 * operational pickers until an operator supplies a real customer name.
 */
class DeactivateIncompleteCustomers extends Command
{
    protected $signature = 'erp:deactivate-incomplete-customers
        {--apply : Change active records to inactive; omitted means dry-run}
        {--confirm-database= : Required database name when using --apply}
        {--force : Skip the final interactive confirmation}';

    protected $description = 'Deactivate customers whose imported name is empty or equals the legacy code';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $database = DB::connection()->getDatabaseName();
        if ($apply && $this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        $customers = Customer::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereRaw("trim(coalesce(name_th, '')) = ''")
                ->orWhereRaw('trim(name_th) = trim(code)'))
            ->orderBy('code')
            ->get(['id', 'code', 'name_th', 'branch_id']);

        $this->line("ฐานข้อมูล: {$database}");
        $this->line(sprintf('ลูกค้าชื่อไม่สมบูรณ์ที่กำลังใช้งาน: %s ราย', number_format($customers->count())));
        if ($customers->isNotEmpty()) {
            $this->table(['รหัส', 'ชื่อที่นำเข้า'], $customers->take(50)->map(fn (Customer $customer) => [$customer->code, $customer->name_th])->all());
        }

        if (! $apply) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }

        if ($customers->isEmpty()) {
            $this->info('ไม่มีลูกค้าที่ต้องปิดใช้งาน');

            return self::SUCCESS;
        }

        $this->warn(sprintf('จะปิดใช้งานลูกค้า %s ราย ข้อมูลและที่อยู่ยังอยู่ครบ เปิดกลับได้หลังแก้ชื่อ', number_format($customers->count())));
        if (! $this->option('force') && ! $this->confirm('ยืนยันปิดใช้งานลูกค้าเหล่านี้?', false)) {
            $this->info('ยกเลิก ไม่มีข้อมูลถูกแก้ไข');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($customers): void {
                foreach ($customers as $customer) {
                    $customer->update(['is_active' => false]);
                    AuditLog::create([
                        'branch_id' => $customer->branch_id,
                        'action' => 'deactivate',
                        'table_name' => 'customers',
                        'record_id' => $customer->id,
                        'old_values' => ['is_active' => true, 'code' => $customer->code, 'name_th' => $customer->name_th],
                        'new_values' => ['is_active' => false, 'reason' => 'missing_imported_customer_name'],
                    ]);
                }
            });
        } catch (Throwable $exception) {
            $this->error('ปิดใช้งานไม่สำเร็จ ไม่มีข้อมูลถูกแก้ไข (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('ปิดใช้งานแล้ว %s ราย ข้อมูลและที่อยู่ยังเก็บอยู่ครบ', number_format($customers->count())));

        return self::SUCCESS;
    }
}
