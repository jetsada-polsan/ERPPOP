<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Document;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseLocation;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

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
        {--approve= : user id to approve as; omit to leave documents pending_approval for manual review}
        {--skip-unmatched : explicitly allow product codes that do not exist to be skipped}
        {--allow-existing-stock : explicitly allow import into a warehouse that already has stock movement history}';

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

        $locationIds = collect($locationColumns)->pluck('warehouse_location_id')->unique();
        if (! $this->option('allow-existing-stock') && StockMovement::whereIn('warehouse_location_id', $locationIds)->exists()) {
            $this->error('พบประวัติการเคลื่อนไหวในคลังปลายทาง คำสั่ง opening stock จะไม่ทำงานซ้ำ ใช้ --allow-existing-stock เฉพาะเมื่อผู้ตรวจสอบยืนยันแล้ว');

            return self::FAILURE;
        }

        $itemsByColumn = array_fill_keys(array_keys($locationColumns), []);
        $unmatchedCodes = [];
        $validationErrors = [];
        $seenCodes = [];
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

            if (isset($seenCodes[$skuCode])) {
                $validationErrors[] = "รหัสสินค้า {$skuCode} ซ้ำที่แถว {$seenCodes[$skuCode]} และ {$rowNum}";

                continue;
            }
            $seenCodes[$skuCode] = $rowNum;

            $rawCost = trim((string) ($row[2] ?? ''));
            $unitCost = $this->parseNumber($rawCost);
            if ($rawCost !== '' && $unitCost === null) {
                $validationErrors[] = "ต้นทุนของ {$skuCode} แถว {$rowNum} ไม่ใช่ตัวเลข";

                continue;
            }

            $quantities = [];
            foreach ($locationColumns as $colIndex => $meta) {
                $rawQty = trim((string) ($row[$colIndex] ?? ''));
                $qty = $this->parseNumber($rawQty);
                if ($rawQty !== '' && $qty === null) {
                    $validationErrors[] = "จำนวน {$skuCode} คอลัมน์ {$meta['label']} แถว {$rowNum} ไม่ใช่ตัวเลข";

                    continue;
                }
                if ($qty !== null && $qty < 0) {
                    $validationErrors[] = "จำนวน {$skuCode} คอลัมน์ {$meta['label']} แถว {$rowNum} ติดลบ";

                    continue;
                }
                if ($qty !== null && $qty > 0) {
                    $quantities[$colIndex] = $qty;
                }
            }
            if ($quantities !== [] && ($unitCost === null || $unitCost <= 0)) {
                $validationErrors[] = "สินค้า {$skuCode} แถว {$rowNum} มีจำนวนเปิดสต๊อกแต่ไม่มีต้นทุนต่อหน่วยที่มากกว่า 0";

                continue;
            }
            foreach ($quantities as $colIndex => $qty) {
                $itemsByColumn[$colIndex][] = [
                    'product_id' => $product->id,
                    'counted_qty' => $qty,
                    'unit_cost' => round((float) $unitCost, 4),
                ];
            }
        }
        fclose($handle);

        $this->info('สรุปคอลัมน์คลังปลายทางที่จับคู่ได้:');
        $this->table(['คอลัมน์', 'branch_id', 'warehouse_location_id', 'จำนวนบรรทัด'], collect($locationColumns)
            ->map(fn ($meta, $i) => [$meta['label'], $meta['branch_id'], $meta['warehouse_location_id'], count($itemsByColumn[$i])])
            ->values()->all());

        if ($unmatchedCodes !== []) {
            $this->warn(count($unmatchedCodes).' รหัสสินค้าไม่พบในระบบ (ข้ามทั้งต้นทุนและจำนวน): '.implode(', ', array_slice($unmatchedCodes, 0, 20))
                .(count($unmatchedCodes) > 20 ? ' ...' : ''));
            if (! $this->option('skip-unmatched')) {
                $validationErrors[] = 'พบรหัสสินค้าที่ไม่มีในระบบ ใช้ --skip-unmatched เฉพาะเมื่อยืนยันว่าต้องการข้ามจริง';
            }
        }

        if ($validationErrors !== []) {
            foreach (array_slice($validationErrors, 0, 30) as $error) {
                $this->error($error);
            }
            $this->error('ยกเลิกการนำเข้า: กรุณาแก้ไฟล์แล้วรัน --dry-run ใหม่');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีการเขียนข้อมูลใดๆ');

            return self::SUCCESS;
        }

        $createdBy = $this->option('created-by') ? (int) $this->option('created-by') : null;
        $approveAs = $this->option('approve') ? (int) $this->option('approve') : null;
        if (! $createdBy || ! User::whereKey($createdBy)->exists()) {
            $this->error('ต้องระบุ --created-by เป็น user id ที่มีอยู่จริง เพื่อเก็บผู้จัดทำเอกสาร');

            return self::FAILURE;
        }
        if ($approveAs && (! User::whereKey($approveAs)->exists() || $approveAs === $createdBy)) {
            $this->error('--approve ต้องเป็น user id ที่มีอยู่จริงและเป็นคนละคนกับ --created-by');

            return self::FAILURE;
        }

        $results = [];
        try {
            DB::transaction(function () use ($locationColumns, $itemsByColumn, $createdBy, $approveAs, $service, &$results): void {
                foreach ($locationColumns as $colIndex => $meta) {
                    $items = $itemsByColumn[$colIndex];
                    if ($items === []) {
                        $results[] = [$meta['label'], '-', 'ข้าม (ไม่มีจำนวนนับ)'];

                        continue;
                    }

                    Auth::onceUsingId($createdBy);
                    $document = $service->create([
                        'branch_id' => $meta['branch_id'],
                        'warehouse_location_id' => $meta['warehouse_location_id'],
                        'remark' => 'นำเข้าสต๊อกตั้งต้น (opening stock import)',
                        'items' => $items,
                    ]);

                    $status = 'รออนุมัติ ('.$document->doc_number.')';
                    if ($approveAs) {
                        Auth::onceUsingId($approveAs);
                        $service->approve($document);
                        AuditLog::create([
                            'user_id' => $approveAs, 'branch_id' => $document->branch_id,
                            'action' => 'approve', 'table_name' => 'documents', 'record_id' => $document->id,
                            'old_values' => ['status' => 'pending_approval'], 'new_values' => ['status' => 'active'],
                        ]);
                        $status = 'อนุมัติแล้ว ('.$document->doc_number.')';
                    }
                    $results[] = [$meta['label'], (string) $document->total_items, $status];
                }
            });
        } catch (Throwable $exception) {
            $this->error('นำเข้าไม่สำเร็จและ rollback ทั้งไฟล์: '.$exception->getMessage());

            return self::FAILURE;
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
