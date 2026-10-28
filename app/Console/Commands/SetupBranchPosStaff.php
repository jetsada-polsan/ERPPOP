<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use App\Models\UserPosCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * ตั้งคนขายของสาขา: แคชเชียร์ 1 รหัสต่อเครื่อง POS + หัวหน้า 1 รหัส
 *
 * โปรแกรม POS ถือ "คนขาย = user" และเครื่องแสดงเฉพาะ user ที่ผูกเครื่องไว้ (+ หัวหน้า)
 * แคชเชียร์แต่ละเครื่องจึงต้องเป็น user ของตัวเอง (username = รหัสตัวเล็ก เช่น b001-2)
 * user แคชเชียร์ใช้แค่ PIN ที่เครื่อง ไม่ได้รหัสผ่านเว็บ (สุ่มไว้ ไม่มีใครรู้)
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
        $cashierRole = Role::where('code', 'CASHIER')->first();

        $errors = array_filter([
            $branch ? null : 'ไม่พบสาขา',
            $user ? null : 'ไม่พบ user',
            $cashierCodes !== [] && $managerCode !== '' && ! in_array($managerCode, $cashierCodes, true) ? null : 'ต้องระบุ --cashier อย่างน้อย 1 รหัส และ --manager ที่ไม่ซ้ำกับแคชเชียร์',
            $role ? null : 'ไม่พบบทบาท BRANCH_MGR',
            $cashierRole ? null : 'ไม่พบบทบาท CASHIER',
        ]);
        foreach ($cashierCodes as $code) {
            $login = User::where('username', strtolower($code))->first();
            if ($login && $login->branch_id && $login->branch_id !== $branch?->id) {
                $errors[] = 'user '.strtolower($code).' สังกัดสาขาอื่นอยู่แล้ว';
            }
        }
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
            ...array_map(fn ($code, $i) => ['แคชเชียร์เครื่อง '.($i + 1), "{$code} ".$this->cashierName($branch, $code, count($cashierCodes)).' (user '.strtolower($code).')'], $cashierCodes, array_keys($cashierCodes)),
            ['หัวหน้า', "{$managerCode} หัวหน้า {$branch->name_th} (ผูก {$user->username})"],
            ['PIN', 'ตั้งที่ ERP: จัดการเครื่อง POS → คนขาย & PIN → ออก PIN ชั่วคราว (PIN เดิมไม่ถูกแตะ)'],
        ]);

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($branch, $user, $role, $cashierRole, $cashierCodes, $managerCode) {
                $user->update(['is_active' => true, 'branch_id' => $branch->id]);
                $user->roles()->syncWithoutDetaching([$role->id]);

                foreach ($cashierCodes as $code) {
                    $name = $this->cashierName($branch, $code, count($cashierCodes));
                    $login = User::firstOrCreate(['username' => strtolower($code)], [
                        'name' => $name, 'password' => Str::random(40), 'must_change_password' => true, 'is_active' => true,
                    ]);
                    $login->update(['name' => $name, 'branch_id' => $branch->id, 'is_active' => true]);
                    $login->roles()->syncWithoutDetaching([$cashierRole->id]);

                    $existing = Salesman::where('code', $code)->first();
                    $cashier = Salesman::updateOrCreate(['code' => $code], [
                        'branch_id' => $branch->id, 'user_id' => $login->id, 'name' => $name,
                        'is_active' => true,
                        // รันซ้ำเพื่อเพิ่มเครื่อง: รหัสเดิมที่ตั้ง PIN แล้วไม่ถูกบังคับเปลี่ยนใหม่
                        'must_change_pin' => $existing ? (bool) $existing->must_change_pin : true,
                    ]);
                    $login->update(['salesman_id' => $cashier->id]);
                    // PIN ที่ตั้งไว้ตอนยังไม่ผูก user ย้ายไปไว้ที่ user (ระบบอ่าน PIN ของ user ก่อน)
                    if ($cashier->pos_pin_hash && ! $login->posCredential?->pin_hash) {
                        UserPosCredential::firstOrCreate(['user_id' => $login->id])->forceFill([
                            'pin_hash' => $cashier->pos_pin_hash, 'force_pin_change' => (bool) $cashier->must_change_pin,
                            'credential_version' => now(),
                        ])->save();
                    }
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

        $this->info("ตั้งคนขายสาขา {$branch->code} แล้ว — รหัสที่ยังไม่มี PIN ให้ออก PIN ชั่วคราวที่ ERP: ตั้งค่า → ผูกเครื่อง");

        return self::SUCCESS;
    }

    /** ชื่อแสดงในบิล/รายงานกะ — หลายเครื่องใส่เลขเครื่องท้ายรหัสให้รู้ว่าลิ้นชักไหน */
    private function cashierName(Branch $branch, string $code, int $count): string
    {
        // รหัสไม่มีเลขท้าย (B001) คือเครื่องแรก เมื่อสาขามีหลายเครื่อง
        $suffix = preg_match('/-(\d+)$/', $code, $m) ? ' เครื่อง '.$m[1] : ($count > 1 ? ' เครื่อง 1' : '');

        return 'แคชเชียร์ '.$branch->name_th.$suffix;
    }
}
