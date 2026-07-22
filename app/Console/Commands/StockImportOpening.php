<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Document;
use App\Models\Product;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Loads opening stock from opening_stock_template.csv (รหัสสินค้า, ชื่อสินค้า,
 * ต้นทุน/หน่วย, then one qty column per branch/warehouse — header cell must end
 * with the branch or warehouse_location code in parens, e.g. "ดอนกลาง(0001)").
 *
 * Reuses the existing Stock Adjustment (ตรวจนับสต็อก) maker-checker flow instead
 * of writing to stock_balances directly, so opening stock gets real stock_lots
 * (FIFO) and an auditable document per warehouse_location, exactly like a normal
 * physical count would. Since on_hand_qty is 0 everywhere before go-live, every
 * counted qty comes through as a 100% positive "found extra" diff.
 */
class StockImportOpening extends Command
{
    protected $signature = 'stock:import-opening
        {csv : path to the filled opening_stock_template.csv}
        {--dry-run : validate and preview only, write nothing}
        {--created-by= : user id recorded as the document creator}
        {--approve= : user id to approve as; omit to leave documents pending_approval for manual review}';

    protected $description = 'นำเข้าสต๊อกตั้งต้นจาก CSV ผ่าน Stock Adjustment flow (ทีละคลัง/สาขา)';

    public function handle(StockAdjustmentService $service): int
    {
        $path = (string) $this->argument('csv');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์ {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        if ($header === false || count($header) < 4) {
            $this->error('รูปแบบ CSV ไม่ถูกต้อง (ต้องมีอย่างน้อย รหัสสินค้า, ชื่อสินค้า, ต้นทุน/หน่วย, และคลังปลายทางอย่างน้อย 1 คอลัมน์)');

            return self::FAILURE;
        }
        // strip a UTF-8 BOM if the file was saved from Excel
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $locationColumns = [];
        for ($i = 3; $i < count($header); $i++) {
            $label = trim((string) $header[$i]);
            if (! preg_match('/\(([^()]+)\)\s*$/u', $label, $m)) {
                $this->warn("ข้ามคอลัมน์ \"{$label}\" (ไม่พบรหัสในวงเล็บท้ายชื่อคอลัมน์)");
                continue;
            }
            $code = trim($m[1]);

            $branch = Branch::where('code', $code)->first();
            if ($branch) {
                if (! $branch->default_warehouse_location_id) {
                    $this->error("สาขา {$code} ({$branch->name_th}) ไม่มี default_warehouse_location_id — แก้ที่หน้าสาขาก่อน");

                    return self::FAILURE;
                }
                $locationColumns[$i] = [
                    'label' => $label,
                    'branch_id' => $branch->id,
                    'warehouse_location_id' => $branch->default_warehouse_location_id,
                ];

                continue;
            }

            $location = WarehouseLocation::where('code', $code)->first();
            if (! $location) {
                $this->error("ไม่พบรหัสสาขาหรือคลัง \"{$code}\" (คอลัมน์ \"{$label}\") ในระบบ — ตรวจสอบรหัสในหัวตาราง CSV");

                return self::FAILURE;
            }
            $branchId = $location->warehouse?->branch_id
                ?? Branch::where('code', 'HO')->value('id');
            if (! $branchId) {
                $this->error("คลัง \"{$code}\" ไม่ได้ผูกกับสาขาใด และไม่พบสาขา HO ให้ลงเอกสารแทน — ระบุ branch_id เอง");

                return self::FAILURE;
            }
            $locationColumns[$i] = [
                'label' => $label,
                'branch_id' => $branchId,
                'warehouse_location_id' => $location->id,
            ];
        }

        if ($locationColumns === []) {
            $this->error('ไม่พบคอลัมน์คลังปลายทางที่ระบุรหัสได้เลย');

            return self::FAILURE;
        }

        $itemsByColumn = array_fill_keys(array_keys($locationColumns), []);
        $costUpdates = [];
        $unmatchedCodes = [];
        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $skuCode = trim((string) ($row[0] ?? ''));
            if ($skuCode === '') {
                continue;
            }

            $product = Product::where('sku_code', $skuCode)->first();
            if (! $product) {
                $unmatchedCodes[] = "{$skuCode} (แถว {$rowNum})";
                continue;
            }

            $unitCost = $this->parseNumber($row[2] ?? null);
            if ($unitCost !== null && $unitCost > 0) {
                $costUpdates[$product->id] = round($unitCost, 4);
            }

            foreach ($locationColumns as $colIndex => $meta) {
                $qty = $this->parseNumber($row[$colIndex] ?? null);
                if ($qty === null || $qty <= 0) {
                    continue;
                }
                $itemsByColumn[$colIndex][] = ['product_id' => $product->id, 'counted_qty' => $qty];
            }
        }
        fclose($handle);

        $this->info('สรุปคอลัมน์คลังปลายทางที่จับคู่ได้:');
        $this->table(['คอลัมน์', 'branch_id', 'warehouse_location_id', 'จำนวนบรรทัด'], collect($locationColumns)
            ->map(fn ($meta, $i) => [$meta['label'], $meta['branch_id'], $meta['warehouse_location_id'], count($itemsByColumn[$i])])
            ->values()->all());

        $this->info('ต้นทุน/หน่วยที่จะอัปเดต average_cost: '.count($costUpdates).' สินค้า');

        if ($unmatchedCodes !== []) {
            $this->warn(count($unmatchedCodes).' รหัสสินค้าไม่พบในระบบ (ข้ามทั้งต้นทุนและจำนวน): '.implode(', ', array_slice($unmatchedCodes, 0, 20))
                .(count($unmatchedCodes) > 20 ? ' ...' : ''));
        }

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีการเขียนข้อมูลใดๆ');

            return self::SUCCESS;
        }

        foreach ($costUpdates as $productId => $unitCost) {
            Product::whereKey($productId)->update([
                'average_cost' => $unitCost,
                'last_purchase_cost' => $unitCost,
                'last_purchase_cost_at' => now(),
            ]);
        }

        $createdBy = $this->option('created-by') ? (int) $this->option('created-by') : null;
        $approveAs = $this->option('approve') ? (int) $this->option('approve') : null;

        $results = [];
        foreach ($locationColumns as $colIndex => $meta) {
            $items = $itemsByColumn[$colIndex];
            if ($items === []) {
                $results[] = [$meta['label'], '-', 'ข้าม (ไม่มีจำนวนนับ)'];
                continue;
            }

            try {
                if ($createdBy) {
                    Auth::onceUsingId($createdBy);
                }
                $document = $service->create([
                    'branch_id' => $meta['branch_id'],
                    'warehouse_location_id' => $meta['warehouse_location_id'],
                    'remark' => 'นำเข้าสต๊อกตั้งต้น (opening stock import)',
                    'items' => $items,
                ]);
            } catch (RuntimeException $e) {
                $results[] = [$meta['label'], '-', 'สร้างเอกสารไม่สำเร็จ: '.$e->getMessage()];
                continue;
            }

            $status = 'รออนุมัติ ('.$document->doc_number.')';
            if ($approveAs) {
                try {
                    Auth::onceUsingId($approveAs);
                    $service->approve($document);
                    AuditLog::create([
                        'user_id' => $approveAs, 'branch_id' => $document->branch_id,
                        'action' => 'approve', 'table_name' => 'documents', 'record_id' => $document->id,
                        'old_values' => ['status' => 'pending_approval'], 'new_values' => ['status' => 'active'],
                    ]);
                    $status = 'อนุมัติแล้ว ('.$document->doc_number.')';
                } catch (RuntimeException $e) {
                    $status = 'สร้างแล้วแต่อนุมัติไม่สำเร็จ ('.$document->doc_number.'): '.$e->getMessage();
                }
            }
            $results[] = [$meta['label'], (string) $document->total_items, $status];
        }

        $this->table(['คลังปลายทาง', 'รายการ', 'สถานะ'], $results);
        if (! $approveAs) {
            $this->info('เอกสารทั้งหมดรออนุมัติ — เข้าไปอนุมัติที่หน้า /stock-adjustments (ต้องเป็นคนละ user กับผู้สร้าง)');
        }

        return self::SUCCESS;
    }

    private function parseNumber(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace(',', '', $value);

        return is_numeric($value) ? (float) $value : null;
    }
}
