<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Mssql\InteractsWithMssql;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Import ผู้ใช้เดิมจาก BPlus (USERTAB + กลุ่ม POSNDEF) เข้า users พร้อมบทบาท
 * ที่ map ตรงกับ 12 บทบาทที่ seed ไว้.
 *
 * ความปลอดภัย: รหัสผ่านเดิมของ BPlus เก็บ plaintext ยาว 2-8 ตัวอักษร ห้ามนำมา
 * ใช้ต่อ - ระบบสุ่มรหัสชั่วคราวแบบแข็งแรงให้ทุกคน, ตั้ง must_change_password
 * ให้บังคับเปลี่ยนตอน login ครั้งแรก, และเขียนรายการรหัสชั่วคราวลงไฟล์ใน
 * storage/app (นอก public) ให้ admin แจกพนักงานแล้วลบทิ้ง.
 */
class BplusUserSync extends Command
{
    use InteractsWithMssql;

    protected $signature = 'bplus:sync-users {--dry-run : แสดงผลอย่างเดียว ไม่เขียนลงฐานข้อมูล}';

    protected $description = 'Import ผู้ใช้จาก BPlus USERTAB พร้อมบทบาท + รหัสผ่านชั่วคราวปลอดภัย';

    private const POSN_TO_ROLE = [
        '1' => 'GM',
        '101' => 'ACC_MGR',
        '102' => 'IT_MGR',
        '103' => 'HR_MGR',
        '104' => 'ACC',
        '105' => 'HR',
        '106' => 'CASHIER',
        '108' => 'WAREHOUSE',
        '109' => 'PURCHASING',
        '110' => 'MARKETING',
        '112' => 'BRANCH_MGR',
        '113' => 'SALES',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $posnNames = collect($this->fetchAll('SELECT POSN_KEY, POSN_THAIDESC FROM POSNDEF'))
            ->pluck('POSN_THAIDESC', 'POSN_KEY');
        $roleIdByCode = Role::pluck('id', 'code');

        $rows = $this->fetchAll(
            'SELECT USER_CODE, USER_TH_NAME, USER_EN_NAME, USER_TH_POSITION, USER_POSN, USER_LEVEL
             FROM USERTAB ORDER BY USER_KEY'
        );
        $this->info('อ่านผู้ใช้จาก MSSQL: '.count($rows).' คน');

        $created = 0;
        $skippedExists = [];
        $issued = [];

        foreach ($rows as $r) {
            $code = trim((string) $r['USER_CODE']);
            if ($code === '') {
                continue;
            }
            $username = Str::lower($code);

            if (User::whereRaw('LOWER(username) = ?', [$username])->exists()) {
                $skippedExists[] = $username;

                continue;
            }

            $posn = (string) $r['USER_POSN'];
            $roleCode = self::POSN_TO_ROLE[$posn] ?? 'SALES';
            // USER_LEVEL 9 = ระดับสูงสุดของ BPlus
            if ((string) $r['USER_LEVEL'] === '9') {
                $roleCode = 'GM';
            }

            $name = trim((string) ($r['USER_TH_NAME'] ?? '')) ?: (trim((string) ($r['USER_EN_NAME'] ?? '')) ?: $code);
            $position = trim((string) ($r['USER_TH_POSITION'] ?? '')) ?: ($posnNames[$posn] ?? null);
            $tempPassword = $this->strongTempPassword();

            if (! $dry) {
                $user = User::create([
                    'username' => $username,
                    'name' => $name,
                    'position' => $position,
                    'password' => $tempPassword,
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
                $roleId = $roleIdByCode[$roleCode] ?? null;
                if ($roleId) {
                    $user->roles()->sync([$roleId]);
                }
            }

            $issued[] = [$username, $name, $posnNames[$posn] ?? $roleCode, $tempPassword];
            $created++;
        }

        if (! $dry && $issued !== []) {
            $file = storage_path('app/bplus-user-temp-passwords-'.now()->format('Ymd-His').'.csv');
            $fh = fopen($file, 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, ['username', 'ชื่อ', 'กลุ่ม/บทบาท', 'รหัสผ่านชั่วคราว (บังคับเปลี่ยนเมื่อ login ครั้งแรก)']);
            foreach ($issued as $line) {
                fputcsv($fh, $line);
            }
            fclose($fh);
            $this->warn('รายการรหัสผ่านชั่วคราว: '.$file);
            $this->warn('แจกให้พนักงานแล้วลบไฟล์นี้ทิ้งทันที');
        }

        $this->table(['ผลลัพธ์', 'จำนวน'], [
            ['สร้างผู้ใช้ใหม่'.($dry ? ' (dry-run)' : ''), $created],
            ['ข้าม - username ซ้ำกับที่มีอยู่', count($skippedExists)],
        ]);
        if ($skippedExists !== []) {
            $this->line('ข้าม: '.implode(', ', $skippedExists));
        }

        return self::SUCCESS;
    }

    // รหัสชั่วคราว 12 ตัว: การันตีมีพิมพ์ใหญ่+เล็ก+ตัวเลข (ผ่านกติกา Password rule)
    // ตัดตัวอักษรที่อ่านสับสน (0/O, 1/l/I) ออกเพื่อพิมพ์ตามง่าย
    private function strongTempPassword(): string
    {
        $upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $all = $upper.$lower.$digits;

        $password = $upper[random_int(0, strlen($upper) - 1)]
            .$lower[random_int(0, strlen($lower) - 1)]
            .$digits[random_int(0, strlen($digits) - 1)];
        for ($i = 0; $i < 9; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }
}
