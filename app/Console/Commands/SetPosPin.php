<?php

namespace App\Console\Commands;

use App\Models\Salesman;
use Illuminate\Console\Command;

class SetPosPin extends Command
{
    protected $signature = 'pos:pin {salesman : salesman code or id} {pin : 4-20 digit PIN} {--permanent : ตั้งเป็น PIN ถาวร ไม่ต้องให้เจ้าตัวเปลี่ยนตอนล็อกอิน}';

    protected $description = 'Set a starting POS PIN for a cashier/salesman';

    public function handle(): int
    {
        $pin = (string) $this->argument('pin');
        if (! preg_match('/^\d{4,20}$/', $pin)) {
            $this->error('PIN ต้องเป็นตัวเลข 4-20 หลัก');
            return self::FAILURE;
        }

        $key = (string) $this->argument('salesman');
        $salesman = Salesman::query()
            ->where('code', $key)
            ->orWhere('id', ctype_digit($key) ? (int) $key : 0)
            ->first();

        if (! $salesman) {
            $this->error('ไม่พบพนักงานขาย/แคชเชียร์');
            return self::FAILURE;
        }

        $mustChange = ! $this->option('permanent');
        $salesman->setPin($pin, $mustChange);

        $this->info("ตั้ง PIN POS ให้ {$salesman->code} - {$salesman->name} แล้ว");
        if ($mustChange) {
            $this->line('  เจ้าตัวต้องเปลี่ยน PIN เองตอนล็อกอินครั้งแรก');
        }

        return self::SUCCESS;
    }
}
