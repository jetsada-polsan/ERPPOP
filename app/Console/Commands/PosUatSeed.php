<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Run the existing UAT seed script through Artisan with production guards. */
class PosUatSeed extends Command
{
    protected $signature = 'pos:uat-seed';

    protected $description = 'Seed an isolated UAT database for the Python POS end-to-end test';

    public function handle(): int
    {
        if (! in_array(app()->environment(), ['staging', 'local', 'testing'], true)) {
            $this->error('UAT seed อนุญาตเฉพาะ staging/local/testing เท่านั้น');
            return self::FAILURE;
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if ($database === 'jeterp' || ! str_ends_with($database, '_uat')) {
            $this->error('หยุด: UAT ต้องใช้ฐานข้อมูลที่ลงท้ายด้วย _uat และห้ามใช้ jeterp');
            return self::FAILURE;
        }

        require base_path('tools/staging/seed_uat_data.php');
        return self::SUCCESS;
    }
}
