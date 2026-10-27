<?php

namespace App\Console\Concerns;

/**
 * ด่านกันคำสั่งลบข้อมูล: ต้องมี backup อายุไม่เกิน 2 ชั่วโมงและ checksum ผ่านก่อนลบจริง
 * ใช้ร่วมกันระหว่าง erp:reset-transactions และ erp:purge-products
 */
trait RequiresFreshBackup
{
    /** ไม่มี backup สดที่ตรวจ checksum แล้ว = ไม่ให้ลบ */
    protected function hasFreshBackup(): bool
    {
        $latest = collect(glob(storage_path('app/backups/erp-db-*.sql.gz')) ?: [])
            ->sortByDesc(fn (string $file) => filemtime($file))
            ->first();

        if (! $latest) {
            $this->error('ไม่พบไฟล์สำรอง — รัน php artisan erp:backup ก่อน');

            return false;
        }

        $ageHours = (time() - filemtime($latest)) / 3600;
        if ($ageHours > 2) {
            $this->error(sprintf('ไฟล์สำรองล่าสุดเก่า %.1f ชั่วโมง — รัน php artisan erp:backup ใหม่ก่อน', $ageHours));

            return false;
        }

        $checksumFile = $latest.'.sha256';
        if (! is_file($checksumFile)) {
            $this->error('ไม่พบไฟล์ checksum ของ backup');

            return false;
        }
        if (! hash_equals(trim(explode(' ', (string) file_get_contents($checksumFile))[0]), hash_file('sha256', $latest))) {
            $this->error('checksum ของ backup ไม่ตรง — ไฟล์อาจเสียหาย ห้ามลบข้อมูล');

            return false;
        }

        $this->info(sprintf('backup ผ่าน: %s (%.1f ชม.ที่แล้ว, checksum ตรง)', basename($latest), $ageHours));

        return true;
    }
}
