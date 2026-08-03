<?php

namespace App\Console\Commands;

use App\Models\Salesman;
use Illuminate\Console\Command;

class SetPosSharedPin extends Command
{
    protected $signature = 'pos:shared-pin {pin=1234 : PIN กลาง 4-20 หลัก} {--branch= : จำกัดเฉพาะรหัสสาขา}';

    protected $description = 'Set a temporary shared POS PIN for active cashiers; POS will require selecting the cashier name afterwards';

    public function handle(): int
    {
        $pin = (string) $this->argument('pin');
        if (! preg_match('/^\d{4,20}$/', $pin)) {
            $this->error('PIN ต้องเป็นตัวเลข 4-20 หลัก');
            return self::FAILURE;
        }

        $cashiers = Salesman::query()
            ->where('is_active', true)
            ->when($this->option('branch'), fn ($query, $branch) => $query->whereHas('branch', fn ($q) => $q->where('code', $branch)))
            ->get();

        if ($cashiers->isEmpty()) {
            $this->error('ไม่พบแคชเชียร์ที่ใช้งาน');
            return self::FAILURE;
        }

        foreach ($cashiers as $cashier) {
            // PIN กลางจงใจใช้ร่วมกัน: หน้าจอ POS จะบังคับเลือกชื่อก่อนเริ่มกะเพื่อคง audit รายคน
            $cashier->setPin($pin, false);
        }

        $this->info("ตั้ง PIN กลางให้ {$cashiers->count()} คนแล้ว");
        $this->line('ผู้ใช้ต้องเลือกชื่อตัวเองหลังกรอก PIN ทุกครั้ง');

        return self::SUCCESS;
    }
}
