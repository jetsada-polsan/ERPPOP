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
 * ตั้งคนขายของสาขาแบบ "1 สาขา 1 user": แคชเชียร์ 1 รหัสต่อเครื่อง POS + หัวหน้า 1 รหัส
 *
 * ระบบเปิดกะได้ 1 กะต่อรหัสแคชเชียร์ต่อสาขา — หลายเครื่องใช้รหัสเดียวกันจะเข้ากะเดียวกัน
 * เงินทุกลิ้นชักรวมกันนับแยกไม่ได้ จึงต้องมีรหัสแคชเชียร์แยกตามเครื่อง (เช่น B001-1, B001-2, B001-3)
 * รันซ้ำเพื่อเพิ่มเครื่องได้ รหัสที่มีอยู่แล้วไม่ถูกสร้างซ้ำ
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
        {--cashier=* : รหัสแคชเชียร์ 1 รหัสต่อเครื่อง POS (ระบุซ้ำได้)}
        {--manager= : รหัสหัวหน้า}
        {--dry-run : สรุปโดยไม่บันทึก}';

    protected $description = 'Set up one branch login, one cashier code per till and a manager';

    public function handle(): int
    {
        $branch = Branch::where('code', (string) $this->argument('branch'))->first();
        $user = User::where('username', (string) $this->option('user'))->first();
        $cashierCodes = collect((array) $this->option('cashier'))->map(fn ($c) => strtoupper(trim((string) $c)))->filter()->unique()->values()->all();
        $managerCode = strtoupper(trim((string) $this->option('manager')));
        $role = Role::where('code', 'BRANCH_MGR')->first();

        $errors = array_filter([
            $branch ? null : 'ไม่พบสาขา',
            $user ? null : 'ไม่พบ user',
            $cashierCodes !== [] && $managerCode !== '' && ! in_array($managerCode, $cashierCodes, true) ? null : 'ต้องระบุ --cashier อย่างน้อย 1 รหัส และ --manager ที่ไม่ซ้ำกับแคชเชียร์',
            $role ? null : 'ไม่พบบทบาท BRANCH_MGR',
        ]);
        foreach ([...$cashierCodes, $managerCode] as $code) {
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
            ...array_map(fn ($code, $i) => ['แคชเชียร์เครื่อง '.($i + 1), "{$code} ".$this->cashierName($branch, $code, count($cashierCodes)).' (ไม่ผูก user)'], $cashierCodes, array_keys($cashierCodes)),
            ['หัวหน้า', "{$managerCode} หัวหน้า {$branch->name_th} (ผูก {$user->username})"],
            ['PIN', 'ยังไม่ตั้ง — ใช้ pos:pin ตั้งเอง เครื่องจะบังคับเปลี่ยนตอนล็อกอินครั้งแรก'],
        ]);

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($branch, $user, $role, $cashierCodes, $managerCode) {
                $user->update(['is_active' => true, 'branch_id' => $branch->id]);
                $user->roles()->syncWithoutDetaching([$role->id]);

                foreach ($cashierCodes as $code) {
                    $existing = Salesman::where('code', $code)->first();
                    Salesman::updateOrCreate(['code' => $code], [
                        'branch_id' => $branch->id, 'user_id' => null, 'name' => $this->cashierName($branch, $code, count($cashierCodes)),
                        'is_active' => true,
                        // รันซ้ำเพื่อเพิ่มเครื่อง: รหัสเดิมที่ตั้ง PIN แล้วไม่ถูกบังคับเปลี่ยนใหม่
                        'must_change_pin' => $existing ? (bool) $existing->must_change_pin : true,
                    ]);
                }
                $manager = Salesman::where('code', $managerCode)->first();
                Salesman::updateOrCreate(['code' => $managerCode], [
                    'branch_id' => $branch->id, 'user_id' => $user->id, 'name' => 'หัวหน้า '.$branch->name_th,
                    'is_active' => true, 'must_change_pin' => $manager ? (bool) $manager->must_change_pin : true,
                ]);
                $user->update(['salesman_id' => Salesman::where('code', $managerCode)->value('id')]);
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("ตั้งคนขายสาขา {$branch->code} แล้ว — ตั้ง PIN เริ่มต้นด้วย pos:pin ".implode(', pos:pin ', $cashierCodes)." และ pos:pin {$managerCode}");

        return self::SUCCESS;
    }

    /** ชื่อแสดงในบิล/รายงานกะ — หลายเครื่องใส่เลขเครื่องท้ายรหัสให้รู้ว่าลิ้นชักไหน */
    private function cashierName(Branch $branch, string $code, int $count): string
    {
        $suffix = preg_match('/-(\d+)$/', $code, $m) ? ' เครื่อง '.$m[1] : ($count > 1 ? ' '.$code : '');

        return 'แคชเชียร์ '.$branch->name_th.$suffix;
    }
}
