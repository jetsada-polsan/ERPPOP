<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ปิดใช้งานสินค้าตามรายการรหัส (เช่น สินค้าที่ BPlus ปิดไว้ SKU_ENABLE = N)
 *
 * ปิดอย่างเดียว ไม่ย้ายประเภท ไม่ลบ — เจ้าของเปิดกลับทีละตัวเมื่อจะขาย
 * ไฟล์: บรรทัดแรกเป็นหัว sku_code บรรทัดถัดไปรหัสละบรรทัด
 */
class DeactivateProductsFromList extends Command
{
    use RequiresFreshBackup;

    protected $signature = 'erp:deactivate-products
        {file : ไฟล์รายการรหัสสินค้า}
        {--dry-run : สรุปโดยไม่บันทึก}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Switch off the products listed in a file of SKU codes';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์ {$path}");

            return self::FAILURE;
        }

        $codes = array_values(array_unique(array_filter(array_map('trim', array_slice(file($path), 1)))));
        $found = Product::whereIn('sku_code', $codes)->get(['id', 'sku_code', 'is_active']);
        $toSwitch = $found->where('is_active', true);

        $this->table(['รายการ', 'จำนวน'], [
            ['รหัสในไฟล์', number_format(count($codes))],
            ['พบในระบบ', number_format($found->count())],
            ['ไม่มีในระบบ (ข้าม)', number_format(count($codes) - $found->count())],
            ['ปิดอยู่แล้ว', number_format($found->count() - $toSwitch->count())],
            ['จะปิดใช้งาน', number_format($toSwitch->count())],
            ['ใช้งานต่อหลังปิด', number_format(Product::where('is_active', true)->count() - $toSwitch->count())],
        ]);

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }
        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("ยืนยันปิดใช้งานสินค้า {$toSwitch->count()} รายการ?", false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(fn () => $toSwitch->pluck('id')->chunk(1000)
                ->each(fn ($ids) => Product::whereIn('id', $ids->all())->update(['is_active' => false])));
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("ปิดใช้งานสินค้า {$toSwitch->count()} รายการแล้ว");

        return self::SUCCESS;
    }
}
