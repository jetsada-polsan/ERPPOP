<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Production gate ที่อ่านอย่างเดียวสำหรับก่อนเปิดใช้งานจริงหรือ deploy.
 * ไม่สร้าง token, ไม่สร้างบิล และไม่แก้ข้อมูลธุรกิจ.
 */
class ErpReadiness extends Command
{
    protected $signature = 'erp:readiness {--max-backup-age=26 : Maximum backup age in hours}';

    protected $description = 'Check production readiness for POS sync, accounting, tax and operations';

    public function handle(): int
    {
        $checks = [];
        $this->checkDatabase($checks);
        $this->checkMigrations($checks);
        $this->checkBackup($checks);
        $this->checkSalesLedger($checks);
        $this->checkPosDevices($checks);
        $this->checkIdempotency($checks);
        $this->checkOperationalWarnings($checks);

        $this->table(['ตรวจ', 'ผล', 'รายละเอียด'], $checks);

        $failed = count(array_filter($checks, fn (array $check): bool => $check[1] === 'ไม่ผ่าน'));
        $warnings = count(array_filter($checks, fn (array $check): bool => $check[1] === 'เตือน'));
        $this->line($failed === 0
            ? '<info>พร้อมทดสอบ/เปิดใช้งาน'.($warnings ? " (มี {$warnings} คำเตือน)" : '').'</info>'
            : "<error>ยังไม่พร้อม: {$failed} รายการ</error>");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<int, array{0:string,1:string,2:string}> $checks */
    private function checkDatabase(array &$checks): void
    {
        try {
            DB::select('select 1');
            $checks[] = ['ฐานข้อมูล', 'ผ่าน', 'เชื่อมต่อได้'];
        } catch (Throwable $e) {
            $checks[] = ['ฐานข้อมูล', 'ไม่ผ่าน', $e->getMessage()];
        }
    }

    /** @param array<int, array{0:string,1:string,2:string}> $checks */
    private function checkMigrations(array &$checks): void
    {
        try {
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
                ->keys()->diff(app('migrator')->getRepository()->getRan())->count();
            $checks[] = ['Migration', $pending === 0 ? 'ผ่าน' : 'ไม่ผ่าน', $pending === 0 ? 'เป็นปัจจุบัน' : "ค้าง {$pending} รายการ"];
        } catch (Throwable $e) {
            $checks[] = ['Migration', 'ไม่ผ่าน', $e->getMessage()];
        }
    }

    /** @param array<int, array{0:string,1:string,2:string}> $checks */
    private function checkBackup(array &$checks): void
    {
        $latest = collect(glob(storage_path('app/backups/erp-db-*')) ?: [])
            ->sortByDesc(fn (string $file) => filemtime($file))->first();
        $age = $latest ? (time() - filemtime($latest)) / 3600 : null;
        $maxAge = max(1, (int) $this->option('max-backup-age'));
        $validChecksum = $this->hasValidChecksum($latest);
        $ok = $latest && $age <= $maxAge && $validChecksum;
        $checks[] = ['Backup', $ok ? 'ผ่าน' : 'ไม่ผ่าน', $latest
            ? number_format($age, 1).' ชั่วโมง · '.($validChecksum ? 'checksum ถูกต้อง' : 'checksum ไม่ถูกต้อง/ไม่มี')
            : 'ยังไม่พบไฟล์สำรอง'];
    }

    private function hasValidChecksum(?string $backup): bool
    {
        if (! $backup || ! is_file($backup) || ! is_file($backup.'.sha256')) {
            return false;
        }

        $parts = preg_split('/\s+/', trim(File::get($backup.'.sha256')));
        $expected = strtolower((string) ($parts[0] ?? ''));
        $actual = hash_file('sha256', $backup);

        return $actual !== false && strlen($expected) === 64 && hash_equals($expected, $actual);
    }

    /** @param array<int, array{0:string,1:string,2:string}> $checks */
    private function checkSalesLedger(array &$checks): void
    {
        if (! Schema::hasTable('documents') || ! Schema::hasTable('gl_journals')) {
            $checks[] = ['ขาย-GL', 'ไม่ผ่าน', 'ไม่พบตารางเอกสารหรือ GL'];
            return;
        }

        $missing = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
            ->where('d.status', 'active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gl_journals as g')->whereColumn('g.document_id', 'd.id'))
            ->count();
        $checks[] = ['ขาย-GL', $missing === 0 ? 'ผ่าน' : 'ไม่ผ่าน', $missing === 0 ? 'เอกสารขายลง GL ครบ' : "ขาด GL {$missing} ใบ"];
    }

    /** @param array<int, array{0:string,1:string,2:string}> $checks */
    private function checkPosDevices(array &$checks): void
    {
        if (! Schema::hasTable('pos_devices')) {
            $checks[] = ['POS device', 'เตือน', 'ยังไม่มีตารางอุปกรณ์ POS'];
            return;
        }

        $total = DB::table('pos_devices')->whereNull('revoked_at')->count();
        $invalid = DB::table('pos_devices')->whereNull('revoked_at')->where(function ($q) {
            $q->whereNull('branch_id')->orWhereNull('user_id');
        })->count();
        $checks[] = ['POS device', $invalid === 0 ? 'ผ่าน' : 'ไม่ผ่าน', "ใช้งาน {$total} เครื่อง · {$invalid} เครื่องผูกข้อมูลไม่ครบ"];
    }

    /** @param array<int, array{0:string,1:string,2:string}> $checks */
    private function checkIdempotency(array &$checks): void
    {
        if (! Schema::hasTable('pos_api_idempotency')) {
            $checks[] = ['POS idempotency', 'ไม่ผ่าน', 'ไม่พบตารางกันบิลซ้ำ'];
            return;
        }

        $stuck = DB::table('pos_api_idempotency')->where('state', 'processing')->where('updated_at', '<', now()->subMinutes(10))->count();
        $checks[] = ['POS idempotency', $stuck === 0 ? 'ผ่าน' : 'ไม่ผ่าน', $stuck === 0 ? 'ไม่มีบิลค้างประมวลผลเกิน 10 นาที' : "มี {$stuck} รายการค้าง"];
    }

    /** @param array<int, array{0:string,1:string,2:string}> $checks */
    private function checkOperationalWarnings(array &$checks): void
    {
        if (Schema::hasTable('failed_jobs')) {
            $failedJobs = DB::table('failed_jobs')->count();
            $checks[] = ['Queue', $failedJobs === 0 ? 'ผ่าน' : 'เตือน', "งานล้มเหลว {$failedJobs} รายการ"];
        }
        if (Schema::hasTable('etax_documents')) {
            $rejected = DB::table('etax_documents')->where('status', 'rejected')->count();
            $checks[] = ['E-Tax', $rejected === 0 ? 'ผ่าน' : 'เตือน', $rejected === 0 ? 'ไม่มีรายการถูกปฏิเสธ' : "มี {$rejected} รายการต้องติดตาม"];
        }
    }
}
