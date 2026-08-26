<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;

/**
 * สลับโหมดหน้าขายเว็บ /pos — ใช้ตอน cutover ไปแอปเดสก์ท็อป PopCentral POS
 *
 *   php artisan pos:web-mode sell       ขายได้ตามเดิม (ดีฟอลต์)
 *   php artisan pos:web-mode redirect   เหลือหน้าสถานะ + ลิงก์ดาวน์โหลดแอป
 *
 * แยกจากการ deploy: เปิด/ปิดได้ทันทีโดยไม่ต้องแก้โค้ดหรือปล่อยรุ่นใหม่
 */
class SetPosWebMode extends Command
{
    protected $signature = 'pos:web-mode {mode : sell หรือ redirect}';

    protected $description = 'ตั้งโหมดหน้าขายเว็บ /pos (sell = ขายได้, redirect = หน้าสถานะ)';

    public function handle(): int
    {
        $mode = strtolower((string) $this->argument('mode'));
        if (! in_array($mode, ['sell', 'redirect'], true)) {
            $this->error("mode ต้องเป็น sell หรือ redirect");

            return self::FAILURE;
        }
        AppSetting::set('pos_web_mode', $mode);
        $this->info($mode === 'redirect'
            ? 'หน้าขายเว็บ /pos จะแสดงหน้าสถานะ + ลิงก์ดาวน์โหลดแอปแล้ว'
            : 'หน้าขายเว็บ /pos กลับมาขายได้ตามปกติแล้ว');

        return self::SUCCESS;
    }
}
