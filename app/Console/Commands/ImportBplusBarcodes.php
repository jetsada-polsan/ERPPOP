<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Support\BarcodePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * เทียบบาร์โค้ดที่ขายจริงใน BPlus (จาก scripts/bplus-barcode-export.py) กับ ERP
 * แล้วเพิ่มเฉพาะตัวที่ ERP ยังไม่มี เลขบาร์โค้ดใช้ตามป้ายจริง ไม่แปลง ไม่เติม 0
 * ไม่แก้และไม่ลบบาร์โค้ดที่มีอยู่แล้ว ตัวที่ผูกคนละสินค้าหรือหาหน่วยไม่เจอ ส่งให้คนตรวจ
 */
class ImportBplusBarcodes extends Command
{
    protected $signature = 'erp:import-bplus-barcodes
        {file : CSV sku_code, barcode, unit_name, unit_factor, lines, last_sold}
        {--apply : บันทึกจริง ถ้าไม่ใส่จะแสดงผลอย่างเดียว}
        {--report= : เขียนรายการลง CSV}';

    protected $description = 'Compare sold BPlus barcodes with ERP and add only the missing ones';

    public function handle(BarcodePolicy $policy): int
    {
        $rows = $this->readRows((string) $this->argument('file'));
        if ($rows === null) {
            $this->error('อ่านไฟล์ไม่ได้ หรือหัวคอลัมน์ไม่ตรง');

            return self::FAILURE;
        }

        $products = Product::withTrashed()->get(['id', 'sku_code', 'legacy_sku', 'base_unit_id']);
        $byLegacy = $products->whereNotNull('legacy_sku')->keyBy('legacy_sku');
        $bySku = $products->keyBy('sku_code');
        $existing = ProductBarcode::query()->get(['barcode', 'product_id'])->pluck('product_id', 'barcode');
        $units = ProductUnit::query()->get(['id', 'name', 'qty_per_base_unit']);

        $plan = ['match' => [], 'add' => [], 'conflict' => [], 'no_product' => [], 'no_unit' => [], 'sku' => [], 'skip' => []];
        foreach ($rows as $row) {
            $product = $byLegacy->get($row['sku_code']) ?? $bySku->get($row['sku_code']);
            if ($row['barcode'] === $row['sku_code']) {
                // พิมพ์ SKU ตอนขาย ไม่ใช่ป้ายบาร์โค้ด POS ค้นด้วย SKU ได้อยู่แล้ว
                $plan['sku'][] = $row + ['reason' => 'พิมพ์ SKU ไม่ใช่บาร์โค้ด'];
            } elseif (! $this->looksLikeBarcode($row['barcode'])) {
                $plan['skip'][] = $row + ['reason' => 'ไม่ใช่บาร์โค้ด'];
            } elseif (! $product) {
                $plan['no_product'][] = $row + ['reason' => 'ไม่พบสินค้าใน ERP'];
            } elseif ($existing->has($row['barcode'])) {
                $owner = (int) $existing->get($row['barcode']);
                $owner === $product->id
                    ? $plan['match'][] = $row
                    : $plan['conflict'][] = $row + ['reason' => 'ERP ผูกบาร์โค้ดนี้กับสินค้าอื่น ('.($products->firstWhere('id', $owner)?->sku_code ?? $owner).')'];
            } elseif (($packSize = $this->packSizeInName($row['unit_name'])) !== null && abs($packSize - $row['unit_factor']) > 0.0001) {
                $plan['no_unit'][] = $row + ['reason' => 'ชื่อหน่วย "'.$row['unit_name'].'" บอก '.$packSize.' แต่ BPlus ขายตัวคูณ '.$row['unit_factor']];
            } else {
                // ERP เก็บตัวคูณไว้ที่บาร์โค้ด หน่วยระบุด้วยชื่ออย่างเดียว ไม่มีหน่วยชื่อนี้ = สร้างตอนบันทึก
                $unit = $units->first(fn (ProductUnit $unit) => trim((string) $unit->name) === $row['unit_name']);
                $plan['add'][] = $row + ['product_id' => $product->id, 'unit_id' => $unit?->id, 'type' => $this->typeFor($row, $policy)];
            }
        }

        $this->table(['กลุ่ม', 'จำนวน'], [
            ['ตรงกันแล้ว', count($plan['match'])],
            ['ขาดใน ERP (จะเพิ่ม)', count($plan['add'])],
            ['ผูกคนละสินค้า (ต้องตรวจ)', count($plan['conflict'])],
            ['ไม่พบสินค้าใน ERP', count($plan['no_product'])],
            ['ตัวคูณขัดกับชื่อหน่วย (ต้องตรวจ)', count($plan['no_unit'])],
            ['หน่วยใหม่ที่จะสร้าง', collect($plan['add'])->whereNull('unit_id')->pluck('unit_name')->unique()->count()],
            ['พิมพ์ SKU ตรง ๆ (ข้าม)', count($plan['sku'])],
            ['ไม่ใช่บาร์โค้ด (ข้าม)', count($plan['skip'])],
        ]);

        if ($path = $this->option('report')) {
            $this->writeReport($path, $plan);
            $this->info("รายงาน: {$path}");
        }
        if (! $this->option('apply')) {
            $this->info('แสดงผลอย่างเดียว ยังไม่ได้บันทึก ใส่ --apply เพื่อเพิ่มบาร์โค้ดที่ขาด');

            return self::SUCCESS;
        }

        $added = 0;
        DB::transaction(function () use ($plan, &$added): void {
            foreach ($plan['add'] as $row) {
                // ตรวจซ้ำในทรานแซกชัน กันกรณีมีคนเพิ่มบาร์โค้ดเดียวกันระหว่างรอ
                if (ProductBarcode::where('barcode', $row['barcode'])->exists()) {
                    continue;
                }
                $unitId = $row['unit_id'] ?? $this->unitNamed($row['unit_name'])->id;
                ProductBarcode::create([
                    'product_id' => $row['product_id'], 'barcode' => $row['barcode'], 'barcode_type' => $row['type'],
                    'unit_id' => $unitId, 'unit_factor' => $row['unit_factor'], 'is_active' => true, 'source' => 'bplus',
                ]);
                $added++;
            }
        });
        $this->info("เพิ่มบาร์โค้ดแล้ว {$added} รายการ ไม่ได้แก้หรือลบบาร์โค้ดเดิม");

        return self::SUCCESS;
    }

    /** @return array<int, array{sku_code:string, barcode:string, unit_name:string, unit_factor:float, lines:int}>|null */
    private function readRows(string $path): ?array
    {
        if (! is_readable($path) || ! ($handle = fopen($path, 'rb'))) {
            return null;
        }
        $header = fgetcsv($handle);
        $header = array_map(fn ($value) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value)), $header ?: []);
        if (array_slice($header, 0, 5) !== ['sku_code', 'barcode', 'unit_name', 'unit_factor', 'lines']) {
            return null;
        }

        // บาร์โค้ดเดียวอาจขายด้วยหลายหน่วยในบิลเก่า เลือกหน่วยที่ขายมากสุด
        $best = [];
        while (($line = fgetcsv($handle)) !== false) {
            $row = [
                'sku_code' => trim((string) ($line[0] ?? '')), 'barcode' => trim((string) ($line[1] ?? '')),
                'unit_name' => trim((string) ($line[2] ?? '')), 'unit_factor' => (float) ($line[3] ?? 1), 'lines' => (int) ($line[4] ?? 0),
            ];
            $key = $row['sku_code']."\t".$row['barcode'];
            if (! isset($best[$key]) || $row['lines'] > $best[$key]['lines']) {
                $best[$key] = $row;
            }
        }
        fclose($handle);

        return array_values($best);
    }

    /** ค่าขยะอย่าง -, 000000, 0000000000001 และรหัสที่ BPlus ต่อท้าย CC (ยกเลิกแล้ว) */
    private function looksLikeBarcode(string $barcode): bool
    {
        return preg_match('/^[0-9A-Za-z]{6,24}$/', $barcode) === 1
            && ! str_ends_with(strtoupper($barcode), 'CC')
            && strlen(ltrim($barcode, '0')) > 1;
    }

    /** ตัวเลขขนาดบรรจุท้ายชื่อหน่วย เช่น "กล่องx12", "กล่อง x 20", "หิ้ว*25" ไม่มี = null */
    private function packSizeInName(string $name): ?float
    {
        return preg_match('/[x*×]\s*(\d+(?:\.\d+)?)\s*\.?\s*$/iu', $name, $match) === 1 ? (float) $match[1] : null;
    }

    /** หน่วยใช้ชื่อเป็นตัวระบุ เหมือน erp:apply-unit-export */
    private function unitNamed(string $name): ProductUnit
    {
        $name = mb_substr($name, 0, 100);

        return ProductUnit::where('name', $name)->first()
            ?? ProductUnit::create(['code' => 'U'.substr(md5($name), 0, 10), 'name' => $name, 'qty_per_base_unit' => 1]);
    }

    private function typeFor(array $row, BarcodePolicy $policy): string
    {
        $code = $row['barcode'];
        if ($policy->check(BarcodePolicy::SCALE_PLU, $code)['ok']) {
            return BarcodePolicy::SCALE_PLU;
        }
        if ($policy->isValidEan13($code) && str_starts_with($code, '885')) {
            return BarcodePolicy::EAN13_STANDARD;
        }

        return ClassifyBarcodes::classify($code, $row['sku_code'], $policy);
    }

    private function writeReport(string $path, array $plan): void
    {
        $handle = fopen($path, 'wb');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['group', 'sku_code', 'barcode', 'unit_name', 'unit_factor', 'lines', 'note']);
        foreach ($plan as $group => $rows) {
            if ($group === 'match' || $group === 'sku') {
                continue;
            }
            foreach ($rows as $row) {
                fputcsv($handle, [$group, $row['sku_code'], $row['barcode'], $row['unit_name'], $row['unit_factor'], $row['lines'], $row['reason'] ?? $row['type'] ?? '']);
            }
        }
        fclose($handle);
    }
}
