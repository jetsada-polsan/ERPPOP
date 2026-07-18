<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberType;
use App\Services\Mssql\InteractsWithMssql;
use Illuminate\Console\Command;

/**
 * Sync สมาชิก (MEMBER) จาก BPlus MSSQL เข้า members - ตารางเดียวที่ ETL หลัก
 * ไม่ได้ครอบคลุม. แต้มสะสมเดิมอยู่ MB_SH_POINT, รหัสสมาชิกคือ MB_CODE
 * (ร้านนี้ใช้เบอร์โทรเป็นรหัส). รันซ้ำได้ (updateOrCreate ตาม member_code).
 */
class BplusMemberSync extends Command
{
    use InteractsWithMssql;

    protected $signature = 'bplus:sync-members {--dry-run : แสดงผลอย่างเดียว ไม่เขียนลงฐานข้อมูล}';

    protected $description = 'Sync สมาชิกและแต้มสะสมจาก BPlus MSSQL (MEMBER) เข้า members';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // ประเภทสมาชิก (BPlus เก็บใน MBTYPE)
        $typeIdByKey = [];
        try {
            foreach ($this->fetchAll('SELECT * FROM MBTYPE') as $t) {
                $key = (string) ($t['MBT_KEY'] ?? $t['MT_KEY'] ?? '');
                $name = trim((string) ($t['MBT_DESC'] ?? $t['MBT_NAME'] ?? $t['MT_DESC'] ?? ''));
                if ($key === '') {
                    continue;
                }
                if (! $dry) {
                    $type = MemberType::firstOrCreate(['code' => $key], ['name' => $name ?: ('ประเภท '.$key)]);
                    $typeIdByKey[$key] = $type->id;
                }
            }
            $this->info('ประเภทสมาชิกจาก MBTYPE: '.count($typeIdByKey));
        } catch (\Throwable $e) {
            $this->warn('ข้ามประเภทสมาชิก (อ่าน MBTYPE ไม่ได้): '.$e->getMessage());
        }

        $rows = $this->fetchAll(
            'SELECT MB_CODE, MB_INTL, MB_NAME, MB_SURNME, MB_PHONE, MB_PHONE_HND,
                    MB_SH_POINT, MB_ENABLED, MB_MT, MB_SINCE
             FROM MEMBER ORDER BY MB_KEY'
        );
        $this->info('อ่านสมาชิกจาก MSSQL: '.count($rows).' คน');

        $synced = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $code = trim((string) $r['MB_CODE']);
            if ($code === '') {
                $skipped++;

                continue;
            }

            $name = trim(trim((string) $r['MB_INTL']).trim((string) $r['MB_NAME']).' '.trim((string) $r['MB_SURNME']));
            $phone = trim((string) ($r['MB_PHONE_HND'] ?? '')) ?: (trim((string) ($r['MB_PHONE'] ?? '')) ?: null);

            if (! $dry) {
                Member::updateOrCreate(
                    ['member_code' => $code],
                    [
                        'name' => $name !== '' ? $name : $code,
                        'phone' => $phone,
                        'member_type_id' => $typeIdByKey[(string) $r['MB_MT']] ?? null,
                        'points' => is_numeric($r['MB_SH_POINT']) ? (float) $r['MB_SH_POINT'] : 0,
                        'is_active' => ($r['MB_ENABLED'] ?? 'Y') === 'Y',
                    ]
                );
            }
            $synced++;
        }

        $this->table(['ผลลัพธ์', 'จำนวน'], [
            ['sync สมาชิก'.($dry ? ' (dry-run)' : ''), number_format($synced)],
            ['ข้าม (ไม่มีรหัส)', number_format($skipped)],
        ]);
        if (! $dry) {
            $this->info('members ทั้งหมดตอนนี้: '.number_format(Member::count()).' คน');
        }

        return self::SUCCESS;
    }
}
