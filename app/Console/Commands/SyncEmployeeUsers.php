<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncEmployeeUsers extends Command
{
    protected $signature = 'erp:sync-employee-users
        {--dry-run : แสดงรายการอย่างเดียว ไม่เขียนข้อมูล}
        {--role=HR : บทบาทเริ่มต้นสำหรับพนักงานที่ยังไม่มี mapping เฉพาะฝ่าย}';

    protected $description = 'สร้างบัญชี ERP จากทะเบียนพนักงานที่มีรหัส โดยไม่ทับบัญชีเดิม';

    public function handle(): int
    {
        $roleCode = strtoupper(trim((string) $this->option('role')));
        $roleId = Role::where('code', $roleCode)->value('id');
        if (! $roleId) {
            $this->error("ไม่พบบทบาท {$roleCode}");

            return self::FAILURE;
        }

        $employees = Employee::query()
            ->whereNotNull('employee_code')
            ->where('employee_code', '!=', '')
            ->where('status', 'Active')
            ->orderBy('employee_code')
            ->get();
        $existing = User::query()->pluck('id', 'username')->mapWithKeys(fn ($id, $username) => [Str::lower($username) => $id]);
        $created = 0;
        $skipped = 0;
        $unlinked = 0;
        $rows = [];

        foreach ($employees as $employee) {
            $username = Str::lower(trim($employee->employee_code));
            if ($employee->user_id || $existing->has($username)) {
                $skipped++;
                $rows[] = [$employee->employee_code, $employee->full_name, 'ข้าม - มีบัญชีแล้ว'];

                continue;
            }

            $mappedRole = $this->roleFor($employee, $roleCode);
            $mappedRoleId = Role::where('code', $mappedRole)->value('id') ?: $roleId;
            $rows[] = [$employee->employee_code, $employee->full_name, "สร้าง {$mappedRole}"];
            $created++;

            if ($this->option('dry-run')) {
                continue;
            }

            DB::transaction(function () use ($employee, $username, $mappedRoleId) {
                $user = User::create([
                    'username' => $username,
                    'name' => $employee->full_name,
                    'phone' => $employee->phone,
                    'position' => $employee->position ?: $employee->department,
                    'branch_id' => $employee->branch_id,
                    'password' => '12345678',
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
                $user->roles()->sync([$mappedRoleId]);
                $employee->update(['user_id' => $user->id]);
            });
        }

        $this->table(['รหัสพนักงาน', 'ชื่อ', 'ผลลัพธ์'], $rows);
        $this->info(($this->option('dry-run') ? 'จะสร้าง' : 'สร้างแล้ว')." {$created} บัญชี, ข้าม {$skipped} บัญชี");
        $this->line('รหัสเริ่มต้น: 12345678 และทุกบัญชีถูกบังคับให้เปลี่ยนรหัสผ่านเมื่อเข้าใช้ครั้งแรก');
        if (! $this->option('dry-run')) {
            $this->warn('แจกชั่วคราวอย่างปลอดภัยและให้พนักงานเปลี่ยนรหัสผ่านทันที ห้ามใช้รหัส 12345678 ต่อเนื่อง');
        }

        return self::SUCCESS;
    }

    private function roleFor(Employee $employee, string $fallback): string
    {
        $text = Str::lower(($employee->department ?? '').' '.($employee->position ?? ''));

        return match (true) {
            Str::contains($text, ['บัญชี', 'การเงิน']) => 'ACC',
            Str::contains($text, ['จัดซื้อ', 'จัดหา']) => 'PURCHASING',
            Str::contains($text, ['คลัง', 'สต๊อก', 'warehouse']) => 'WAREHOUSE',
            Str::contains($text, ['ขาย', 'sales']) => 'SALES',
            Str::contains($text, ['การตลาด', 'marketing']) => 'MARKETING',
            default => $fallback,
        };
    }
}
