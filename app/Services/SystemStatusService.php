<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Read-only snapshot for the system status dashboard. */
class SystemStatusService
{
    /** @return array<string, array<string, mixed>> */
    public function snapshot(): array
    {
        return [
            'disk' => $this->card('พื้นที่ดิสก์', fn () => $this->disk()),
            'memory' => $this->card('หน่วยความจำ', fn () => $this->memory()),
            'cpu' => $this->card('โหลด CPU', fn () => $this->cpu()),
            'database' => $this->card('ฐานข้อมูล', fn () => $this->database()),
            'backup' => $this->card('Backup', fn () => $this->backup()),
            'queue' => $this->card('Queue', fn () => $this->queue()),
            'bplus_runs' => $this->card('ซิงค์ BPlus', fn () => $this->bplusRuns()),
            'bplus_tokens' => $this->card('Token ซิงค์', fn () => $this->bplusTokens()),
            'b18' => $this->card('ข้อมูล B18', fn () => $this->b18()),
        ];
    }

    /** @return array<string, mixed> */
    private function card(string $title, callable $reader): array
    {
        try {
            return ['title' => $title] + $reader();
        } catch (Throwable $exception) {
            $message = preg_replace('/[\r\n\t]+/', ' ', trim($exception->getMessage())) ?: 'เกิดข้อผิดพลาด';
            $message = preg_replace('/(?i)(password|token_hash|token|secret|authorization)\s*[:=]\s*[^,\s]+/', '$1=[ซ่อน]', $message) ?: $message;

            return [
                'title' => $title,
                'status' => 'unknown',
                'summary' => 'อ่านไม่ได้: '.mb_substr($message, 0, 180),
                'details' => [],
                'rows' => [],
            ];
        }
    }

    /** @return array<string, mixed> */
    private function disk(): array
    {
        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());
        if (! is_numeric($total) || ! is_numeric($free) || (float) $total <= 0) {
            throw new \RuntimeException('ไม่สามารถอ่านพื้นที่ของ path แอปได้');
        }

        $total = (float) $total;
        $free = (float) $free;
        $used = max(0, $total - $free);
        $percent = ($used / $total) * 100;
        $status = $percent > 90 ? 'danger' : ($percent >= 80 ? 'warning' : 'good');
        $folders = [];
        foreach (['pos-releases', 'pos-python-releases', 'jet-pos-python-releases', 'backups'] as $name) {
            $path = storage_path('app/'.$name);
            $folders[] = ['label' => $name, 'value' => $this->formatBytes($this->directoryBytes($path))];
        }

        return [
            'status' => $status,
            'summary' => number_format($percent, 1).'% ใช้ไป',
            'details' => [
                ['label' => 'ใช้ไป', 'value' => $this->formatBytes($used)],
                ['label' => 'ทั้งหมด', 'value' => $this->formatBytes($total)],
                ['label' => 'เหลือ', 'value' => $this->formatBytes($free)],
                ['label' => 'ตำแหน่ง', 'value' => base_path()],
            ],
            'rows' => $folders,
        ];
    }

    /** @return array<string, mixed> */
    private function memory(): array
    {
        $path = '/proc/meminfo';
        if (! is_readable($path)) {
            return ['status' => 'unknown', 'summary' => 'ไม่มีข้อมูล', 'details' => [], 'rows' => []];
        }

        $contents = file_get_contents($path);
        preg_match('/^MemTotal:\s+(\d+)\s+kB$/m', (string) $contents, $totalMatch);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', (string) $contents, $availableMatch);
        if (! isset($totalMatch[1], $availableMatch[1]) || (int) $totalMatch[1] <= 0) {
            return ['status' => 'unknown', 'summary' => 'ไม่มีข้อมูล', 'details' => [], 'rows' => []];
        }

        $total = (int) $totalMatch[1] * 1024;
        $available = (int) $availableMatch[1] * 1024;
        $percent = ($available / $total) * 100;

        return [
            'status' => $percent < 10 ? 'danger' : 'good',
            'summary' => number_format($percent, 1).'% ว่าง',
            'details' => [
                ['label' => 'ว่าง', 'value' => $this->formatBytes($available)],
                ['label' => 'ทั้งหมด', 'value' => $this->formatBytes($total)],
            ],
            'rows' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function cpu(): array
    {
        $loads = sys_getloadavg();
        if (! is_array($loads) || ! isset($loads[0], $loads[1], $loads[2])) {
            return ['status' => 'unknown', 'summary' => 'ไม่มีข้อมูล', 'details' => [], 'rows' => []];
        }

        $cores = 0;
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo)) {
            $cores = preg_match_all('/^processor\s*:/m', $cpuInfo) ?: 0;
        }
        if ($cores < 1) {
            return ['status' => 'unknown', 'summary' => 'ไม่มีข้อมูลจำนวน core', 'details' => [], 'rows' => []];
        }

        $load = (float) $loads[1];

        return [
            'status' => $load > ($cores * 2) ? 'danger' : 'good',
            'summary' => '5 นาที '.number_format($load, 2).' / '.$cores.' core',
            'details' => [
                ['label' => 'โหลด 1 นาที', 'value' => number_format((float) $loads[0], 2)],
                ['label' => 'โหลด 5 นาที', 'value' => number_format($load, 2)],
                ['label' => 'โหลด 15 นาที', 'value' => number_format((float) $loads[2], 2)],
                ['label' => 'จำนวน core', 'value' => (string) $cores],
            ],
            'rows' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $size = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS size_bytes')->size_bytes;
        } elseif ($driver === 'sqlite') {
            $path = DB::connection()->getDatabaseName();
            $size = $path !== ':memory:' && is_file($path) ? (int) filesize($path) : 0;
        } else {
            $size = null;
        }

        $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
            ->keys()->diff(app('migrator')->getRepository()->getRan())->count();

        return [
            'status' => 'neutral',
            'summary' => $size === null ? 'ไม่รองรับการอ่านขนาดของฐานข้อมูลนี้' : $this->formatBytes($size),
            'details' => [
                ['label' => 'ชนิดฐานข้อมูล', 'value' => $driver],
                ['label' => 'ขนาด', 'value' => $size === null ? 'ไม่มีข้อมูล' : $this->formatBytes($size)],
                ['label' => 'Migration ค้าง', 'value' => (string) $pending.' รายการ'],
            ],
            'rows' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function backup(): array
    {
        $files = array_values(array_filter(glob(storage_path('app/backups/erp-db-*')) ?: [], 'is_file'));
        $total = array_sum(array_map(static fn (string $file): int => (int) filesize($file), $files));
        usort($files, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
        $latest = $files[0] ?? null;
        $hours = $latest ? (time() - filemtime($latest)) / 3600 : null;

        return [
            'status' => $hours === null || $hours > 26 ? 'danger' : 'good',
            'summary' => $latest ? basename($latest) : 'ยังไม่พบไฟล์ Backup',
            'details' => [
                ['label' => 'ไฟล์ล่าสุด', 'value' => $latest ? Carbon::createFromTimestamp(filemtime($latest))->format('d/m/Y H:i:s') : 'ไม่มีข้อมูล'],
                ['label' => 'ขนาดไฟล์ล่าสุด', 'value' => $latest ? $this->formatBytes((int) filesize($latest)) : 'ไม่มีข้อมูล'],
                ['label' => 'จำนวนไฟล์', 'value' => (string) count($files)],
                ['label' => 'ขนาดรวม', 'value' => $this->formatBytes($total)],
            ],
            'rows' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function queue(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['status' => 'unknown', 'summary' => 'ไม่มีตาราง failed_jobs', 'details' => [], 'rows' => []];
        }

        $count = DB::table('failed_jobs')->count();
        $rows = DB::table('failed_jobs')->latest('failed_at')->limit(5)->get(['id', 'payload', 'exception', 'failed_at'])
            ->map(function (object $job): array {
                $payload = json_decode((string) $job->payload, true) ?: [];
                $name = data_get($payload, 'displayName') ?: data_get($payload, 'data.commandName') ?: data_get($payload, 'job') ?: 'ไม่ทราบชื่อ job';
                $exception = preg_split('/\r\n|\r|\n/', (string) $job->exception)[0] ?? '';

                return [
                    'label' => Carbon::parse($job->failed_at)->format('d/m/Y H:i:s').' · '.mb_substr((string) $name, 0, 100),
                    'value' => mb_substr($exception, 0, 150),
                ];
            })->all();

        return [
            'status' => $count > 0 ? 'warning' : 'good',
            'summary' => 'งานล้มเหลว '.$count.' รายการ',
            'details' => [],
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function bplusRuns(): array
    {
        if (! Schema::hasTable('bplus_sync_runs')) {
            return ['status' => 'unknown', 'summary' => 'ไม่มีตารางประวัติซิงค์', 'details' => [], 'rows' => []];
        }

        $runs = DB::table('bplus_sync_runs')->latest('created_at')->limit(10)->get();
        $lastSuccess = DB::table('bplus_sync_runs')->where('status', 'ok')->max('updated_at');
        $staleSuccess = ! $lastSuccess || Carbon::parse($lastSuccess)->lt(now()->subHours(8));
        $stuck = $runs->contains(fn (object $run): bool => $run->status === 'processing'
            && Carbon::parse($run->created_at)->lt(now()->subMinutes(15)));
        $rows = $runs->map(function (object $run): array {
            $seconds = max(0, strtotime((string) $run->updated_at) - strtotime((string) $run->created_at));
            $message = trim((string) ($run->message ?? ''));
            $message = $message === '' ? '—' : mb_substr(preg_replace('/\s+/', ' ', $message) ?: '', 0, 180);

            return [
                'label' => Carbon::parse($run->created_at)->format('d/m/Y H:i:s').' · '.(string) $run->route_code,
                'value' => 'สถานะ '.(string) $run->status.' · '.$seconds.' วินาที · รับ '.(int) $run->received.' ใบ · ปัญหา '.(int) $run->problems.' · '.$message,
            ];
        })->all();

        return [
            'status' => $staleSuccess || $stuck ? 'danger' : 'good',
            'summary' => $stuck ? 'พบรอบ processing เกิน 15 นาที' : ($staleSuccess ? 'รอบสำเร็จล่าสุดเกิน 8 ชั่วโมงหรือยังไม่มีรอบสำเร็จ' : 'รอบสำเร็จล่าสุด '.Carbon::parse($lastSuccess)->format('d/m/Y H:i:s')),
            'details' => [],
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function bplusTokens(): array
    {
        if (! Schema::hasTable('bplus_sync_tokens')) {
            return ['status' => 'unknown', 'summary' => 'ไม่มีตาราง Token ซิงค์', 'details' => [], 'rows' => []];
        }

        $tokens = DB::table('bplus_sync_tokens')->whereNull('revoked_at')->latest('id')
            ->get(['name', 'route_code', 'last_used_at', 'last_ip']);
        $rows = $tokens->map(fn (object $token): array => [
            'label' => (string) $token->name.' · '.(string) $token->route_code,
            'value' => 'ใช้ล่าสุด '.($token->last_used_at ? Carbon::parse($token->last_used_at)->format('d/m/Y H:i:s') : 'ยังไม่เคยใช้')
                .' · IP '.($token->last_ip ?: 'ไม่มีข้อมูล'),
        ])->all();

        return [
            'status' => $tokens->count() > 1 ? 'warning' : 'good',
            'summary' => 'ใช้งานอยู่ '.$tokens->count().' ตัว',
            'details' => [],
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function b18(): array
    {
        $types = ['BOOKING', 'CREDIT_SALE', 'CREDIT_NOTE', 'RECEIPT', 'OPENING_AR'];
        $documents = DB::table('documents as d')
            ->join('document_types as dt', 'dt.id', '=', 'd.document_type_id')
            ->where('d.source_system', 'bplus')->whereNull('d.cancelled_at')
            ->whereNotIn('d.status', ['cancelled', 'void'])
            ->whereIn('dt.code', $types)
            ->selectRaw('dt.code, count(*) as document_count, coalesce(sum(d.total_amount), 0) as total_amount')
            ->groupBy('dt.code')->get()->keyBy('code');

        $ledger = DB::table('gl_journals as g')->join('documents as d', 'd.id', '=', 'g.document_id')
            ->where('d.source_system', 'bplus')->whereNull('d.cancelled_at')
            ->whereNotIn('d.status', ['cancelled', 'void'])
            ->selectRaw('coalesce(sum(g.debit), 0) as debit_total, coalesce(sum(g.credit), 0) as credit_total')->first();
        $debit = (float) ($ledger->debit_total ?? 0);
        $credit = (float) ($ledger->credit_total ?? 0);
        $rows = [];
        foreach ($types as $type) {
            $record = $documents->get($type);
            $rows[] = [
                'label' => $type,
                'value' => number_format((int) ($record->document_count ?? 0)).' ใบ · '.number_format((float) ($record->total_amount ?? 0), 2).' บาท',
            ];
        }
        $rows[] = ['label' => 'GL Dr / Cr', 'value' => number_format($debit, 2).' / '.number_format($credit, 2).' บาท'];
        $balanced = abs($debit - $credit) <= 0.01;

        return [
            'status' => $balanced ? 'good' : 'danger',
            'summary' => $balanced ? 'ยอดเดบิตเท่ากับเครดิต' : 'ยอดเดบิตและเครดิตไม่สมดุล',
            'details' => [],
            'rows' => $rows,
        ];
    }

    private function directoryBytes(string $directory): int
    {
        if (! is_dir($directory) || is_link($directory)) {
            return 0;
        }

        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $bytes += (int) $file->getSize();
            }
        }

        return $bytes;
    }

    private function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = max(0, (float) $bytes);
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 2).' '.$units[$unit];
    }
}
