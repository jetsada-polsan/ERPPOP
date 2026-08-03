<?php

namespace App\Console\Commands;

use App\Models\Salesman;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

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

        $conflicts = Salesman::query()
            ->where('is_active', true)
            ->whereNotNull('pos_pin_hash')
            ->whereKeyNot($salesman->id)
            ->when($salesman->branch_id, fn ($query) => $query->where(fn ($w) => $w
                ->whereNull('branch_id')
                ->orWhere('branch_id', $salesman->branch_id)))
            ->get()
            ->contains(fn (Salesman $candidate) => Hash::check($pin, $candidate->pos_pin_hash));

        if ($conflicts) {
            $this->error('PIN นี้ถูกใช้ในสาขาแล้ว กรุณาเลือก PIN ใหม่');
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
