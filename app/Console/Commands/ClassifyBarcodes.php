<?php

namespace App\Console\Commands;

use App\Support\BarcodePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * จัดประเภทบาร์โค้ดที่ยังเป็นค่าเริ่มต้น CUSTOM ไม่แก้ตัวเลขบาร์โค้ด SKU ราคา หรือหน่วย
 * แตะเฉพาะแถว CUSTOM แถวที่มีคนตั้งประเภทไว้แล้วไม่ยุ่ง
 */
class ClassifyBarcodes extends Command
{
    protected $signature = 'erp:classify-barcodes
        {--apply : บันทึกจริง ถ้าไม่ใส่จะแสดงผลอย่างเดียว}
        {--report= : เขียนรายการลง CSV}';

    protected $description = 'Classify default CUSTOM barcodes as Internal, EAN-13 or Legacy without changing their values';

    public function handle(BarcodePolicy $policy): int
    {
        $plan = [BarcodePolicy::EAN13_STANDARD => [], BarcodePolicy::LEGACY => []];
        $internal = 0;

        DB::table('product_barcodes')
            ->join('products', 'products.id', '=', 'product_barcodes.product_id')
            ->where('product_barcodes.barcode_type', BarcodePolicy::CUSTOM)
            ->select('product_barcodes.id', 'product_barcodes.barcode', 'products.sku_code')
            ->orderBy('product_barcodes.id')
            ->chunk(1000, function ($rows) use (&$plan, &$internal, $policy): void {
                foreach ($rows as $row) {
                    $type = self::classify((string) $row->barcode, (string) $row->sku_code, $policy);
                    $type === BarcodePolicy::CUSTOM ? $internal++ : $plan[$type][] = $row;
                }
            });

        $this->table(['ประเภท', 'จำนวน'], [
            ['Internal (คง CUSTOM ไว้)', $internal],
            ['EAN-13', count($plan[BarcodePolicy::EAN13_STANDARD])],
            ['Legacy / BPlus', count($plan[BarcodePolicy::LEGACY])],
        ]);

        if ($path = $this->option('report')) {
            $handle = fopen($path, 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['barcode_id', 'barcode', 'sku', 'new_type']);
            foreach ($plan as $type => $rows) {
                foreach ($rows as $row) {
                    fputcsv($handle, [$row->id, $row->barcode, $row->sku_code, $type]);
                }
            }
            fclose($handle);
            $this->info("รายงาน: {$path}");
        }

        if (! $this->option('apply')) {
            $this->info('แสดงผลอย่างเดียว ยังไม่ได้บันทึก ใส่ --apply เพื่อบันทึกจริง');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan): void {
            foreach ($plan as $type => $rows) {
                foreach (array_chunk(array_column($rows, 'id'), 500) as $ids) {
                    DB::table('product_barcodes')->whereIn('id', $ids)
                        ->where('barcode_type', BarcodePolicy::CUSTOM)
                        ->update(['barcode_type' => $type]);
                }
            }
        });
        $this->info('บันทึกแล้ว ไม่ได้แก้เลขบาร์โค้ด SKU ราคา หรือหน่วย');

        return self::SUCCESS;
    }

    /**
     * รหัสสั้นหรือเท่ากับ SKU = รหัสภายใน, EAN-13 ที่ผ่าน check digit = EAN-13
     * (เว้นเลขขึ้นต้น 2 ซึ่ง GS1 กันไว้ให้ร้านใช้เอง), ที่เหลือ = Legacy
     */
    public static function classify(string $barcode, string $sku, BarcodePolicy $policy): string
    {
        if ($barcode === $sku || (ctype_digit($barcode) && strlen($barcode) < 13)) {
            return BarcodePolicy::CUSTOM;
        }
        if ($policy->isValidEan13($barcode) && $barcode[0] !== '2') {
            return BarcodePolicy::EAN13_STANDARD;
        }

        return BarcodePolicy::LEGACY;
    }
}
