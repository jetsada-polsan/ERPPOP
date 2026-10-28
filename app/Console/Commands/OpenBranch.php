<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * เปิดสาขาใหม่ให้พร้อมผูกเครื่อง POS ในคำสั่งเดียว
 *
 * สร้าง: สาขา, คลัง WH-<รหัส> + ตำแหน่ง MAIN (ตั้งเป็นคลังเริ่มต้น), user ของสาขา (ถ้ายังไม่มี),
 * แคชเชียร์ 1 รหัสต่อเครื่อง และหัวหน้า ผ่าน erp:setup-branch-pos-staff
 * ตารางราคาและนโยบายสต๊อกติดลบคัดลอกจากสาขาต้นแบบ (--like)
 *
 * ไม่ออก PIN / รหัสผ่าน / token ให้ — ทำที่หน้า ตั้งค่า → ผูกเครื่อง และหน้าผู้ใช้ ตอนติดตั้งจริง
 * user ใหม่ได้รหัสผ่านสุ่มที่ไม่มีใครรู้ ต้องกดรีเซ็ตรหัสผ่านที่หน้าผู้ใช้ก่อนใช้งาน
 */
class OpenBranch extends Command
{
    protected $signature = 'erp:open-branch
        {code : รหัสสาขาใหม่ เช่น B002}
        {name : ชื่อสาขา}
        {--user= : username ของสาขา (ไม่มีจะสร้างให้)}
        {--tills=1 : จำนวนเครื่อง POS (1-9)}
        {--like= : สาขาต้นแบบสำหรับตารางราคา/นโยบายสต๊อก (ค่าเริ่มต้น: สาขาแรกที่ไม่ใช่ HQ)}
        {--dry-run : สรุปโดยไม่บันทึก}';

    protected $description = 'Open a new branch with its warehouse, login, till cashier codes and manager';

    public function handle(): int
    {
        $code = strtoupper(trim((string) $this->argument('code')));
        $name = trim((string) $this->argument('name'));
        $username = strtolower(trim((string) $this->option('user')));
        $tills = (int) $this->option('tills');
        $template = $this->option('like')
            ? Branch::where('code', $this->option('like'))->first()
            : Branch::where('code', '!=', 'HQ')->where('is_active', true)->orderBy('code')->first();

        $errors = array_filter([
            preg_match('/^[A-Z0-9]{2,10}$/', $code) ? null : 'รหัสสาขาต้องเป็นตัวอักษร/ตัวเลข 2-10 ตัว เช่น B002',
            Branch::where('code', $code)->exists() ? "มีสาขา {$code} อยู่แล้ว (เพิ่มเครื่องให้สาขาเดิมใช้ erp:setup-branch-pos-staff)" : null,
            $name !== '' ? null : 'ต้องระบุชื่อสาขา',
            preg_match('/^[a-z0-9_.-]{3,40}$/', $username) ? null : 'ต้องระบุ --user เป็นภาษาอังกฤษตัวเล็ก 3-40 ตัว',
            $tills >= 1 && $tills <= 9 ? null : '--tills ต้องอยู่ระหว่าง 1-9',
            $this->option('like') && ! $template ? 'ไม่พบสาขาต้นแบบ '.$this->option('like') : null,
        ]);
        $existingUser = $username !== '' ? User::where('username', $username)->first() : null;
        if ($existingUser?->branch_id && $existingUser->branch?->code !== $code) {
            $errors[] = "user {$username} สังกัดสาขา {$existingUser->branch?->code} อยู่แล้ว ใช้ชื่ออื่น";
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // B002 -> แคชเชียร์ B002, B002-2, B002-3 / หัวหน้า M002
        $cashiers = collect(range(1, $tills))->map(fn ($n) => $n === 1 ? $code : "{$code}-{$n}")->all();
        $manager = preg_match('/(\d+)$/', $code, $m) ? 'M'.$m[1] : 'M-'.$code;

        $this->table(['รายการ', 'ค่า'], [
            ['สาขา', "{$code} {$name}"],
            ['คลัง', "WH-{$code} / MAIN (คลังเริ่มต้นของสาขา)"],
            ['ราคา/สต๊อกติดลบ', 'คัดลอกจาก '.($template ? "{$template->code} {$template->name_th}" : '— (ค่าเริ่มต้นระบบ)')],
            ['user ของสาขา', $existingUser ? "{$username} (มีอยู่แล้ว)" : "{$username} (สร้างใหม่ — รีเซ็ตรหัสผ่านที่หน้าผู้ใช้ก่อนใช้)"],
            ['แคชเชียร์', implode(', ', $cashiers)],
            ['หัวหน้า', $manager],
            ['ถัดไป', 'ตั้งค่า → ผูกเครื่อง: ออก PIN ชั่วคราว + ออก Token ตอนติดตั้งเครื่องจริง'],
        ]);

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($code, $name, $username, $existingUser, $template, $cashiers, $manager) {
                $branch = Branch::create([
                    'code' => $code, 'name_th' => $name, 'is_active' => true,
                    'price_table_id' => $template?->price_table_id,
                    'negative_stock_policy' => $template?->negative_stock_policy,
                ]);
                $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.$code, 'name' => 'คลัง '.$name]);
                $location = WarehouseLocation::create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'พื้นที่หลัก '.$name]);
                $branch->update(['default_warehouse_location_id' => $location->id]);

                if (! $existingUser) {
                    User::create([
                        'username' => $username, 'name' => $name, 'branch_id' => $branch->id,
                        'password' => Str::random(40), 'must_change_password' => true, 'is_active' => true,
                    ]);
                }

                $status = $this->call('erp:setup-branch-pos-staff', [
                    'branch' => $code, '--user' => $username, '--cashier' => $cashiers, '--manager' => $manager,
                ]);
                if ($status !== self::SUCCESS) {
                    throw new \RuntimeException('ตั้งคนขายไม่สำเร็จ');
                }
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("เปิดสาขา {$code} แล้ว — ไปที่ ตั้งค่า → ผูกเครื่อง เพื่อออก PIN และ Token เมื่อติดตั้งเครื่อง");

        return self::SUCCESS;
    }
}
