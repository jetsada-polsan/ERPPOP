<?php

namespace App\Console\Commands;

use App\Services\LegacyScalePluService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClassifyLegacyScalePlu extends Command
{
    protected $signature = 'erp:classify-legacy-scale-plu
        {--confirm-database= : Must match the connected database}
        {--dry-run : Validate and write the report without changing data}
        {--report= : CSV output path}
        {--force : Skip the interactive confirmation}';

    protected $description = 'Classify exact legacy 801xxx six digit scale PLUs without changing barcode values or SKU';

    public function handle(LegacyScalePluService $service): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");
            return self::FAILURE;
        }

        $plan = $service->plan();
        $this->line("ฐานข้อมูล: {$database}");
        $this->table(['รายการ', 'จำนวน'], [
            ['PLU 801xxx 6 หลัก ที่จะจัดประเภท', count($plan['candidates'])],
            ['ข้อยกเว้นที่คง CUSTOM ไว้', count($plan['exceptions'])],
        ]);
        $this->table(['PLU', 'SKU สินค้า', 'ประเภทสินค้า', 'ชื่อสินค้า'], collect($plan['candidates'])
            ->take(15)->map(fn (array $row) => [$row['plu'], $row['sku'], $row['category'], mb_substr((string) $row['name'], 0, 42)])->all());
        if ($plan['exceptions'] !== []) {
            $this->table(['บาร์โค้ดข้อยกเว้น', 'SKU', 'ชื่อสินค้า', 'เหตุผล'], collect($plan['exceptions'])
                ->take(30)->map(fn (array $row) => [$row['plu'], $row['sku'], mb_substr((string) $row['name'], 0, 32), $row['reason']])->all());
        }
        if ($this->option('report')) {
            $this->writeReport((string) $this->option('report'), $plan);
            $this->info('รายงาน: '.$this->option('report'));
        }
        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกเขียน');
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('จัดประเภทเฉพาะ PLU 801xxx 6 หลักนี้หรือไม่?', false)) {
            return self::SUCCESS;
        }

        try {
            $changed = $service->apply($plan['candidates']);
        } catch (Throwable $error) {
            $this->error('ไม่สำเร็จ: '.$error->getMessage());
            return self::FAILURE;
        }

        $this->info("จัดประเภท SCALE_PLU แล้ว {$changed} รายการ");
        $this->warn('ไม่ได้เปลี่ยนค่า PLU, SKU, ชื่อสินค้า, ราคา, สต๊อก หรือข้อยกเว้น');
        return self::SUCCESS;
    }

    /** @param array{candidates: array<int, array<string, mixed>>, exceptions: array<int, array<string, mixed>>} $plan */
    private function writeReport(string $path, array $plan): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('เขียนรายงานไม่ได้');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['status', 'barcode_id', 'plu_or_barcode', 'sku', 'category', 'product_name', 'barcode_type_before', 'reason']);
        foreach ($plan['candidates'] as $row) {
            fputcsv($handle, ['SCALE_PLU', $row['barcode_id'], $row['plu'], $row['sku'], $row['category'], $row['name'], $row['barcode_type_before'], 'exact 801xxx six digit PLU']);
        }
        foreach ($plan['exceptions'] as $row) {
            fputcsv($handle, ['EXCEPTION', $row['barcode_id'], $row['plu'], $row['sku'], $row['category'], $row['name'], $row['barcode_type_before'], $row['reason']]);
        }
        fclose($handle);
    }
}
