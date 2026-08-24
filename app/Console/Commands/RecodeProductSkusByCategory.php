<?php

namespace App\Console\Commands;

use App\Services\ProductSkuRecodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecodeProductSkusByCategory extends Command
{
    protected $signature = 'erp:recode-product-skus
        {--confirm-database= : ต้องเป็นชื่อฐานข้อมูลที่ต่ออยู่}
        {--dry-run : แสดง mapping และผลตรวจโดยไม่เขียนข้อมูล}
        {--report= : ที่เก็บ CSV mapping}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'รัน SKU ใหม่ตามประเภทสินค้า โดยเก็บรหัสเดิมและไม่แตะบาร์โค้ด';

    public function handle(ProductSkuRecodeService $service): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");
            return self::FAILURE;
        }

        $plan = $service->plan();
        $this->line("ฐานข้อมูล: {$database} · สินค้า ".count($plan).' รายการ');
        $this->table(['SKU ปัจจุบัน', 'SKU เดิม', 'ประเภท', 'SKU ใหม่', 'ชื่อสินค้า'], collect($plan)
            ->take(12)->map(fn (array $row) => [$row['old'], $row['legacy'], $row['category'], $row['new'] ?? '(จัดประเภทก่อน)', mb_substr($row['name'], 0, 34)])->all());

        $problems = $service->preflight($plan);
        if ($problems !== []) {
            $this->table(['ระดับ', 'เรื่อง', 'รายละเอียด'], collect($problems)->take(40)->map(array_values(...))->all());
        }
        if ($this->option('report')) {
            $this->writeReport((string) $this->option('report'), $plan);
            $this->info('ไฟล์ mapping: '.$this->option('report'));
        }
        if (collect($problems)->contains('level', 'หยุด')) {
            $this->error('dry-run/เปลี่ยนรหัสถูกหยุดโดย preflight');
            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกเขียน');
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('เปลี่ยน SKU ตาม mapping นี้ครั้งเดียว?', false)) {
            return self::SUCCESS;
        }

        try {
            $result = $service->apply(auth()->id());
        } catch (Throwable $error) {
            $this->error('ไม่สำเร็จ: '.$error->getMessage());
            return self::FAILURE;
        }
        $this->info("เปลี่ยน SKU {$result['products']} รายการ: {$result['first_code']} ถึง {$result['last_code']}");
        $this->warn('ต้องสั่งเครื่อง POS ทุกเครื่อง sync catalog ใหม่ก่อนเปิดขาย');
        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $plan */
    private function writeReport(string $path, array $plan): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('เขียนไฟล์ mapping ไม่ได้');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['sku_current', 'legacy_sku', 'category_code', 'sku_new', 'product_name', 'barcode_count']);
        foreach ($plan as $row) {
            fputcsv($handle, [$row['old'], $row['legacy'], $row['category'], $row['new'], $row['name'], $row['barcodes']]);
        }
        fclose($handle);
    }
}
