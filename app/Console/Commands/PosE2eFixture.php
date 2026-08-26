<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\PosTerminal;
use App\Models\Product;
use App\Models\Role;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * เตรียมข้อมูล + ออก device token สำหรับ e2e ของ POS Python (ดู .github/workflows/pos-e2e.yml)
 *
 * รันหลัง migrate:fresh + tools/staging/seed_uat_data.php (ซึ่งสร้างสาขา UAT + สินค้า)
 * แล้วเติมสิ่งที่ Python ต้องใช้ยิงขาย: cashier user (สิทธิ์ pos.sell), salesman + PIN,
 * terminal และ device token — พิมพ์ POS_E2E_TOKEN / POS_E2E_BRANCH ออกให้ workflow อ่าน
 *
 * idempotent: รันซ้ำได้ ออก token ใหม่ทุกครั้ง (ของเดิมถูกเพิกถอนโดยปริยายเพราะ hash เปลี่ยน)
 */
class PosE2eFixture extends Command
{
    protected $signature = 'pos:e2e-fixture {--pin=1234} {--branch=UAT}';

    protected $description = 'เตรียม cashier/salesman/PIN/device token สำหรับ e2e ของ POS Python';

    public function handle(): int
    {
        $branch = Branch::where('code', $this->option('branch'))->first();
        if (! $branch) {
            $this->error('ไม่พบสาขา '.$this->option('branch').' — รัน tools/staging/seed_uat_data.php ก่อน');

            return self::FAILURE;
        }

        if (! Product::where('is_active', true)->exists()) {
            $this->error('ยังไม่มีสินค้า — รัน seed_uat_data.php ก่อน');

            return self::FAILURE;
        }

        // role ที่มีสิทธิ์ pos.sell (มาจาก migration ไม่สร้างเอง กัน seeding trap)
        $role = Role::whereHas('permissions', fn ($q) => $q->where('code', 'pos.sell'))->orderBy('id')->first();
        if (! $role) {
            $this->error('ไม่มี role ที่ถือสิทธิ์ pos.sell — migration สิทธิ์ยังไม่รัน');

            return self::FAILURE;
        }

        $user = User::firstOrCreate(
            ['username' => 'pos_e2e'],
            ['name' => 'POS E2E', 'password' => bcrypt('e2e-'.bin2hex(random_bytes(8))), 'is_active' => true]
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        $salesman = Salesman::firstOrCreate(
            ['code' => 'E2E01'],
            ['name' => 'พนักงาน E2E', 'branch_id' => $branch->id, 'is_active' => true]
        );
        $salesman->forceFill(['branch_id' => $branch->id, 'user_id' => $user->id, 'is_active' => true])->save();
        $salesman->setPin((string) $this->option('pin'), false);

        $user->forceFill(['branch_id' => $branch->id, 'salesman_id' => $salesman->id])->save();

        $terminal = PosTerminal::firstOrCreate(
            ['code' => 'E2E-'.$branch->code],
            ['branch_id' => $branch->id, 'name' => 'E2E '.$branch->code]
        );

        [, $token] = PosDevice::issue([
            'name' => 'E2E device',
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'terminal_code' => $terminal->code,
        ]);

        // บรรทัดที่ workflow grep เอาไปใส่ env
        $this->line('POS_E2E_TOKEN='.$token);
        $this->line('POS_E2E_BRANCH='.$branch->id);
        $this->line('POS_E2E_PIN='.$this->option('pin'));

        return self::SUCCESS;
    }
}
