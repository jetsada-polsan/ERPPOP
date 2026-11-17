<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * token สำหรับเครื่องในร้านที่ส่งเอกสาร BPlus ขึ้น ERP เอง (POST /api/bplus/sync)
 * token ใช้ได้แค่ส่งเอกสารของสายที่ระบุ ทำอย่างอื่นในระบบไม่ได้
 *
   *   php artisan bplus:sync-token --issue --route=B18 --name="BK Server"
 *   php artisan bplus:sync-token --issue --all-routes --name="bplusAPI"
 *   php artisan bplus:sync-token --list
 *   php artisan bplus:sync-token --revoke=1
 *
 * token แสดงครั้งเดียวตอนออก ฐานข้อมูลเก็บแค่ sha256
 */
class BplusSyncToken extends Command
{
    protected $signature = 'bplus:sync-token
        {--issue : ออก token ใหม่}
        {--list : แสดง token ทั้งหมด}
        {--revoke= : เพิกถอนตาม id}
        {--route=B18 : สายขายที่ token นี้ส่งได้}
        {--all-routes : อนุญาตทุกสาย รวมสายที่ยังไม่มีใน ERP}
        {--name= : ชื่อเครื่องที่ใช้ token}';

    protected $description = 'จัดการ token ของเครื่องที่ส่งเอกสาร BPlus ขึ้น ERP อัตโนมัติ';

    public function handle(): int
    {
        if ($this->option('list')) {
            $rows = DB::table('bplus_sync_tokens')->orderBy('id')->get()->map(fn ($t) => [
                $t->id, $t->name, $t->route_code ?? 'ทุกสาย', $t->last_used_at ?? '-', $t->last_ip ?? '-', $t->revoked_at ? 'เพิกถอนแล้ว' : 'ใช้งาน',
            ]);
            $this->table(['id', 'ชื่อ', 'สาย', 'ใช้ล่าสุด', 'IP ล่าสุด', 'สถานะ'], $rows->all());

            return self::SUCCESS;
        }
        if ($id = $this->option('revoke')) {
            $updated = DB::table('bplus_sync_tokens')->where('id', (int) $id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
            $updated ? $this->info("เพิกถอน token #{$id} แล้ว") : $this->error("ไม่พบ token #{$id} ที่ยังใช้งานอยู่");

            return $updated ? self::SUCCESS : self::FAILURE;
        }
        if (! $this->option('issue')) {
            $this->error('ระบุ --issue, --list หรือ --revoke=ID');

            return self::FAILURE;
        }

        $allRoutes = (bool) $this->option('all-routes');
        $route = trim((string) $this->option('route'));
        if (! $allRoutes && ! DB::table('sales_areas')->where('code', $route)->exists()) {
            $this->error("ไม่พบสายขาย {$route}");

            return self::FAILURE;
        }
        $token = 'bps_'.Str::random(48);
        $scope = $allRoutes ? null : $route;
        $id = DB::table('bplus_sync_tokens')->insertGetId([
            'name' => (string) ($this->option('name') ?: ($allRoutes ? 'เครื่องซิงค์ทุกสาย' : 'เครื่องซิงค์ '.$route)),
            'route_code' => $scope,
            'token_hash' => hash('sha256', $token),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info($allRoutes ? "ออก token #{$id} สำหรับทุกสายแล้ว" : "ออก token #{$id} สำหรับสาย {$route} แล้ว");
        $this->line('token (แสดงครั้งเดียว ใส่ในไฟล์ตั้งค่าของเครื่องในร้าน ห้ามส่งทางแชท):');
        $this->line($token);

        return self::SUCCESS;
    }
}
