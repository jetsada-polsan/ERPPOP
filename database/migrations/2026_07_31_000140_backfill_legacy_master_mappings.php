<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keep legacy business codes traceable without changing the active ERP codes.
 * The new ERP primary key is the local bigint id; this table records which
 * legacy master-data row supplied the business code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sources = [
            ['branches', 'code', 'BRANCH'],
            ['warehouses', 'code', 'WAREHOUSE'],
            ['product_categories', 'code', 'ICCAT'],
            ['product_departments', 'code', 'ICDEPT'],
            ['product_brands', 'code', 'BRAND'],
            ['product_units', 'code', 'UOFQTY'],
            ['products', 'sku_code', 'SKUMASTER'],
            ['customers', 'code', 'ARFILE'],
            ['suppliers', 'code', 'APFILE'],
            ['salesmen', 'code', 'SALESMAN'],
        ];

        foreach ($sources as [$table, $codeColumn, $legacyTable]) {
            DB::table($table)
                ->select('id', $codeColumn)
                ->orderBy('id')
                ->eachById(function (object $row) use ($table, $codeColumn, $legacyTable): void {
                    $legacyKey = trim((string) $row->{$codeColumn});
                    if ($legacyKey === '') {
                        return;
                    }

                    DB::table('legacy_mappings')->insertOrIgnore([
                        'legacy_database' => 'BPLUS',
                        'legacy_table' => $legacyTable,
                        'legacy_key' => $legacyKey,
                        'new_table' => $table,
                        'new_id' => $row->id,
                        'created_at' => now(),
                    ]);
                });
        }
    }

    public function down(): void
    {
        // Audit mappings are preserved to retain import traceability.
    }
};
