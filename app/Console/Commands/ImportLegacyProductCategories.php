<?php

namespace App\Console\Commands;

use App\Services\LegacyProductCategoryReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportLegacyProductCategories extends Command
{
    protected $signature = 'erp:import-legacy-product-categories
        {file : CSV export from BPlus IC021003, encoded CP874 or UTF-8}
        {--confirm-database= : Must match the connected database}
        {--dry-run : Validate and write the mapping report without changing data}
        {--report= : CSV output path for the mapping report}
        {--force : Skip the interactive confirmation}';

    protected $description = 'Import product categories from legacy IC021003 without changing SKU, barcode, stock, or price';

    public function handle(LegacyProductCategoryReportService $service): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");
            return self::FAILURE;
        }

        try {
            $plan = $service->plan((string) $this->argument('file'));
        } catch (Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }

        $rows = collect($plan['rows']);
        $this->line("ฐานข้อมูล: {$database} · อ่านรายงาน {$rows->count()} รายการ");
        $this->table(['ผล', 'จำนวน'], [
            ['ตรงอยู่แล้ว', $rows->where('status', 'UNCHANGED')->count()],
            ['เปลี่ยนประเภท', $rows->where('status', 'CATEGORY_CHANGE')->count()],
            ['ไม่พบ SKU เดิมใน ERP', $rows->where('status', 'LEGACY_NOT_FOUND')->count()],
        ]);
        if ($plan['problems'] !== []) {
            $this->table(['ระดับ', 'เรื่อง', 'รายละเอียด'], collect($plan['problems'])->map(fn (array $row) => array_values($row))->all());
        }
        if ($this->option('report')) {
            $this->writeReport((string) $this->option('report'), $plan['rows']);
            $this->info('ไฟล์ mapping: '.$this->option('report'));
        }
        if (collect($plan['problems'])->contains('level', 'หยุด')) {
            $this->error('ถูกหยุดโดย preflight');
            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกเขียน');
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('ปรับเฉพาะประเภทสินค้าตามไฟล์นี้?', false)) {
            return self::SUCCESS;
        }

        $changed = $service->apply($plan['rows']);
        $this->info("ปรับประเภทสินค้า {$changed} รายการแล้ว; SKU และบาร์โค้ดยังไม่เปลี่ยน");
        $this->warn('ขั้นต่อไป: ตรวจ mapping แล้วจึงรัน erp:recode-product-skus แยกต่างหาก');
        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeReport(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('เขียนไฟล์ mapping ไม่ได้');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['status', 'legacy_sku', 'sku_current', 'category_current', 'category_target', 'product_name_legacy', 'product_id', 'source_line']);
        foreach ($rows as $row) {
            fputcsv($handle, [$row['status'], $row['legacy_sku'], $row['sku_current'], $row['category_current'], $row['category_code'], $row['product_name'], $row['product_id'], $row['line']]);
        }
        fclose($handle);
    }
}
