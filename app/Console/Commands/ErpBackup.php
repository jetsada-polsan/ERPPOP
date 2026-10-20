<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class ErpBackup extends Command
{
    protected $signature = 'erp:backup {--keep-days=30 : Number of days to retain local backups} {--disk= : Optional offsite filesystem disk}';

    protected $description = 'Create a compressed database backup with SHA-256 checksum';

    public function handle(): int
    {
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory, 0750);
        $this->securePath($directory, 0750);
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
            $this->compressFile($raw, $compressed);
            File::delete($raw);
            File::put($compressed.'.sha256', hash_file('sha256', $compressed).'  '.basename($compressed).PHP_EOL);
            $this->securePath($compressed);
            $this->securePath($compressed.'.sha256');
            $disk = $this->option('disk') ?: env('ERP_BACKUP_OFFSITE_DISK');
            if ($disk) {
                $target = 'erp-backups/'.now()->format('Y/m').'/'.basename($compressed);
                $this->putStream($disk, $target, $compressed);
                $this->putStream($disk, $target.'.sha256', $compressed.'.sha256');
                $this->info("ส่งสำเนานอกเครื่องไป disk {$disk}: {$target}");
            }
            $this->prune($directory, max(1, (int) $this->option('keep-days')));
            $this->info('สำรองฐานข้อมูลสำเร็จ: '.$compressed);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            File::delete($raw);
            $this->error('สำรองฐานข้อมูลไม่สำเร็จ: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Compress a backup in bounded memory instead of loading the whole dump.
     */
    private function compressFile(string $source, string $target): void
    {
        $input = @fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException("ไม่สามารถอ่านไฟล์ backup {$source}");
        }

        $output = @gzopen($target, 'wb9');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException("ไม่สามารถสร้างไฟล์บีบอัด {$target}");
        }

        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException("อ่านไฟล์ backup ไม่สำเร็จ {$source}");
                }

                if ($chunk === '') {
                    continue;
                }

                if (gzwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException("เขียนไฟล์บีบอัดไม่สำเร็จ {$target}");
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }
    }

    private function putStream(string $disk, string $target, string $source): void
    {
        $stream = @fopen($source, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ไม่สามารถอ่านไฟล์สำหรับส่งไป disk {$disk}: {$source}");
        }

        try {
            if (! Storage::disk($disk)->put($target, $stream)) {
                throw new RuntimeException("ส่งไฟล์ไป disk {$disk} ไม่สำเร็จ: {$target}");
            }
        } finally {
            fclose($stream);
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

    private function securePath(string $path, int $mode = 0640): void
    {
        $group = (string) config('operations.backup_group');
        if ($group !== '') {
            @chgrp($path, $group);
        }
        @chmod($path, $mode);
    }
}
