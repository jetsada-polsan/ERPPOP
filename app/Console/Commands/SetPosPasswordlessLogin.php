<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;

class SetPosPasswordlessLogin extends Command
{
    protected $signature = 'pos:passwordless {state : enable or disable}';

    protected $description = 'Enable or disable device-bound POS cashier selection without a PIN';

    public function handle(): int
    {
        $enabled = match ((string) $this->argument('state')) {
            'enable' => true,
            'disable' => false,
            default => null,
        };

        if ($enabled === null) {
            $this->error('ใช้ enable หรือ disable เท่านั้น');
            return self::FAILURE;
        }

        AppSetting::set('pos_passwordless_login', $enabled ? '1' : '0');
        $this->info($enabled
            ? 'เปิด POS แบบเลือกชื่อพนักงานโดยไม่ต้องใช้ PIN แล้ว (จำกัดตาม Device Token และสาขา)'
            : 'ปิด POS แบบไม่ใช้ PIN แล้ว');

        return self::SUCCESS;
    }
}
