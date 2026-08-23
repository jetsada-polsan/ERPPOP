<?php

namespace App\Console\Commands;

use App\Services\MasterDataCutoverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * เปลี่ยนรหัสสาขาและสินค้าเป็นชุดใหม่ พร้อมออกไฟล์ mapping ไว้เทียบกับระบบเก่า
 */
class MasterDataCutover extends Command
{
    protected $signature = 'erp:master-cutover
        {--confirm-database= : ชื่อฐานข้อมูล ต้องพิมพ์ให้ตรง}
        {--dry-run : แสดงแผนและผลตรวจ โดยไม่เขียนอะไรลงฐาน}
        {--report= : ที่เก็บไฟล์ mapping (.csv)}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'เปลี่ยนรหัสสาขาเป็น B001... และรหัสสินค้าเป็น P000001... เก็บรหัสเดิมไว้ mapping';

    public function handle(MasterDataCutoverService $service): int
    {
        $database = DB::connection()->getDatabaseName();
        if ($this->option('confirm-database') !== $database) {
            $this->error("ต้องระบุ --confirm-database={$database} ให้ตรงกับฐานที่ต่ออยู่");

            return self::FAILURE;
        }

        $branchPlan = $service->planBranches();
        $productPlan = $service->planProducts();

        $this->line("ฐานข้อมูล: {$database}");
        $this->line(sprintf('สาขา %d รายการ · สินค้า %d รายการ', count($branchPlan), count($productPlan)));
        $this->line('');

        $this->table(
            ['รหัสเดิม', 'รหัสใหม่', 'ชื่อสาขา'],
            collect($branchPlan)->map(fn ($row) => [$row['legacy'], $row['new'], mb_substr($row['name'], 0, 34)])->all(),
        );

        $this->line('ตัวอย่างสินค้า 5 รายการแรกและ 3 รายการท้าย:');
        $this->table(
            ['รหัสเดิม', 'รหัสใหม่', 'ชื่อสินค้า', 'บาร์โค้ด'],
            collect($productPlan)->take(5)->concat(collect($productPlan)->slice(-3))
                ->map(fn ($row) => [$row['legacy'], $row['new'], mb_substr($row['name'], 0, 30), $row['barcodes']])->all(),
        );

        $problems = $service->preflight();
        $blocking = collect($problems)->where('level', 'หยุด');
        if ($problems !== []) {
            $this->line('');
            $this->table(['ระดับ', 'เรื่อง', 'รายละเอียด'], collect($problems)->take(30)->map(array_values(...))->all());
        }

        if ($report = $this->option('report')) {
            $this->writeReport($report, $branchPlan, $productPlan);
            $this->info("ไฟล์ mapping: {$report}");
        }

        $barcodes = DB::table('product_barcodes')->whereNotNull('barcode')->where('barcode', '<>', '')->count();
        $this->line("บาร์โค้ดในระบบ {$barcodes} รายการ — cutover ไม่แตะเลย");

        if ($blocking->isNotEmpty()) {
            $this->error('ยังทำ cutover ไม่ได้ ติดอยู่ '.$blocking->count().' ข้อ');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกเขียน');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('เขียนรหัสใหม่ลงฐาน?', false)) {
            $this->info('ยกเลิก ไม่มีอะไรถูกเขียน');

            return self::SUCCESS;
        }

        try {
            $result = $service->apply();
        } catch (Throwable $exception) {
            $this->error('ไม่สำเร็จ (ไม่มีอะไรถูกเขียน): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('เปลี่ยนรหัสแล้ว: สาขา %d · สินค้า %d', $result['branches'], $result['products']));

        $sample = DB::table('product_barcodes')->whereNotNull('barcode')->where('barcode', '<>', '')
            ->inRandomOrder()->limit(50)->pluck('barcode')->all();
        $verified = $service->verifyBarcodes($sample);
        $this->line(sprintf('ทดสอบสแกนบาร์โค้ด %d รายการ: หาเจอ %d · หาไม่เจอ %d',
            $verified['checked'], $verified['resolved'], count($verified['failed'])));

        if ($verified['failed'] !== []) {
            $this->error('บาร์โค้ดที่สแกนไม่เจอ: '.implode(', ', array_slice($verified['failed'], 0, 10)));

            return self::FAILURE;
        }

        $this->line('เอกสารและ POS จะเริ่มเลขใหม่ตามรหัสสาขาใหม่เอง เพราะ document_sequences ว่างอยู่');
        $this->warn('ต้องสั่งให้เครื่อง POS ทุกเครื่อง sync catalog ใหม่ ไม่งั้นยังถือรหัสสินค้าเดิมอยู่');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $branchPlan
     * @param  array<int, array<string, mixed>>  $productPlan
     */
    private function writeReport(string $path, array $branchPlan, array $productPlan): void
    {
        $handle = fopen($path, 'wb');
        fwrite($handle, "\xEF\xBB\xBF");   // BOM ให้ Excel อ่านภาษาไทยออก
        fputcsv($handle, ['ชนิด', 'id', 'รหัสเดิม', 'รหัสใหม่', 'ชื่อ', 'จำนวนบาร์โค้ด']);
        foreach ($branchPlan as $row) {
            fputcsv($handle, ['สาขา', $row['id'], $row['legacy'], $row['new'], $row['name'], '']);
        }
        foreach ($productPlan as $row) {
            fputcsv($handle, ['สินค้า', $row['id'], $row['legacy'], $row['new'], $row['name'], $row['barcodes']]);
        }
        fclose($handle);
    }
}
