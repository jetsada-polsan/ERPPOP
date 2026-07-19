<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class ErpBackup extends Command
{
    protected $signature = 'erp:backup {--keep-days=30 : Number of days to retain local backups}';

    protected $description = 'Create a compressed database backup with SHA-256 checksum';

    public function handle(): int
    {
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0700);
        $driver = config('database.default');
        $stamp = now()->format('Ymd-His');
        $raw = "{$directory}/erp-db-{$stamp}.sql";

        try {
            if ($driver === 'sqlite') {
                $source = config('database.connections.sqlite.database');
                if (! is_file($source)) {
                    throw new RuntimeException('ไม่พบไฟล์ฐานข้อมูล SQLite');
                }
                File::copy($source, $raw);
            } elseif ($driver === 'pgsql') {
                $this->dumpPostgres($raw);
            } elseif ($driver === 'mysql') {
                $this->dumpMysql($raw);
            } else {
                throw new RuntimeException("ยังไม่รองรับฐานข้อมูล {$driver}");
            }

            $compressed = $raw.'.gz';
            File::put($compressed, gzencode(File::get($raw), 9));
            File::delete($raw);
            File::put($compressed.'.sha256', hash_file('sha256', $compressed).'  '.basename($compressed).PHP_EOL);
            chmod($compressed, 0600);
            chmod($compressed.'.sha256', 0600);
            $this->prune($directory, max(1, (int) $this->option('keep-days')));
            $this->info('สำรองฐานข้อมูลสำเร็จ: '.$compressed);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            File::delete($raw);
            $this->error('สำรองฐานข้อมูลไม่สำเร็จ: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function dumpPostgres(string $target): void
    {
        $config = config('database.connections.pgsql');
        $process = new Process([
            'pg_dump', '--no-owner', '--no-privileges',
            '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'],
            '--dbname='.$config['database'], '--file='.$target,
        ], null, ['PGPASSWORD' => (string) $config['password']], null, 600);
        $process->mustRun();
    }

    private function dumpMysql(string $target): void
    {
        $config = config('database.connections.mysql');
        $process = new Process([
            'mysqldump', '--single-transaction', '--quick',
            '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'],
            '--result-file='.$target, $config['database'],
        ], null, ['MYSQL_PWD' => (string) $config['password']], null, 600);
        $process->mustRun();
    }

    private function prune(string $directory, int $days): void
    {
        $cutoff = now()->subDays($days)->timestamp;
        foreach (glob($directory.'/erp-db-*') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                File::delete($file);
            }
        }
    }
}
