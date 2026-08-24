<?php

namespace App\Console\Commands;

use App\Services\ManufacturerEan13Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ClassifyManufacturerEan13 extends Command
{
    protected $signature = 'erp:classify-manufacturer-ean13
        {--confirm-database= : Must match the connected database}
        {--dry-run : Validate and write the report without changing data}
        {--report= : CSV output path}
        {--force : Skip the interactive confirmation}';

    protected $description = 'Classify verified legacy 885 EAN-13 manufacturer barcodes without changing their values';

    public function handle(ManufacturerEan13Service $service): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");
            return self::FAILURE;
        }

        $plan = $service->plan();
        $this->line("ฐานข้อมูล: {$database}");
        $this->table(['รายการ', 'จำนวน'], [
            ['885 EAN-13 มาตรฐานที่ผ่าน check digit', count($plan['standard'])],
            ['ข้อยกเว้นที่คง INTERNAL_13 ไว้', count($plan['exceptions'])],
        ]);
        if ($this->option('report')) {
            $this->writeReport((string) $this->option('report'), $plan);
            $this->info('รายงาน: '.$this->option('report'));
        }
        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกเขียน');
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('จัดประเภท EAN-13 ผู้ผลิตเฉพาะรายการที่ผ่าน check digit หรือไม่?', false)) {
            return self::SUCCESS;
        }

        try {
            $changed = $service->apply($plan['standard']);
        } catch (Throwable $error) {
            $this->error('ไม่สำเร็จ: '.$error->getMessage());
            return self::FAILURE;
        }
        $this->info("จัดประเภท EAN13_STANDARD แล้ว {$changed} รายการ");
        $this->warn('ไม่ได้แก้เลขบาร์โค้ด, SKU, ราคา หรือสินค้าใด ๆ');
        return self::SUCCESS;
    }

    /** @param array{standard: array<int, array<string, mixed>>, exceptions: array<int, array<string, mixed>>} $plan */
    private function writeReport(string $path, array $plan): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('เขียนรายงานไม่ได้');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['status', 'barcode_id', 'barcode', 'sku', 'product_name', 'barcode_type_before', 'reason']);
        foreach ($plan['standard'] as $row) {
            fputcsv($handle, ['EAN13_STANDARD', $row['barcode_id'], $row['barcode'], $row['sku'], $row['name'], $row['barcode_type_before'], '885 EAN-13 verified']);
        }
        foreach ($plan['exceptions'] as $row) {
            fputcsv($handle, ['EXCEPTION', $row['barcode_id'], $row['barcode'], $row['sku'], $row['name'], $row['barcode_type_before'], $row['reason']]);
        }
        fclose($handle);
    }
}
