<?php

namespace App\Console\Commands;

use App\Services\AlertDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpHealth extends Command
{
    protected $signature = 'erp:health {--max-backup-age=26 : Maximum backup age in hours}';

    protected $description = 'Check ERP database, migrations, queue failures, storage, and backup freshness';

    public function handle(AlertDispatchService $alerts): int
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

        // เอกสารขายที่ไม่มี GL — เคยเกิดจริงกับบิล POS 5 ใบแรก (6-12 ก.ค. 2026) ที่ขายก่อนต่อ GL
        // ตอนนั้นไม่มีอะไรจับได้เลยจนกระทบยอดตอนตรวจสองเดือนถัดมา
        $checks[] = $this->salesWithoutLedger();

        $checks[] = ['Storage', is_writable(storage_path()) ? 'ผ่าน' : 'ไม่ผ่าน', storage_path()];
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $checks[] = ['Queue', $failedJobs === 0 ? 'ผ่าน' : 'เตือน', "งานล้มเหลว {$failedJobs} รายการ"];

        $this->table(['รายการ', 'ผล', 'รายละเอียด'], $checks);
        if (Schema::hasTable('monitor_events')) {
            foreach ($checks as [$code, $status, $detail]) {
                if ($status === 'ผ่าน') {
                    DB::table('monitor_events')->where('check_code', $code)->where('status', 'open')
                        ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);

                    continue;
                }
                $exists = DB::table('monitor_events')->where('check_code', $code)->where('status', 'open')->exists();
                if (! $exists) {
                    DB::table('monitor_events')->insert([
                        'check_code' => $code, 'severity' => $status === 'ไม่ผ่าน' ? 'critical' : 'warning',
                        'status' => 'open', 'message' => $detail, 'detected_at' => now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $alerts->send("PopCentral ALERT\n{$code}: {$detail}");
                }
            }
        }

        return collect($checks)->contains(fn (array $row) => $row[1] === 'ไม่ผ่าน')
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * เอกสารขายที่ยืนยันแล้วต้องมีรายการ GL เสมอ
     *
     * ถ้าไม่มี แปลว่ายอดขายกับบัญชีเดินคนละทาง และจะรู้ตัวก็ต่อเมื่อปิดงวดไม่ลง
     * ตรวจทุกครั้งที่รัน erp:health จะเห็นตั้งแต่ใบแรกที่หลุด
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function salesWithoutLedger(): array
    {
        if (! Schema::hasTable('gl_journals') || ! Schema::hasTable('documents')) {
            return ['ขาย-GL', 'เตือน', 'ยังไม่มีตารางที่ต้องใช้'];
        }

        try {
            $missing = DB::table('documents as d')
                ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
                ->whereIn('dt.code', ['CASH_SALE', 'CREDIT_SALE'])
                ->where('d.status', 'active')
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                    ->from('gl_journals as g')->whereColumn('g.document_id', 'd.id'))
                ->selectRaw('count(*) as n, coalesce(sum(d.total_amount), 0) as amount')
                ->first();
        } catch (Throwable $exception) {
            return ['ขาย-GL', 'ไม่ผ่าน', $exception->getMessage()];
        }

        $count = (int) ($missing->n ?? 0);

        return [
            'ขาย-GL',
            $count === 0 ? 'ผ่าน' : 'ไม่ผ่าน',
            $count === 0
                ? 'เอกสารขายลง GL ครบ'
                : "เอกสารขาย {$count} ใบไม่มีรายการ GL รวม ".number_format((float) ($missing->amount ?? 0), 2).' บาท',
        ];
    }
}
