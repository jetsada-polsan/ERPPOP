<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ErpBackupTest extends TestCase
{
    private string $backupDirectory;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDirectory = storage_path('app/backups');
        $this->source = storage_path('framework/testing/erp-backup-source.sql');

        File::ensureDirectoryExists($this->backupDirectory);
        File::ensureDirectoryExists(dirname($this->source));
        File::cleanDirectory($this->backupDirectory);
        File::put($this->source, str_repeat("INSERT INTO backup_probe VALUES ('ok');\n", 50000));

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->source,
        ]);
    }

    protected function tearDown(): void
    {
        File::cleanDirectory($this->backupDirectory);
        File::delete($this->source);

        parent::tearDown();
    }

    public function test_it_compresses_the_dump_without_leaving_the_raw_file(): void
    {
        $this->artisan('erp:backup', ['--keep-days' => 30])
            ->expectsOutputToContain('สำรองฐานข้อมูลสำเร็จ')
            ->assertSuccessful();

        $backups = glob($this->backupDirectory.'/erp-db-*.sql.gz') ?: [];

        $this->assertCount(1, $backups);
        $this->assertSame(
            str_repeat("INSERT INTO backup_probe VALUES ('ok');\n", 50000),
            gzdecode(File::get($backups[0]))
        );
        $this->assertFileDoesNotExist(str_replace('.sql.gz', '.sql', $backups[0]));
        $this->assertSame(hash_file('sha256', $backups[0]), strtok(trim(File::get($backups[0].'.sha256')), " \t"));
    }
}
