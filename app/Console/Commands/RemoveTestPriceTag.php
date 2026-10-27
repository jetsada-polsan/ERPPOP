<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PriceTagTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveTestPriceTag extends Command
{
    protected $signature = 'erp:remove-test-price-tag {--dry-run : แสดงป้ายที่จะลบโดยไม่แก้ข้อมูล}';

    protected $description = 'Remove only the exact TEST01 / TEST-Claude price-tag template identified in UAT';

    public function handle(): int
    {
        return DB::transaction(function (): int {
            $tag = PriceTagTemplate::where('code', 'TEST01')->lockForUpdate()->first();
            if (! $tag) {
                $this->info('ไม่พบ TEST01 ไม่มีข้อมูลที่ต้องลบ');

                return self::SUCCESS;
            }
            if ($tag->name !== 'TEST-Claude') {
                $this->error('TEST01 ไม่ได้ชื่อ TEST-Claude ปฏิเสธการลบเพื่อป้องกันลบป้ายจริง');

                return self::FAILURE;
            }
            $this->table(['ID', 'รหัส', 'ชื่อ'], [[$tag->id, $tag->code, $tag->name]]);
            if ($this->option('dry-run')) {
                $this->info('DRY RUN: ไม่ลบข้อมูล');

                return self::SUCCESS;
            }
            AuditLog::create([
                'action' => 'uat_test_price_tag_delete', 'table_name' => 'price_tag_templates',
                'record_id' => $tag->id, 'old_values' => $tag->getAttributes(),
                'new_values' => ['reason' => 'Owner identified TEST01 / TEST-Claude as a UAT template'],
            ]);
            $tag->delete();
            $this->info('ลบเฉพาะ TEST01 / TEST-Claude แล้ว');

            return self::SUCCESS;
        });
    }
}
