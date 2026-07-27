<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Salesman;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * สร้างรหัสพนักงานขาย (salesmen) จากทะเบียนพนักงานจริง เพื่อให้ยอดขายผูกกับ "คน"
 * ไม่ใช่ "เครื่อง" — ใช้ employee_code เป็นรหัสเข้า POS จะได้ไล่กลับไปหาข้อมูล HR ได้
 * ไม่แตะรายการเดิมและไม่ตั้ง PIN ให้ (ตั้งด้วย pos:pin ทีละคน)
 */
class SyncBranchCashiers extends Command
{
    protected $signature = 'pos:sync-cashiers {branch : branch code or id} {--position=แคชเชียร์} {--dry-run}';

    protected $description = 'Create POS cashier records from active employees of a branch';

    public function handle(): int
    {
        // หารหัสสาขาก่อนเสมอ: รหัสอย่าง "0004" ถ้าแปลงเป็นเลขไปจับ id จะได้คนละสาขา
        $key = (string) $this->argument('branch');
        $branch = Branch::where('code', $key)->first()
            ?? (ctype_digit($key) ? Branch::find((int) $key) : null);

        if (! $branch) {
            $this->error('ไม่พบสาขา');

            return self::FAILURE;
        }

        $employees = Employee::where('branch_id', $branch->id)
            ->where('status', 'Active')
            ->where('position', (string) $this->option('position'))
            ->whereNotNull('employee_code')
            ->orderBy('employee_code')
            ->get();

        if ($employees->isEmpty()) {
            $this->warn("ไม่พบพนักงานตำแหน่งนี้ที่สาขา {$branch->name_th}");

            return self::SUCCESS;
        }

        $existing = Salesman::whereIn('code', $employees->pluck('employee_code'))->pluck('code')->all();
        $new = $employees->reject(fn (Employee $e) => in_array($e->employee_code, $existing, true));

        $this->line("สาขา {$branch->name_th}: พบ {$employees->count()} คน, สร้างใหม่ {$new->count()} คน");
        foreach ($new as $employee) {
            $this->line("  {$employee->employee_code}  {$employee->full_name}");
        }

        if ($this->option('dry-run')) {
            $this->info('dry-run: ยังไม่บันทึก');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($new, $branch) {
            foreach ($new as $employee) {
                Salesman::create([
                    'branch_id' => $branch->id,
                    'code' => $employee->employee_code,
                    'name' => $employee->full_name,
                    'is_active' => true,
                ]);
            }
        });

        $this->info("สร้างแล้ว {$new->count()} คน — ตั้ง PIN ให้แต่ละคนด้วย php artisan pos:pin <รหัส> <PIN>");

        return self::SUCCESS;
    }
}
