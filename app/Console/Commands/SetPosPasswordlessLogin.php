<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;

class SetPosPasswordlessLogin extends Command
{
    protected $signature = 'pos:passwordless {state : enable or disable}';

    protected $description = 'Passwordless cashier login has been retired; POS always requires a four-digit PIN';

    public function handle(): int
    {
        $state = (string) $this->argument('state');
        if (! in_array($state, ['enable', 'disable'], true)) {
            $this->error('ใช้ enable หรือ disable เท่านั้น');
            return self::FAILURE;
        }

        if ($state === 'enable') {
            $this->error('POS ต้องยืนยัน PIN คนขาย 4 หลักแล้ว ไม่รองรับโหมดเลือกชื่อโดยไม่ใช้ PIN');
            return self::FAILURE;
        }

        AppSetting::set('pos_passwordless_login', '0');
        $this->info('ปิดค่าตั้ง passwordless แล้ว; POS ใช้ PIN 4 หลัก');

        return self::SUCCESS;
    }
}
