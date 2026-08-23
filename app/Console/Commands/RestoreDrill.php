<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RestoreDrill extends Command
{
    protected $signature = 'erp:restore-drill {--backup= : Backup file path} {--execute : Restore into ERP_RESTORE_DATABASE}';

    protected $description = 'Verify backup checksum and optionally restore it into an isolated drill database';

    public function handle(): int
    {
        $file = $this->option('backup') ?: collect(glob(storage_path('app/backups/erp-db-*')) ?: [])
            ->reject(fn ($path) => str_ends_with($path, '.sha256'))->sortByDesc(fn ($path) => filemtime($path))->first();
        if (! $file || ! is_file($file) || filesize($file) === 0) {
            $this->error('ไม่พบไฟล์ backup สำหรับทดสอบ');

            return self::FAILURE;
        }
        if (! is_readable($file)) {
            $this->error('ไม่มีสิทธิ์อ่านไฟล์ backup กรุณาให้ผู้ดูแลตรวจสิทธิ์ไฟล์');

            return self::FAILURE;
        }
        $checksumFile = $file.'.sha256';
        if (is_file($checksumFile)) {
            if (! is_readable($checksumFile)) {
                $this->error('ไม่มีสิทธิ์อ่านไฟล์ checksum กรุณาให้ผู้ดูแลตรวจสิทธิ์ไฟล์');

                return self::FAILURE;
            }
            $expected = strtok(trim(file_get_contents($checksumFile)), " \t");
            if (! hash_equals($expected, hash_file('sha256', $file))) {
                $this->error('Checksum ไม่ตรง ไฟล์ backup เสียหาย');

                return self::FAILURE;
            }
        }
        $gz = gzopen($file, 'rb');
        $sample = $gz ? gzread($gz, 4096) : false;
        if ($gz) {
            gzclose($gz);
        }
        if ($sample === false || strlen($sample) < 100) {
            $this->error('ไม่สามารถคลายไฟล์ backup ได้');

            return self::FAILURE;
        }
        $this->info('ตรวจ checksum และโครงสร้างไฟล์ backup ผ่าน');
        if (! $this->option('execute')) {
            return self::SUCCESS;
        }
        $database = env('ERP_RESTORE_DATABASE');
        if (! $database || $database === config('database.connections.pgsql.database')) {
            $this->error('ต้องกำหนด ERP_RESTORE_DATABASE เป็นฐานทดสอบแยกจาก production');

            return self::FAILURE;
        }
        $temp = tempnam(sys_get_temp_dir(), 'erp-restore-');
        $input = gzopen($file, 'rb');
        $output = fopen($temp, 'wb');
        if (! $input || ! $output) {
            $this->error('ไม่สามารถเตรียมไฟล์ restore ชั่วคราวได้');

            return self::FAILURE;
        }
        while (! gzeof($input)) {
            fwrite($output, gzread($input, 1024 * 1024));
        }
        gzclose($input);
        fclose($output);
        $config = config('database.connections.pgsql');
        $env = ['PGPASSWORD' => (string) (env('ERP_BACKUP_DB_PASSWORD') ?: $config['password'])];
        $user = env('ERP_BACKUP_DB_USERNAME') ?: $config['username'];
        try {
            (new Process(['dropdb', '--if-exists', '-h', $config['host'], '-p', (string) $config['port'], '-U', $user, $database], null, $env, null, 120))->mustRun();
            (new Process(['createdb', '-h', $config['host'], '-p', (string) $config['port'], '-U', $user, $database], null, $env, null, 120))->mustRun();
            (new Process(['psql', '-h', $config['host'], '-p', (string) $config['port'], '-U', $user, '-d', $database, '-f', $temp], null, $env, null, 600))->mustRun();
            $this->grantToApplicationUser($config, $env, $user, $database);
            $this->info("Restore drill ผ่านบนฐาน {$database}");

            return self::SUCCESS;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * คืนสิทธิ์ให้ผู้ใช้ที่แอปใช้ต่อฐาน
     *
     * erp:backup ดัมป์ด้วย --no-owner --no-privileges ตารางในฐานที่กู้มาจึงตกเป็นของ
     * ผู้ใช้ที่รัน restore ไม่ใช่ของ app user ผลคือกู้เสร็จแล้วแอปเปิดไม่ได้
     * ฟ้อง permission denied ทั้งที่ข้อมูลครบ — ต้อง GRANT ต่อท้ายเสมอ
     * ไม่ใช่ปล่อยให้คนมาไล่แก้เองตอนระบบล่ม
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $env
     */
    private function grantToApplicationUser(array $config, array $env, string $restoreUser, string $database): void
    {
        $appUser = (string) $config['username'];
        if ($appUser === '' || $appUser === $restoreUser) {
            return;   // กู้ด้วยผู้ใช้เดียวกับที่แอปใช้อยู่แล้ว ไม่ต้องคืนสิทธิ์
        }

        $quoted = '"'.str_replace('"', '""', $appUser).'"';
        $statements = [
            "GRANT USAGE ON SCHEMA public TO {$quoted}",
            "GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO {$quoted}",
            "GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO {$quoted}",
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO {$quoted}",
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO {$quoted}",
        ];

        foreach ($statements as $statement) {
            (new Process([
                'psql', '-h', $config['host'], '-p', (string) $config['port'],
                '-U', $restoreUser, '-d', $database, '-c', $statement,
            ], null, $env, null, 120))->mustRun();
        }

        $this->line("คืนสิทธิ์ในฐาน {$database} ให้ {$appUser} แล้ว");
    }
}
