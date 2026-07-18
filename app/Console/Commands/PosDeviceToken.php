<?php

namespace App\Console\Commands;

use App\Models\PosDevice;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * ออก/แสดง/เพิกถอน token อุปกรณ์ POS desktop (Tauri).
 *
 *   php artisan pos:device --issue --user=jane --name="วาริน เครื่อง 1" --terminal=POS-0002-01
 *   php artisan pos:device --list
 *   php artisan pos:device --revoke=5
 *
 * token plaintext จะโชว์ครั้งเดียวตอน --issue เท่านั้น (เก็บ sha256 ในฐานข้อมูล)
 */
class PosDeviceToken extends Command
{
    protected $signature = 'pos:device
        {--issue : ออก token ใหม่}
        {--list : แสดงอุปกรณ์ทั้งหมด}
        {--revoke= : เพิกถอนตาม device id}
        {--user= : username หรือ id ของ cashier user (คู่กับ --issue)}
        {--name= : ชื่ออุปกรณ์}
        {--terminal= : รหัสเครื่อง เช่น POS-0002-01}';

    protected $description = 'จัดการ token อุปกรณ์สำหรับ POS desktop (Tauri)';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listDevices();
        }
        if ($revoke = $this->option('revoke')) {
            return $this->revoke((int) $revoke);
        }
        if ($this->option('issue')) {
            return $this->issue();
        }

        $this->error('ระบุ --issue, --list หรือ --revoke=ID');

        return self::FAILURE;
    }

    private function issue(): int
    {
        $userRef = (string) $this->option('user');
        if ($userRef === '') {
            $this->error('ต้องระบุ --user=<username|id>');

            return self::FAILURE;
        }

        $user = User::where('username', $userRef)->first()
            ?? (is_numeric($userRef) ? User::find((int) $userRef) : null);

        if (! $user) {
            $this->error("ไม่พบผู้ใช้: {$userRef}");

            return self::FAILURE;
        }
        if (! $user->salesman_id) {
            $this->warn("เตือน: ผู้ใช้ {$user->username} ยังไม่ได้ผูก salesman_id — เปิดกะ/ขายจะไม่ผ่าน");
        }
        if (! $user->hasPermission('pos.sell')) {
            $this->warn("เตือน: ผู้ใช้ {$user->username} ยังไม่มีสิทธิ์ pos.sell — token จะถูกปฏิเสธ");
        }

        [$device, $token] = PosDevice::issue([
            'name' => (string) ($this->option('name') ?: ($user->name.' device')),
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
            'terminal_code' => $this->option('terminal') ?: null,
        ]);

        $this->info("สร้างอุปกรณ์ #{$device->id} ({$device->name}) เรียบร้อย");
        $this->line("  cashier user : {$user->username} (branch_id={$user->branch_id}, salesman_id={$user->salesman_id})");
        $this->line("  terminal     : ".($device->terminal_code ?: '-'));
        $this->newLine();
        $this->warn('TOKEN (คัดลอกไปตั้งค่าในเครื่อง POS — โชว์ครั้งเดียวเท่านั้น):');
        $this->line("  {$token}");

        return self::SUCCESS;
    }

    private function listDevices(): int
    {
        $devices = PosDevice::with('user:id,username')->orderBy('id')->get();
        if ($devices->isEmpty()) {
            $this->info('ยังไม่มีอุปกรณ์');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'ชื่อ', 'user', 'branch', 'terminal', 'last seen', 'สถานะ'],
            $devices->map(fn (PosDevice $d) => [
                $d->id,
                $d->name,
                $d->user?->username,
                $d->branch_id,
                $d->terminal_code ?: '-',
                $d->last_seen_at?->diffForHumans() ?: 'ยังไม่เคย',
                $d->isActive() ? 'ใช้งาน' : 'เพิกถอนแล้ว',
            ])->all()
        );

        return self::SUCCESS;
    }

    private function revoke(int $id): int
    {
        $device = PosDevice::find($id);
        if (! $device) {
            $this->error("ไม่พบอุปกรณ์ #{$id}");

            return self::FAILURE;
        }
        $device->update(['revoked_at' => now()]);
        $this->info("เพิกถอนอุปกรณ์ #{$id} ({$device->name}) แล้ว");

        return self::SUCCESS;
    }
}
