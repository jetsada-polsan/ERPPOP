<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the category assigned to each legacy SKU from BPlus IC021003.
 * It deliberately changes no barcode, price, stock, or SKU number.
 */
class LegacyProductCategoryReportService
{
    /** @return array<int, array{legacy_sku:string,category_code:string,category_name:string,product_name:string,line:int}> */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("อ่านไฟล์รายงานไม่ได้: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("เปิดไฟล์รายงานไม่ได้: {$path}");
        }

        $rows = [];
        $line = 0;
        while (($raw = fgetcsv($handle)) !== false) {
            $line++;
            $row = array_map(fn ($value) => $this->toUtf8((string) $value), $raw);

            // IC021003 repeats report headers on every row.  The report footer is
            // always 35 columns after the "ประเภทสินค้า" marker.
            $start = count($row) - 35;
            if ($start < 0 || ($row[$start] ?? '') !== 'ประเภทสินค้า') {
                continue;
            }

            $category = trim((string) ($row[$start + 1] ?? ''));
            $categoryName = trim((string) ($row[$start + 2] ?? ''));
            $legacySku = trim((string) ($row[$start + 3] ?? ''));
            $productName = trim((string) ($row[$start + 4] ?? ''));
            if ($category === '' || $legacySku === '' || $productName === '') {
                continue;
            }

            $rows[] = [
                'legacy_sku' => $legacySku,
                'category_code' => $category,
                'category_name' => $categoryName,
                'product_name' => $productName,
                'line' => $line,
            ];
        }
        fclose($handle);

        return $rows;
    }

    /** @return array{rows:array<int, array<string, mixed>>,problems:array<int, array{level:string,issue:string,detail:string}>} */
    public function plan(string $path): array
    {
        $source = $this->read($path);
        $categories = ProductCategory::query()->pluck('id', 'code')->mapWithKeys(
            fn ($id, $code) => [trim((string) $code) => (int) $id]
        );
        $products = Product::withTrashed()->with('category:id,code')->get(['id', 'sku_code', 'legacy_sku', 'product_category_id', 'name_th'])
            ->groupBy(fn (Product $product) => trim((string) $product->legacy_sku));
        $sourceCounts = collect($source)->countBy('legacy_sku');
        $rows = [];
        $problems = [];

        foreach ($source as $entry) {
            $legacySku = $entry['legacy_sku'];
            if (($sourceCounts[$legacySku] ?? 0) > 1) {
                $problems[] = ['level' => 'หยุด', 'issue' => 'ไฟล์มีรหัสสินค้าเดิมซ้ำ', 'detail' => "{$legacySku} บรรทัด {$entry['line']}"];
                continue;
            }
            if (! isset($categories[$entry['category_code']])) {
                $problems[] = ['level' => 'หยุด', 'issue' => 'ไม่พบประเภทสินค้าใน ERP', 'detail' => "{$entry['category_code']} · {$entry['category_name']}"];
                continue;
            }

            $matches = $products->get($legacySku, collect());
            if ($matches->isEmpty()) {
                $rows[] = $entry + ['status' => 'LEGACY_NOT_FOUND', 'product_id' => null, 'sku_current' => null, 'category_current' => null];
                continue;
            }
            if ($matches->count() !== 1) {
                $problems[] = ['level' => 'หยุด', 'issue' => 'รหัสสินค้าเดิมซ้ำใน ERP', 'detail' => "{$legacySku} พบ {$matches->count()} รายการ"];
                continue;
            }

            /** @var Product $product */
            $product = $matches->first();
            $rows[] = $entry + [
                'status' => (int) $product->product_category_id === $categories[$entry['category_code']] ? 'UNCHANGED' : 'CATEGORY_CHANGE',
                'product_id' => $product->id,
                'sku_current' => $product->sku_code,
                'category_current' => $product->category?->code,
                'category_target_id' => $categories[$entry['category_code']],
            ];
        }

        $mappedLegacy = collect($source)->pluck('legacy_sku')->all();
        $notInReport = Product::withTrashed()->whereNotNull('legacy_sku')->whereNotIn('legacy_sku', $mappedLegacy)->count();
        if ($notInReport > 0) {
            $problems[] = ['level' => 'เตือน', 'issue' => 'สินค้า ERP ไม่อยู่ในรายงาน IC021003', 'detail' => "{$notInReport} รายการ — คงประเภทเดิมไว้ ไม่ปิด/ไม่ลบ"];
        }

        return ['rows' => $rows, 'problems' => $this->uniqueProblems($problems)];
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function apply(array $rows): int
    {
        $changes = collect($rows)->where('status', 'CATEGORY_CHANGE')->values();

        return DB::transaction(function () use ($changes): int {
            foreach ($changes as $row) {
                Product::withTrashed()->whereKey($row['product_id'])->update([
                    'product_category_id' => $row['category_target_id'],
                ]);
            }
            return $changes->count();
        });
    }

    private function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = iconv('CP874', 'UTF-8//IGNORE', $value);
        if ($converted === false) {
            throw new RuntimeException('แปลงรหัสอักขระ CP874 ของรายงานเดิมไม่สำเร็จ');
        }

        return $converted;
    }

    /** @param array<int, array{level:string,issue:string,detail:string}> $problems */
    private function uniqueProblems(array $problems): array
    {
        return collect($problems)->unique(fn (array $problem) => implode('|', $problem))->values()->all();
    }
}
