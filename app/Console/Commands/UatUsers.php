<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ยูสเซอร์ทดสอบ UAT ครบทุกบทบาทที่ฟลอว์อนุมัติต้องใช้ (ผู้สร้างอนุมัติเอกสารตัวเองไม่ได้)
 *
 * รหัสผ่านสุ่มใหม่ทุกครั้งที่รัน แสดงเฉพาะในเทอร์มินัลของผู้รัน ไม่เก็บลงไฟล์
 * --remove ปิดใช้งานและสุ่มรหัสผ่านทิ้ง ไม่ลบแถว เพราะเอกสารทดสอบอ้าง user อยู่
 */
class UatUsers extends Command
{
    /** username => [ชื่อ, บทบาท] */
    public const USERS = [
        'uat-staff' => ['UAT พนักงาน (ผู้สร้างเอกสาร)', ['SALES', 'WAREHOUSE', 'PURCHASING', 'ACC']],
        'uat-branch-mgr' => ['UAT ผู้จัดการสาขา', ['BRANCH_MGR']],
        'uat-acc-mgr' => ['UAT ผู้จัดการบัญชี', ['ACC_MGR']],
    ];

    protected $signature = 'erp:uat-users
        {branch=B001 : รหัสสาขาที่ทดสอบ}
        {--remove : ปิดใช้งานยูสเซอร์ UAT ทั้งหมด}
        {--dry-run : สรุปโดยไม่แก้ข้อมูล}';

    protected $description = 'Create (or disable) UAT test users for every approval role';

    public function handle(): int
    {
        if ($this->option('remove')) {
            $users = User::whereIn('username', array_keys(self::USERS))->get();
            $this->table(['ปิดใช้งาน', 'ชื่อ'], $users->map(fn ($u) => [$u->username, $u->name])->all());
            if ($this->option('dry-run')) {
                $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

                return self::SUCCESS;
            }
            $users->each(fn (User $u) => $u->update(['is_active' => false, 'password' => Str::random(40)]));
            $this->info('ปิดใช้งาน '.$users->count().' ยูสเซอร์');

            return self::SUCCESS;
        }

        $branch = Branch::where('code', $this->argument('branch'))->first();
        if (! $branch) {
            $this->error('ไม่พบสาขา '.$this->argument('branch'));

            return self::FAILURE;
        }
        $codes = collect(self::USERS)->flatMap(fn ($u) => $u[1])->unique();
        $roles = Role::whereIn('code', $codes)->pluck('id', 'code');
        if ($missing = $codes->diff($roles->keys())->implode(', ')) {
            $this->error('ไม่พบบทบาท '.$missing);

            return self::FAILURE;
        }

        $this->line("สาขา {$branch->code} {$branch->name_th}");
        if ($this->option('dry-run')) {
            $this->table(['ยูสเซอร์', 'ชื่อ', 'บทบาท', 'มีอยู่แล้ว'], collect(self::USERS)->map(fn ($u, $username) => [
                $username, $u[0], implode(',', $u[1]), User::where('username', $username)->exists() ? 'ใช่' : '-',
            ])->values()->all());
            $this->info('dry-run: ไม่มีข้อมูลใดถูกแก้ไข');

            return self::SUCCESS;
        }

        $rows = DB::transaction(fn () => collect(self::USERS)->map(function ($u, $username) use ($branch, $roles) {
            $password = Str::password(14, symbols: false);
            $user = User::firstOrNew(['username' => $username]);
            $user->fill(['name' => $u[0], 'branch_id' => $branch->id, 'is_active' => true, 'must_change_password' => false]);
            $user->password = $password;
            $user->save();
            $user->roles()->sync($roles->only($u[1])->values()->all());

            return [$username, $password, implode(',', $u[1])];
        })->values()->all());

        $this->table(['ยูสเซอร์', 'รหัสผ่าน', 'บทบาท'], $rows);
        $this->warn('รหัสผ่านแสดงครั้งเดียว อย่าส่งในแชท ทดสอบเสร็จรัน: php artisan erp:uat-users --remove');

        return self::SUCCESS;
    }
}
