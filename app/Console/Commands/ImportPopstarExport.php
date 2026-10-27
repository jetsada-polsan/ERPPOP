<?php

namespace App\Console\Commands;

use App\Console\Concerns\RequiresFreshBackup;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Support\BarcodePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * นำเข้าไฟล์ POPSTAR DATA EXPORT (TSV สองส่วน [CUSTOMERS] / [PRODUCTS]) เข้าแฟ้มที่ล้างแล้ว
 *
 * กติกาที่เจ้าของกำหนด:
 * - ลูกค้าชื่อ+เบอร์ตรงกันคนละรหัส = ซ้ำ เก็บรหัสแรก
 * - สินค้า: รหัส 6 หลักในไฟล์คือรหัสจริง ประเภทดูจาก 3 ตัวแรกของรหัส (101001 -> 101)
 * - ต้นทุนเป็น 0 ทุกบาร์โค้ด หรือชื่อ #ยกเลิก# = สินค้าไม่ได้ใช้ ลงประเภท CC (สินค้ายกเลิก) ปิดใช้งาน
 * - บาร์ลูก 801xxx 6 หลัก = PLU เครื่องชั่ง; 801 ที่มีตัวเกินท้าย (8010310, 801044CC) คือรหัสที่ยกเลิกแล้ว ไม่นำเข้า
 * - บาร์โค้ดแต่ละตัวเก็บราคาของตัวเอง (POS ใช้ราคาบาร์โค้ดก่อนราคาสินค้า)
 */
class ImportPopstarExport extends Command
{
    use RequiresFreshBackup;

    public const RETIRED_CATEGORY = 'CC';

    protected $signature = 'erp:import-popstar-export
        {file : ไฟล์ TSV ที่มีส่วน [CUSTOMERS] และ [PRODUCTS]}
        {--unit=PCS : รหัสหน่วยนับหลักที่ใช้กับทุกสินค้า (สร้างให้ถ้ายังไม่มี)}
        {--dry-run : ตรวจและสรุปโดยไม่บันทึก}
        {--force : ข้ามคำถามยืนยัน}';

    protected $description = 'Import customers and products from the POPSTAR data export file';

    public function handle(BarcodePolicy $policy): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์ {$path}");

            return self::FAILURE;
        }

        $sections = $this->sections(file_get_contents($path));
        if (! isset($sections['CUSTOMERS'], $sections['PRODUCTS'])) {
            $this->error('ไฟล์ต้องมีส่วน [CUSTOMERS] และ [PRODUCTS]');

            return self::FAILURE;
        }

        [$customers, $duplicates] = $this->customers($sections['CUSTOMERS']);
        $products = $this->products($sections['PRODUCTS'], $policy);
        $categories = ProductCategory::pluck('id', 'code')->all();

        $errors = [];
        if (! isset($categories[self::RETIRED_CATEGORY])) {
            $errors[] = 'ไม่พบประเภทสินค้า CC (สินค้ายกเลิก)';
        }
        foreach ($products as $sku => &$product) {
            $product['category_id'] = $product['retired'] ? ($categories[self::RETIRED_CATEGORY] ?? null) : $this->categoryFor($sku, $categories);
        }
        unset($product);
        $uncategorised = array_keys(array_filter($products, fn ($p) => $p['category_id'] === null));

        // ต้องเป็นแฟ้มลูกค้าที่ล้างแล้วเท่านั้น รหัสใหม่ไม่ชนของเก่าก็จริง แต่จะได้ลูกค้าสองชุดปนกัน
        $existingCustomers = Customer::withTrashed()->count();
        $existingProducts = Product::withTrashed()->whereIn('sku_code', array_keys($products))->orWhereIn('legacy_sku', array_keys($products))->count();
        $barcodes = array_merge(...array_map(fn ($p) => array_column($p['barcodes'], 'barcode'), array_values($products)));
        $existingBarcodes = ProductBarcode::whereIn('barcode', $barcodes)->count();
        if ($existingCustomers > 0) {
            $errors[] = "ยังมีลูกค้าเดิมในระบบ {$existingCustomers} ราย (ล้างด้วย erp:purge-legacy-masters ก่อน)";
        }
        if ($existingProducts > 0 || $existingBarcodes > 0) {
            $errors[] = "มีสินค้า {$existingProducts} / บาร์โค้ด {$existingBarcodes} ซ้ำกับในระบบ (ล้างด้วย erp:purge-products ก่อน)";
        }

        $retired = array_filter($products, fn ($p) => $p['retired']);
        $skipped = array_merge(...array_map(fn ($p) => $p['skipped'], array_values($products)));
        $this->table(['รายการ', 'จำนวน'], [
            ['ลูกค้าที่จะนำเข้า', number_format(count($customers))],
            ['ลูกค้าซ้ำ (ชื่อ+เบอร์ตรง) ที่ตัดออก', number_format(count($duplicates))],
            ['สินค้าที่จะนำเข้า', number_format(count($products))],
            ['  - ใช้งาน', number_format(count($products) - count($retired))],
            ['  - ลงประเภท CC ปิดใช้งาน (ต้นทุน 0 / #ยกเลิก#)', number_format(count($retired))],
            ['บาร์โค้ดที่จะนำเข้า', number_format(count($barcodes))],
            ['บาร์โค้ดที่ไม่นำเข้า (รหัสชั่ง 801 ที่ยกเลิก)', number_format(count($skipped))],
            ['สินค้าที่หาประเภทไม่เจอ', number_format(count($uncategorised))],
        ]);
        if ($skipped !== []) {
            $this->line('บาร์โค้ดที่ไม่นำเข้า: '.implode(', ', $skipped));
        }
        if ($uncategorised !== []) {
            $errors[] = 'หาประเภทสินค้าไม่เจอ: '.implode(', ', array_slice($uncategorised, 0, 20));
        }
        foreach ($errors as $error) {
            $this->error($error);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('dry-run: ไม่มีข้อมูลใดถูกบันทึก');

            return self::SUCCESS;
        }

        if (! $this->hasFreshBackup()) {
            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('ยืนยันนำเข้า?', false)) {
            $this->info('ยกเลิก');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($customers, $products) {
                foreach ($customers as $row) {
                    $customer = Customer::create(['code' => $row['code'], 'name_th' => $row['name'], 'is_active' => true]);
                    if ($row['address'] !== '') {
                        CustomerAddress::create(['customer_id' => $customer->id, 'address_line' => $row['address'], 'is_default' => true]);
                    }
                    foreach ($this->phones($row['phone']) as $phone) {
                        CustomerContact::create(['customer_id' => $customer->id, 'name' => $row['name'], 'phone' => $phone]);
                    }
                }

                $unit = ProductUnit::firstOrCreate(['code' => (string) $this->option('unit')], ['name' => 'หน่วย', 'qty_per_base_unit' => 1]);
                foreach ($products as $sku => $row) {
                    $product = Product::create([
                        'sku_code' => $sku, 'legacy_sku' => $sku, 'name_th' => $row['name'],
                        'product_category_id' => $row['category_id'], 'base_unit_id' => $unit->id,
                        'default_price' => $row['price'], 'average_cost' => $row['cost'],
                        'is_vat' => false, 'is_active' => ! $row['retired'],
                    ]);
                    foreach ($row['barcodes'] as $barcode) {
                        ProductBarcode::create([
                            'product_id' => $product->id, 'barcode' => $barcode['barcode'], 'barcode_type' => $barcode['type'],
                            'unit_id' => $unit->id, 'unit_factor' => 1, 'price' => $barcode['price'], 'is_active' => true,
                        ]);
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->error('ล้มเหลว ไม่มีข้อมูลถูกบันทึก (rollback แล้ว): '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('นำเข้าลูกค้า %s ราย สินค้า %s รายการ บาร์โค้ด %s ตัว',
            number_format(count($customers)), number_format(count($products)), number_format(count($barcodes))));

        return self::SUCCESS;
    }

    /** @return array<string, array<int, array<int, string>>> */
    private function sections(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $sections = [];
        $current = null;
        foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
            if (preg_match('/^\[(\/?)([A-Z]+)\]$/', trim($line), $match)) {
                $current = $match[1] === '/' ? null : $match[2];
                if ($current !== null) {
                    $sections[$current] = [];
                }
                continue;
            }
            if ($current === null || trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }
            $sections[$current][] = array_map('trim', explode("\t", $line));
        }
        foreach ($sections as &$rows) {
            array_shift($rows); // หัวคอลัมน์
        }

        return $sections;
    }

    /** @return array{0: array<int, array<string, string>>, 1: array<int, string>} */
    private function customers(array $rows): array
    {
        $kept = [];
        $seen = [];
        $duplicates = [];
        foreach ($rows as $row) {
            [$code, $name, $address, $phone] = array_pad($row, 4, '');
            if ($code === '' || $name === '') {
                continue;
            }
            $key = preg_replace('/\s+/u', '', $name).'|'.preg_replace('/\D/', '', $phone);
            if (isset($seen[$key])) {
                $duplicates[] = $code;
                continue;
            }
            $seen[$key] = true;
            $kept[] = ['code' => $code, 'name' => $name, 'address' => $address, 'phone' => $phone];
        }

        return [$kept, $duplicates];
    }

    /**
     * แยกช่องเบอร์ที่มีหลายเบอร์ ("062-834-2714 , 087-549-5063") เป็นผู้ติดต่อละเบอร์
     * ช่องเบอร์ในระบบยาวได้ 30 ตัวอักษร
     *
     * @return array<int, string>
     */
    private function phones(string $raw): array
    {
        preg_match_all('/\+?\d[\d-]{4,}\d/', $raw, $matches);
        $phones = $matches[0] !== [] ? $matches[0] : array_filter([trim($raw)]);

        return array_values(array_unique(array_map(fn ($phone) => mb_substr($phone, 0, 30), $phones)));
    }

    /** @return array<string, array<string, mixed>> */
    private function products(array $rows, BarcodePolicy $policy): array
    {
        $products = [];
        foreach ($rows as $row) {
            [$sku, $barcode, $name, $cost, $price] = array_pad($row, 6, '');
            if ($sku === '') {
                continue;
            }
            $products[$sku] ??= ['name' => $name, 'rows' => [], 'barcodes' => [], 'skipped' => []];
            $products[$sku]['rows'][] = ['barcode' => $barcode, 'cost' => (float) $cost, 'price' => (float) $price];
        }

        foreach ($products as $sku => &$product) {
            // ราคาหลักมาจากบาร์โค้ดที่เท่ากับรหัสสินค้า (หน่วยขายหลัก) ถ้าไม่มีใช้แถวแรก
            $main = collect($product['rows'])->firstWhere('barcode', $sku) ?? $product['rows'][0];
            $product['price'] = $main['price'];
            $product['cost'] = $main['cost'];
            $product['retired'] = str_contains($product['name'], '#ยกเลิก#')
                || collect($product['rows'])->every(fn ($r) => $r['cost'] == 0);

            foreach ($product['rows'] as $row) {
                $code = $row['barcode'];
                if ($code === '' || in_array($code, array_column($product['barcodes'], 'barcode'), true)) {
                    continue;
                }
                if (str_starts_with($code, '801') && preg_match('/^801\d{3}$/', $code) !== 1 && strlen($code) < 13) {
                    $product['skipped'][] = $code;
                    continue;
                }
                $type = match (true) {
                    $policy->check(BarcodePolicy::SCALE_PLU, $code)['ok'] => BarcodePolicy::SCALE_PLU,
                    $policy->check(BarcodePolicy::EAN13_STANDARD, $code)['ok'] => BarcodePolicy::EAN13_STANDARD,
                    default => BarcodePolicy::CUSTOM,
                };
                $product['barcodes'][] = ['barcode' => $code, 'type' => $type, 'price' => $row['price'] > 0 ? $row['price'] : null];
            }
            unset($product['rows']);
        }

        return $products;
    }

    /** ประเภทจาก 3 ตัวแรกของรหัส (101001 -> 101) หรือ 2 ตัวสำหรับรหัสตัวอักษร (CO, PK, AS) */
    private function categoryFor(string $sku, array $categories): ?int
    {
        return $categories[substr($sku, 0, 3)] ?? $categories[substr($sku, 0, 2)] ?? null;
    }
}
