<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpHealth extends Command
{
    protected $signature = 'erp:health {--max-backup-age=26 : Maximum backup age in hours}';

    protected $description = 'Check ERP database, migrations, queue failures, storage, and backup freshness';

    public function handle(): int
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks[] = ['ฐานข้อมูล', 'ผ่าน', 'เชื่อมต่อได้'];
        } catch (Throwable $exception) {
            $checks[] = ['ฐานข้อมูล', 'ไม่ผ่าน', $exception->getMessage()];
        }

        try {
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
                ->keys()->diff(app('migrator')->getRepository()->getRan())->count();
            $checks[] = ['Migration', $pending === 0 ? 'ผ่าน' : 'ไม่ผ่าน', $pending === 0 ? 'เป็นปัจจุบัน' : "ค้าง {$pending} รายการ"];
        } catch (Throwable $exception) {
            $checks[] = ['Migration', 'ไม่ผ่าน', $exception->getMessage()];
        }

        $backupDir = storage_path('app/backups');
        $latest = collect(glob($backupDir.'/erp-db-*') ?: [])->sortByDesc(fn (string $file) => filemtime($file))->first();
        $maxAge = max(1, (int) $this->option('max-backup-age'));
        $backupAge = $latest ? (time() - filemtime($latest)) / 3600 : null;
        $checks[] = [
            'Backup',
            $backupAge !== null && $backupAge <= $maxAge ? 'ผ่าน' : 'ไม่ผ่าน',
            $latest ? number_format($backupAge, 1).' ชั่วโมงที่แล้ว' : 'ยังไม่พบไฟล์สำรอง',
        ];

        $checks[] = ['Storage', is_writable(storage_path()) ? 'ผ่าน' : 'ไม่ผ่าน', storage_path()];
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $checks[] = ['Queue', $failedJobs === 0 ? 'ผ่าน' : 'เตือน', "งานล้มเหลว {$failedJobs} รายการ"];

        $this->table(['รายการ', 'ผล', 'รายละเอียด'], $checks);

        return collect($checks)->contains(fn (array $row) => $row[1] === 'ไม่ผ่าน')
            ? self::FAILURE
            : self::SUCCESS;
    }
}
