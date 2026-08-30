<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;

/**
 * สลับโหมดหน้าขายเว็บ /pos — ใช้ตอน cutover ไปแอปเดสก์ท็อป PopCentral POS
 *
 *   php artisan pos:web-mode sell       ขายได้ตามเดิม (ดีฟอลต์)
 *   php artisan pos:web-mode preview    แสดง layout ที่ Build แบบไม่บันทึกยอด
 *   php artisan pos:web-mode redirect   เหลือหน้าสถานะ + ลิงก์ดาวน์โหลดแอป
 *
 * แยกจากการ deploy: เปิด/ปิดได้ทันทีโดยไม่ต้องแก้โค้ดหรือปล่อยรุ่นใหม่
 */
class SetPosWebMode extends Command
{
    protected $signature = 'pos:web-mode {mode : sell, preview หรือ redirect}';

    protected $description = 'ตั้งโหมดหน้าขายเว็บ /pos (sell = ขายได้, preview = ดู layout, redirect = หน้าสถานะ)';

    public function handle(): int
    {
        $mode = strtolower((string) $this->argument('mode'));
        if (! in_array($mode, ['sell', 'preview', 'redirect'], true)) {
            $this->error('mode ต้องเป็น sell, preview หรือ redirect');

            return self::FAILURE;
        }
        AppSetting::set('pos_web_mode', $mode);
        $message = match ($mode) {
            'redirect' => 'หน้าขายเว็บ /pos จะแสดงหน้าสถานะ + ลิงก์ดาวน์โหลดแอปแล้ว',
            'preview' => 'หน้าขายเว็บ /pos จะแสดง layout ที่ Build ในโหมดตัวอย่างแล้ว',
            default => 'หน้าขายเว็บ /pos กลับมาขายได้ตามปกติแล้ว',
        };
        $this->info($message);

        return self::SUCCESS;
    }
}
