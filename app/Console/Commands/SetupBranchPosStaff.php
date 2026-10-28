<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ตั้งคนขายของสาขาแบบ "1 สาขา 1 user": แคชเชียร์กลาง 1 รหัส + หัวหน้า 1 รหัส
 *
 * - user ของสาขา (เช่น amnat) เปิดใช้งาน ผูกสาขา และได้บทบาท BRANCH_MGR
 * - หัวหน้า (เช่น m001) ผูกกับ user นั้น เพราะ POS ดูบทบาทของ user ที่ผูกเพื่อให้สิทธิ์อนุมัติลดราคา/ยกเลิกบิล
 * - แคชเชียร์ (เช่น b001) ไม่ผูก user ใช้ PIN ของตัวเอง
 *
 * ไม่ตั้ง PIN — เจ้าของตั้ง PIN เริ่มต้นเองด้วย pos:pin แล้วเครื่องบังคับให้เปลี่ยนตอนล็อกอินครั้งแรก
 */
class SetupBranchPosStaff extends Command
{
    protected $signature = 'erp:setup-branch-pos-staff
        {branch : รหัสสาขา}
        {--user= : username ของสาขา}
        {--cashier= : รหัสแคชเชียร์กลาง}
        {--manager= : รหัสหัวหน้า}
        {--dry-run : สรุปโดยไม่บันทึก}';

    protected $description = 'Set up one branch login plus a shared cashier and a manager for the till';

    public function handle(): int
    {
        $branch = Branch::where('code', (string) $this->argument('branch'))->first();
        $user = User::where('username', (string) $this->option('user'))->first();
        $cashierCode = strtoupper(trim((string) $this->option('cashier')));
        $managerCode = strtoupper(trim((string) $this->option('manager')));
        $role = Role::where('code', 'BRANCH_MGR')->first();

        $errors = array_filter([
            $branch ? null : 'ไม่พบสาขา',
            $user ? null : 'ไม่พบ user',
            $cashierCode !== '' && $managerCode !== '' && $cashierCode !== $managerCode ? null : 'ต้องระบุ --cashier และ --manager คนละรหัส',
            $role ? null : 'ไม่พบบทบาท BRANCH_MGR',
        ]);
        foreach ([$cashierCode, $managerCode] as $code) {
            $taken = $code !== '' ? Salesman::where('code', $code)->first() : null;
            if ($taken && $taken->branch_id !== $branch?->id) {
                $errors[] = "รหัส {$code} ถูกใช้กับสาขาอื่นแล้ว";
            }
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->table(['รายการ', 'ค่า'], [
            ['สาขา', "{$branch->code} {$branch->name_th}"],
            ['user ของสาขา', "{$user->username} → เปิดใช้งาน, สาขา {$branch->code}, เพิ่มบทบาท BRANCH_MGR"],
            ['แคชเชียร์กลาง', "{$cashierCode} แคชเชียร์ {$branch->name_th} (ไม่ผูก user)"],
            ['หัวหน้า', "{$managerCode} หัวหน้า {$branch->name_th} (ผูก {$user->username})"],
            ['PIN', 'ยังไม่ตั้ง — ใช้ pos:pin ตั้งเอง เครื่องจะบังคับเปลี่ยนตอนล็อกอินครั้งแรก'],
        ]);

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($branch, $user, $role, $cashierCode, $managerCode) {
                $user->update(['is_active' => true, 'branch_id' => $branch->id]);
                $user->roles()->syncWithoutDetaching([$role->id]);

                Salesman::updateOrCreate(['code' => $cashierCode], [
                    'branch_id' => $branch->id, 'user_id' => null, 'name' => 'แคชเชียร์ '.$branch->name_th,
                    'is_active' => true, 'must_change_pin' => true,
                ]);
                Salesman::updateOrCreate(['code' => $managerCode], [
                    'branch_id' => $branch->id, 'user_id' => $user->id, 'name' => 'หัวหน้า '.$branch->name_th,
                    'is_active' => true, 'must_change_pin' => true,
                ]);
                $user->update(['salesman_id' => Salesman::where('code', $managerCode)->value('id')]);
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("ตั้งคนขายสาขา {$branch->code} แล้ว — ตั้ง PIN เริ่มต้นด้วย pos:pin {$cashierCode} และ pos:pin {$managerCode}");

        return self::SUCCESS;
    }
}
