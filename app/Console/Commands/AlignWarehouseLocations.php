<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * จัดระเบียบรหัสพื้นที่เก็บให้ตรงกับรหัสสาขา
 *
 * ฐานนี้มีรหัสพื้นที่เก็บสองชุดซ้อนกัน: ชุดสั้น (001, 012, 016) เป็นชื่อคลังจาก
 * ระบบเก่า และชุด "คลังสาขา..." (0001–0007) เป็นชุดที่ตั้งใหม่ สาขาบางแห่งยังชี้
 * ไปชุดเก่า บางแห่งชี้ชุดใหม่ ปนกันอยู่
 *
 * ที่แย่กว่านั้นคือชุดสั้นใช้เลขเดียวกับรหัสสาขาเดิม ทำให้ "0001" หมายถึงทั้ง
 * สำนักงานใหญ่และคลังสาขาดอนกลาง ซึ่งเป็นต้นเหตุที่เครื่อง POS สามเครื่อง
 * ถูกผูกผิดสาขามาตลอด
 *
 * หลังคำสั่งนี้ พื้นที่ขายของแต่ละสาขาจะใช้รหัสเดียวกับสาขา (B001, HQ) เลขหนึ่งตัว
 * จึงหมายถึงที่เดียวเสมอ ส่วนพื้นที่เก่าที่ไม่ใช้แล้วขึ้นต้นด้วย OLD- ให้เห็นชัดว่าอย่าหยิบ
 */
class AlignWarehouseLocations extends Command
{
    protected $signature = 'erp:align-locations
        {--confirm-database= : ชื่อฐานข้อมูล ต้องพิมพ์ให้ตรง}
        {--dry-run : แสดงแผนโดยไม่แก้ข้อมูล}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'ตั้งรหัสพื้นที่เก็บของสาขาให้ตรงกับรหัสสาขา และเก็บของเก่าเข้ากรุ';

    /**
     * รหัสสาขา => รหัสพื้นที่เก็บเดิมที่จะกลายเป็นพื้นที่ขายของสาขานั้น
     *
     * เลือกชุด "คลังสาขา..." ทุกที่ที่มี เพราะชุดสั้นเป็นชื่อจากระบบเก่า
     * ยกเว้นห้วยวังนองที่ไม่มีชุดใหม่ จึงใช้ของเดิมต่อ
     */
    private const BRANCH_LOCATION = [
        'HQ' => 'HQ',       // สำนักงานใหญ่ (คลังกลาง)
        'B001' => '0002',   // คลังสาขาวาริน        (เดิมชี้ 001 หน้าร้าน วาริน)
        'B002' => '002',    // ห้วยวังนอง — ไม่มีชุดใหม่
        'B003' => '0003',   // คลังสาขาปลาดุก       (เดิมชี้ 009)
        'B004' => '0001',   // คลังสาขาดอนกลาง      (เดิมชี้ 011)
        'B005' => '0006',   // คลังสาขาสุรินทร์
        'B006' => '0007',   // คลังสาขาอำนาจเจริญ
        'B007' => '0004',   // คลังสาขาเจริญศรี      (เดิมชี้ 016)
    ];

    /** พื้นที่จากระบบเก่าที่ไม่มีสาขาใช้แล้ว — เก็บไว้แต่ทำให้เห็นชัดว่าเลิกใช้ */
    private const RETIRED = ['001', '009', '011', '012', '014', '016'];

    /** พื้นที่ที่เป็นของสำนักงานใหญ่แต่ยังไม่มีชื่อ */
    private const HQ_EXTRA = ['HONA' => 'HQ-02'];

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        $plan = $this->plan();
        if ($plan['problems'] !== []) {
            $this->error('หยุด: '.count($plan['problems']).' ข้อ');
            foreach ($plan['problems'] as $problem) {
                $this->line('  '.$problem);
            }

            return self::FAILURE;
        }

        $this->table(['สาขา', 'พื้นที่เดิม', 'ชื่อพื้นที่', 'รหัสใหม่', 'เปลี่ยนพื้นที่ที่ขายไหม'], $plan['branches']);
        if ($plan['retired'] !== []) {
            $this->line('พื้นที่จากระบบเก่าที่จะเก็บเข้ากรุ:');
            $this->table(['รหัสเดิม', 'ชื่อ', 'รหัสใหม่'], $plan['retired']);
        }
        if ($plan['hq_extra'] !== []) {
            $this->line('พื้นที่ของสำนักงานใหญ่ที่ยังไม่มีชื่อ:');
            $this->table(['รหัสเดิม', 'รหัสใหม่'], $plan['hq_extra']);
        }

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('เขียนรหัสพื้นที่เก็บใหม่?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($plan) {
                foreach ($plan['branches'] as [$branchCode, $legacyCode, , $newCode]) {
                    $locationId = DB::table('warehouse_locations')->where('code', $legacyCode)->value('id');
                    DB::table('warehouse_locations')->where('id', $locationId)->update(['code' => $newCode]);
                    Branch::where('code', $branchCode)->update(['default_warehouse_location_id' => $locationId]);
                }
                foreach ($plan['retired'] as [$legacyCode, , $newCode]) {
                    DB::table('warehouse_locations')->where('code', $legacyCode)->update(['code' => $newCode]);
                }
                foreach ($plan['hq_extra'] as [$legacyCode, $newCode]) {
                    DB::table('warehouse_locations')->where('code', $legacyCode)->update([
                        'code' => $newCode,
                        'name' => 'สำนักงานใหญ่ (พื้นที่ '.$legacyCode.' เดิม)',
                    ]);
                }
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีอะไรถูกเปลี่ยน: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('จัดระเบียบรหัสพื้นที่เก็บเรียบร้อย');

        return self::SUCCESS;
    }

    /** @return array{branches:array, retired:array, hq_extra:array, problems:array<int,string>} */
    private function plan(): array
    {
        $problems = [];
        $branches = [];
        $taken = [];

        foreach (self::BRANCH_LOCATION as $branchCode => $legacyCode) {
            $branch = Branch::where('code', $branchCode)->first();
            if (! $branch) {
                $problems[] = "ไม่พบสาขา {$branchCode} — ทำ master cutover แล้วหรือยัง";

                continue;
            }

            $location = DB::table('warehouse_locations')->where('code', $legacyCode)->first();
            if (! $location) {
                $problems[] = "ไม่พบพื้นที่เก็บ {$legacyCode} ที่จะให้เป็นพื้นที่ขายของ {$branchCode}";

                continue;
            }

            $moves = (int) $branch->default_warehouse_location_id !== (int) $location->id;
            $branches[] = [
                $branchCode, $legacyCode, mb_substr((string) ($location->name ?? '-'), 0, 24),
                $branchCode, $moves ? 'ใช่' : 'ไม่',
            ];
            $taken[$branchCode] = $legacyCode;
        }

        $retired = [];
        foreach (self::RETIRED as $legacyCode) {
            $location = DB::table('warehouse_locations')->where('code', $legacyCode)->first();
            if ($location) {
                $retired[] = [$legacyCode, mb_substr((string) ($location->name ?? '-'), 0, 24), 'OLD-'.$legacyCode];
                $taken['OLD-'.$legacyCode] = $legacyCode;
            }
        }

        $hqExtra = [];
        foreach (self::HQ_EXTRA as $legacyCode => $newCode) {
            if (DB::table('warehouse_locations')->where('code', $legacyCode)->exists()) {
                $hqExtra[] = [$legacyCode, $newCode];
                $taken[$newCode] = $legacyCode;
            }
        }

        // รหัสใหม่ต้องไม่ไปชนของที่มีอยู่และไม่ได้กำลังถูกเปลี่ยน
        foreach ($taken as $newCode => $fromCode) {
            $clash = DB::table('warehouse_locations')->where('code', $newCode)->first();
            if ($clash && $clash->code !== $fromCode && ! in_array($clash->code, $taken, true)) {
                $problems[] = "รหัสใหม่ {$newCode} ชนกับพื้นที่ที่มีอยู่แล้ว";
            }
        }

        return ['branches' => $branches, 'retired' => $retired, 'hq_extra' => $hqExtra, 'problems' => $problems];
    }
}
