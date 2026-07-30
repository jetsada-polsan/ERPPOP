<?php

namespace App\Console\Commands;

use App\Models\LegacyTableMapping;
use App\Services\Mssql\LegacyMirrorSourceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RegisterLegacySchemaMappings extends Command
{
    protected $signature = 'mssql:register-schema-mappings';

    protected $description = 'Register read-only Bplus table-to-ERP mapping status without changing legacy data.';

    /** @var array<string, string> */
    private const CORE_MAPPING = [
        'BRANCH' => 'branches', 'WAREHOUSE' => 'warehouses', 'WARELOCATION' => 'warehouse_locations',
        'DOCTYPE' => 'document_types', 'DOCINFO' => 'documents', 'SALESMAN' => 'salesmen',
        'ARFILE' => 'customers', 'ARADDRESS' => 'customer_addresses', 'ARCONTACT' => 'customer_contacts',
        'APFILE' => 'suppliers', 'APADDRESS' => 'supplier_addresses',
        'ICCAT' => 'product_categories', 'ICDEPT' => 'product_departments', 'BRAND' => 'product_brands',
        'SKUMASTER' => 'products',
        'GOODSMMASTER' => 'product_barcodes', 'GOODSMASTER' => 'product_barcodes', 'UOFQTY' => 'product_units',
        'ARPRICETAB' => 'price_tables', 'ARPLU' => 'product_prices',
        'TRANSTKH' => 'stock_documents', 'TRANSTKD' => 'stock_document_items', 'SKUMOVE' => 'stock_movements',
        'AROE' => 'customer_open_items', 'ARDETAIL' => 'customer_ledger', 'ACCOUNTCHART' => 'chart_of_accounts',
        'TRANPAYJ' => 'gl_journals', 'CASHBOOK' => 'cash_books', 'BANKACCOUNT' => 'bank_accounts',
        'BANKFILE' => 'bank_accounts', 'MEMBER' => 'members',
    ];

    /** Physical/archive tables are never copied into the normalized ERP schema. */
    private const EXCLUDED_TECHNICAL = [
        'BPLUSCURRENTUSER', 'BPLUSDELETELOG', 'BPLUSINTLDATA', 'BPLUSLICENSE',
        'BPLUSSYNC', 'BPLUSVERSION', 'AUTOREPORTD', 'AUTOREPORTH', 'AUTORUNCODE',
        'AUTOUPDARPRB', 'BYDATANAME', 'CREDITS',
    ];

    public function handle(LegacyMirrorSourceService $source): int
    {
        $newTables = collect(Schema::getTables())->pluck('name')->filter(fn (string $name): bool => $name !== 'migrations')->all();
        $newByLower = array_fill_keys(array_map('strtolower', $newTables), true);
        $summary = ['mapped' => 0, 'needs_review' => 0, 'excluded' => 0];

        foreach ($source->tables() as $table) {
            $legacy = strtoupper($table['name']);
            $legacyColumns = $source->columns($table['schema'], $table['name']);
            $excluded = in_array($legacy, self::EXCLUDED_TECHNICAL, true)
                || preg_match('/^[CDHLPS]\d{6}$/', $legacy) === 1;
            $target = self::CORE_MAPPING[$legacy] ?? (isset($newByLower[strtolower($legacy)]) ? strtolower($legacy) : null);
            if ($excluded) {
                $target = null;
            }
            $targetColumns = $target !== null && in_array($target, $newTables, true) ? Schema::getColumnListing($target) : [];
            $shared = count(array_intersect(
                array_map(fn (array $column): string => strtoupper($column['name']), $legacyColumns),
                array_map('strtoupper', $targetColumns),
            ));
            $status = $excluded ? 'excluded' : ($target === null ? 'needs_review' : 'mapped');
            $type = $excluded ? 'excluded' : ($target === null ? 'unmapped' : (isset(self::CORE_MAPPING[$legacy]) ? 'normalized' : 'exact'));
            $module = $excluded ? 'archive' : $this->moduleFor($legacy, $target);

            LegacyTableMapping::updateOrCreate(
                ['legacy_database' => (string) config('mssql_source.database'), 'legacy_schema' => $table['schema'], 'legacy_table' => $legacy],
                [
                    'target_table' => $target,
                    'module' => $module,
                    'mapping_type' => $type,
                    'status' => $status,
                    'legacy_column_count' => count($legacyColumns),
                    'target_column_count' => count($targetColumns),
                    'shared_column_count' => $shared,
                    'notes' => $excluded ? 'ตารางเทคนิคหรือ physical monthly table ไม่สร้างซ้ำใน ERP ใหม่; เก็บเฉพาะต้นฉบับ/ประวัติเมื่อจำเป็น' : ($target === null ? 'ต้องกำหนด mapping และกฎนำเข้าก่อน migrate ข้อมูลจริง' : 'ยังต้องตรวจ key, business meaning และ posting rule ก่อน import'),
                ],
            );
            $summary[$status] = ($summary[$status] ?? 0) + 1;
        }

        $this->info("registered={$summary['mapped']} mapped, {$summary['needs_review']} needs_review, {$summary['excluded']} excluded");

        return self::SUCCESS;
    }

    private function moduleFor(string $legacy, ?string $target): string
    {
        $name = strtolower($target ?: $legacy);

        return match (true) {
            str_contains($name, 'sku'), str_contains($name, 'goods'), str_contains($name, 'product') => 'catalog',
            str_contains($name, 'stock'), str_contains($name, 'warehouse'), str_contains($name, 'tran') => 'inventory',
            str_contains($name, 'sale'), str_contains($name, 'doc'), str_contains($name, 'ar') => 'sales_ar',
            str_contains($name, 'account'), str_contains($name, 'pay'), str_contains($name, 'bank'), str_contains($name, 'vat') => 'finance',
            str_contains($name, 'pos'), str_starts_with($name, 'p') => 'pos',
            default => 'review',
        };
    }
}
