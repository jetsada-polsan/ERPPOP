<?php

namespace App\Services\Etl;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls master data (products, customers, suppliers, branches, warehouses, stock
 * balances, salesmen) from the BPlus MSSQL source (read-only, via
 * MssqlMasterDataSourceService) and upserts it into the PostgreSQL tables designed
 * in POSTGRESQL_NEW_SCHEMA.md. Safe to re-run: "master" tables (branches,
 * categories, products, customers, ...) are upserted by their natural business code,
 * so re-running only updates changed rows. "Detail" tables that have no other FK
 * dependents (addresses, contacts, stock balances) are fully replaced each run,
 * treating MSSQL as the source of truth for them.
 *
 * Order matters - each step only runs after the master tables it references.
 */
class MasterDataEtlService
{
    private const CHUNK = 500;

    public function __construct(
        private readonly MssqlMasterDataSourceService $source,
    ) {}

    /** @return array<string, int> counts per step, for reporting */
    public function run(): array
    {
        $counts = [];
        $counts['branches'] = $this->syncBranches();
        $counts['product_categories'] = $this->syncProductCategories();
        $counts['product_departments'] = $this->syncProductDepartments();
        $counts['product_brands'] = $this->syncProductBrands();
        $counts['product_units'] = $this->syncProductUnits();
        $counts['products'] = $this->syncProducts();
        $counts['product_barcodes'] = $this->syncProductBarcodes();
        $counts['warehouses'] = $this->syncWarehouses();
        $counts['warehouse_locations'] = $this->syncWarehouseLocations();
        $counts['branch_default_locations'] = $this->syncBranchDefaultLocations();
        $counts['stock_balances'] = $this->syncStockBalances();
        $counts['customers'] = $this->syncCustomers();
        $counts['customer_addresses'] = $this->syncCustomerAddresses();
        $counts['customer_contacts'] = $this->syncCustomerContacts();
        $counts['suppliers'] = $this->syncSuppliers();
        $counts['supplier_addresses'] = $this->syncSupplierAddresses();
        $counts['salesmen'] = $this->syncSalesmen();
        $counts['pos_terminals'] = $this->syncPosTerminals();
        $counts['document_types'] = $this->syncDocumentTypes();

        return $counts;
    }

    private function syncBranches(): int
    {
        $rows = array_map(fn ($r) => [
            'code' => trim($r['BR_CODE']),
            'name_th' => trim($r['BR_THAIDESC'] ?? '') ?: trim($r['BR_CODE']),
            'name_en' => $this->blankToNull($r['BR_ENGDESC'] ?? null),
            'is_active' => true,
            'updated_at' => now(),
        ], $this->source->fetchBranches());

        return $this->upsertChunked('branches', $rows, ['code'], ['name_th', 'name_en', 'is_active', 'updated_at']);
    }

    private function syncProductCategories(): int
    {
        $rows = array_map(fn ($r) => [
            'code' => trim($r['ICCAT_CODE']),
            'name_th' => trim($r['ICCAT_NAME'] ?? '') ?: trim($r['ICCAT_CODE']),
            'name_en' => null,
        ], $this->source->fetchProductCategories());

        return $this->upsertChunked('product_categories', $rows, ['code'], ['name_th']);
    }

    private function syncProductDepartments(): int
    {
        $rows = array_map(fn ($r) => [
            'code' => trim($r['ICDEPT_CODE']),
            'name_th' => trim($r['ICDEPT_THAIDESC'] ?? '') ?: trim($r['ICDEPT_CODE']),
            'name_en' => $this->blankToNull($r['ICDEPT_ENGDESC'] ?? null),
        ], $this->source->fetchProductDepartments());

        return $this->upsertChunked('product_departments', $rows, ['code'], ['name_th', 'name_en']);
    }

    private function syncProductBrands(): int
    {
        $rows = array_map(fn ($r) => [
            'code' => trim($r['BRN_CODE']),
            'name_th' => trim($r['BRN_NAME'] ?? '') ?: trim($r['BRN_CODE']),
            'name_en' => null,
        ], $this->source->fetchProductBrands());

        return $this->upsertChunked('product_brands', $rows, ['code'], ['name_th']);
    }

    private function syncProductUnits(): int
    {
        // UOFQTY has no human code column - UTQ_KEY (the surrogate id) is the
        // closest thing to a stable code and is what SKUMASTER/GOODSMASTER
        // reference, so it's used as-is.
        $rows = array_map(fn ($r) => [
            'code' => (string) $r['UTQ_KEY'],
            'name' => trim($r['UTQ_NAME'] ?? '') ?: (string) $r['UTQ_KEY'],
            'qty_per_base_unit' => (float) ($r['UTQ_QTY'] ?? 1),
        ], $this->source->fetchProductUnits());

        return $this->upsertChunked('product_units', $rows, ['code'], ['name', 'qty_per_base_unit']);
    }

    private function syncProducts(): int
    {
        $categoryIds = $this->codeToIdMap('product_categories');
        $departmentIds = $this->codeToIdMap('product_departments');
        $brandIds = $this->codeToIdMap('product_brands');
        $unitIds = $this->codeToIdMap('product_units');

        $rows = [];
        foreach ($this->source->fetchProducts() as $r) {
            $skuCode = trim($r['SKU_CODE'] ?? '');
            if ($skuCode === '') {
                continue;
            }

            $baseUnitId = $unitIds[(string) ($r['SKU_S_UTQ'] ?? '')] ?? null;
            if ($baseUnitId === null) {
                continue; // products.base_unit_id is NOT NULL; skip rather than insert garbage
            }

            $rows[] = [
                'sku_code' => $skuCode,
                'name_th' => trim($r['SKU_NAME'] ?? '') ?: $skuCode,
                'name_en' => $this->blankToNull($r['SKU_E_NAME'] ?? null),
                'product_category_id' => $categoryIds[trim($r['ICCAT_CODE'] ?? '')] ?? null,
                'product_department_id' => $departmentIds[trim($r['ICDEPT_CODE'] ?? '')] ?? null,
                'product_brand_id' => $brandIds[trim($r['BRN_CODE'] ?? '')] ?? null,
                'base_unit_id' => $baseUnitId,
                'default_price' => is_numeric($r['SKU_PRICE'] ?? null) ? (float) $r['SKU_PRICE'] : null,
                'is_active' => ($r['SKU_ENABLE'] ?? 'N') === 'Y',
                'updated_at' => now(),
            ];
        }

        return $this->upsertChunked('products', $rows, ['sku_code'], [
            'name_th', 'name_en', 'product_category_id', 'product_department_id',
            'product_brand_id', 'base_unit_id', 'default_price', 'is_active', 'updated_at',
        ]);
    }

    private function syncProductBarcodes(): int
    {
        $productIds = $this->codeToIdMap('products', 'sku_code');
        $unitIds = $this->codeToIdMap('product_units');

        $rows = [];
        foreach ($this->source->fetchProductBarcodes() as $r) {
            $barcode = trim($r['GOODS_CODE'] ?? '');
            $productId = $productIds[trim($r['SKU_CODE'] ?? '')] ?? null;
            $unitId = $unitIds[(string) ($r['GOODS_UTQ'] ?? '')] ?? null;
            if ($barcode === '' || $productId === null || $unitId === null) {
                continue; // unit_id is NOT NULL - skip rather than insert an invalid FK
            }

            $rows[] = [
                'product_id' => $productId,
                'barcode' => $barcode,
                'unit_id' => $unitId,
                'unit_factor' => 1,
                'price' => is_numeric($r['GOODS_PRICE'] ?? null) ? (float) $r['GOODS_PRICE'] : null,
                'is_active' => ($r['GOODS_ENABLE'] ?? 'N') === 'Y',
            ];
        }

        return $this->upsertChunked('product_barcodes', $rows, ['barcode'], [
            'product_id', 'unit_id', 'unit_factor', 'price', 'is_active',
        ]);
    }

    private function syncWarehouses(): int
    {
        $rows = array_map(fn ($r) => [
            'code' => trim($r['WH_CODE']),
            'name' => trim($r['WH_NAME'] ?? '') ?: trim($r['WH_CODE']),
        ], $this->source->fetchWarehouses());

        return $this->upsertChunked('warehouses', $rows, ['code'], ['name']);
    }

    private function syncWarehouseLocations(): int
    {
        $warehouseIds = $this->codeToIdMap('warehouses');

        $rows = [];
        foreach ($this->source->fetchWarehouseLocations() as $r) {
            $warehouseId = $warehouseIds[trim($r['WH_CODE'] ?? '')] ?? null;
            $code = trim($r['WL_CODE'] ?? '');
            if ($warehouseId === null || $code === '') {
                continue;
            }

            $rows[] = [
                'warehouse_id' => $warehouseId,
                'code' => $code,
                'name' => $this->blankToNull($r['WL_NAME'] ?? null),
            ];
        }

        return $this->upsertChunked('warehouse_locations', $rows, ['warehouse_id', 'code'], ['name']);
    }

    /**
     * BPlus has no branch->warehouse foreign key (confirmed: WAREHOUSE.WH_ADDB /
     * ADDRBOOK.ADDB_BRANCH are blank for every warehouse). In this business's real
     * data, individual WARELOCATION rows under the "SHOP" warehouse are each named
     * after a branch (e.g. WARELOCATION "ห้วยวังนอง" = branch "สาขา-ห้วยวังนอง"),
     * so the link is resolved by matching branch name_th (with the "สาขา-"/"สาขา "
     * prefix stripped) against warehouse_locations.name. Head office has no name
     * match and falls back to the "HONA" ("ไม่ได้กำหนดตำแหน่ง" / unassigned)
     * location under the "HO" warehouse.
     */
    private function syncBranchDefaultLocations(): int
    {
        $locations = DB::table('warehouse_locations')->select('id', 'name', 'warehouse_id')->get();
        $hoFallbackId = DB::table('warehouse_locations as wl')
            ->join('warehouses as wh', 'wh.id', '=', 'wl.warehouse_id')
            ->where('wh.code', 'HO')->where('wl.code', 'HONA')
            ->value('wl.id');

        $matched = 0;
        foreach (DB::table('branches')->select('id', 'name_th')->get() as $branch) {
            $needle = preg_replace('/^สาขา[- ]?/u', '', trim($branch->name_th));

            $locationId = $locations->first(fn ($l) => $l->name !== null && str_contains($l->name, $needle))?->id
                ?? $hoFallbackId;

            if ($locationId !== null) {
                DB::table('branches')->where('id', $branch->id)->update(['default_warehouse_location_id' => $locationId]);
                $matched++;
            }
        }

        return $matched;
    }

    private function syncStockBalances(): int
    {
        $productIds = $this->codeToIdMap('products', 'sku_code');
        $locationIds = $this->warehouseLocationCodeMap();

        $rows = [];
        foreach ($this->source->fetchStockBalances() as $r) {
            $productId = $productIds[trim($r['SKU_CODE'] ?? '')] ?? null;
            $locationId = $locationIds[trim($r['WH_CODE'] ?? '').'|'.trim($r['WL_CODE'] ?? '')] ?? null;
            if ($productId === null || $locationId === null) {
                continue;
            }

            $rows[] = [
                'product_id' => $productId,
                'warehouse_location_id' => $locationId,
                'on_hand_qty' => (float) ($r['TOTAL_QTY'] ?? 0),
                'reserved_qty' => 0,
                'updated_at' => now(),
            ];
        }

        // Snapshot sync: MSSQL is the source of truth for this table, so replace fully.
        DB::table('stock_balances')->truncate();

        return $this->upsertChunked('stock_balances', $rows, ['product_id', 'warehouse_location_id'], ['on_hand_qty', 'reserved_qty', 'updated_at']);
    }

    private function syncCustomers(): int
    {
        $rows = [];
        foreach ($this->source->fetchCustomers() as $r) {
            $code = trim($r['AR_CODE'] ?? '');
            if ($code === '') {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'name_th' => trim($r['AR_NAME'] ?? '') ?: $code,
                'name_en' => null,
                'is_active' => ($r['AR_ENABLE'] ?? 'N') === 'Y',
                'updated_at' => now(),
            ];
        }

        return $this->upsertChunked('customers', $rows, ['code'], ['name_th', 'name_en', 'is_active', 'updated_at']);
    }

    private function syncCustomerAddresses(): int
    {
        $customerIds = $this->codeToIdMap('customers');

        $rows = [];
        foreach ($this->source->fetchCustomerAddresses() as $r) {
            $customerId = $customerIds[trim($r['AR_CODE'] ?? '')] ?? null;
            if ($customerId === null) {
                continue;
            }

            $line = $this->joinNonEmpty([
                $r['ADDB_ADDB_1'] ?? null, $r['ADDB_ADDB_2'] ?? null, $r['ADDB_ADDB_3'] ?? null,
                $r['ADDB_PROVINCE'] ?? null, $r['ADDB_POST'] ?? null,
            ]);
            if ($line === '') {
                continue;
            }

            $rows[] = [
                'customer_id' => $customerId,
                'address_line' => $line,
                'is_default' => ($r['ARA_DEFAULT'] ?? 'N') === 'Y',
            ];
        }

        DB::table('customer_addresses')->truncate();
        $this->insertChunked('customer_addresses', $rows);

        return count($rows);
    }

    private function syncCustomerContacts(): int
    {
        $customerIds = $this->codeToIdMap('customers');

        $rows = [];
        foreach ($this->source->fetchCustomerContacts() as $r) {
            $customerId = $customerIds[trim($r['AR_CODE'] ?? '')] ?? null;
            if ($customerId === null) {
                continue;
            }

            $rows[] = [
                'customer_id' => $customerId,
                'name' => $this->blankToNull(trim(($r['CT_NAME'] ?? '').' '.($r['CT_SURNME'] ?? ''))),
                'phone' => $this->blankToNull($r['CT_MOBILE'] ?? null),
                'email' => $this->blankToNull($r['CT_EMAIL'] ?? null),
            ];
        }

        DB::table('customer_contacts')->truncate();
        $this->insertChunked('customer_contacts', $rows);

        return count($rows);
    }

    private function syncSuppliers(): int
    {
        $rows = [];
        foreach ($this->source->fetchSuppliers() as $r) {
            $code = trim($r['AP_CODE'] ?? '');
            if ($code === '') {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'name_th' => trim($r['AP_NAME'] ?? '') ?: $code,
                'name_en' => null,
                'is_active' => ($r['AP_ENABLE'] ?? 'N') === 'Y',
                'updated_at' => now(),
            ];
        }

        return $this->upsertChunked('suppliers', $rows, ['code'], ['name_th', 'name_en', 'is_active', 'updated_at']);
    }

    private function syncSupplierAddresses(): int
    {
        $supplierIds = $this->codeToIdMap('suppliers');

        $rows = [];
        foreach ($this->source->fetchSupplierAddresses() as $r) {
            $supplierId = $supplierIds[trim($r['AP_CODE'] ?? '')] ?? null;
            if ($supplierId === null) {
                continue;
            }

            $line = $this->joinNonEmpty([
                $r['ADDB_ADDB_1'] ?? null, $r['ADDB_ADDB_2'] ?? null, $r['ADDB_ADDB_3'] ?? null,
                $r['ADDB_PROVINCE'] ?? null, $r['ADDB_POST'] ?? null,
            ]);
            if ($line === '') {
                continue;
            }

            $rows[] = [
                'supplier_id' => $supplierId,
                'address_line' => $line,
                'is_default' => ($r['APA_DEFAULT'] ?? 'N') === 'Y',
            ];
        }

        DB::table('supplier_addresses')->truncate();
        $this->insertChunked('supplier_addresses', $rows);

        return count($rows);
    }

    private function syncSalesmen(): int
    {
        $rows = [];
        foreach ($this->source->fetchSalesmen() as $r) {
            $code = trim($r['SLMN_CODE'] ?? '');
            if ($code === '') {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'name' => trim($r['SLMN_NAME'] ?? '') ?: $code,
                'is_active' => ($r['SLMN_ENABLE'] ?? 'N') === 'Y',
            ];
        }

        return $this->upsertChunked('salesmen', $rows, ['code'], ['name', 'is_active']);
    }

    /**
     * One POS terminal per branch (PSH_POS in MSSQL stores BRANCH.BR_KEY directly -
     * this BPlus setup has exactly one POS per branch).
     */
    private function syncPosTerminals(): int
    {
        $branches = DB::table('branches')->select('id', 'code', 'name_th')->get();

        $rows = $branches->map(fn ($b) => [
            'branch_id' => $b->id,
            'code' => $b->code,
            'name' => $b->name_th,
        ])->all();

        return $this->upsertChunked('pos_terminals', $rows, ['code'], ['branch_id', 'name']);
    }

    /**
     * Not sourced from MSSQL - these are the normalized business document types
     * decided in Round 3 (BPLUS_SCHEMA_ANALYSIS.md §3.1), replacing the legacy
     * DOCTYPE table's 197 rows (many of which were per-salesperson/per-area
     * variants of the same handful of real document meanings).
     */
    private function syncDocumentTypes(): int
    {
        $rows = [
            ['code' => 'BOOKING', 'name_th' => 'ใบจอง', 'name_en' => 'Booking', 'affects_stock' => true, 'affects_ar' => false, 'affects_ap' => false],
            ['code' => 'CREDIT_SALE', 'name_th' => 'ใบขายเชื่อ', 'name_en' => 'Credit Sale', 'affects_stock' => true, 'affects_ar' => true, 'affects_ap' => false],
            ['code' => 'CASH_SALE', 'name_th' => 'ใบขายสด', 'name_en' => 'Cash Sale', 'affects_stock' => true, 'affects_ar' => false, 'affects_ap' => false],
            ['code' => 'SALE_RETURN', 'name_th' => 'ใบรับคืนสินค้า', 'name_en' => 'Sale Return', 'affects_stock' => true, 'affects_ar' => true, 'affects_ap' => false],
            ['code' => 'PURCHASE', 'name_th' => 'ใบซื้อเชื่อ', 'name_en' => 'Purchase', 'affects_stock' => true, 'affects_ar' => false, 'affects_ap' => true],
            ['code' => 'STOCK_TRANSFER', 'name_th' => 'ใบโอนย้ายสินค้า', 'name_en' => 'Stock Transfer', 'affects_stock' => true, 'affects_ar' => false, 'affects_ap' => false],
            ['code' => 'STOCK_REQUISITION', 'name_th' => 'ใบเบิกสินค้า', 'name_en' => 'Stock Requisition', 'affects_stock' => true, 'affects_ar' => false, 'affects_ap' => false],
            ['code' => 'STOCK_DAMAGE', 'name_th' => 'ใบตัดชำรุดสินค้า', 'name_en' => 'Stock Damage', 'affects_stock' => true, 'affects_ar' => false, 'affects_ap' => false],
            ['code' => 'PAYMENT_VOUCHER', 'name_th' => 'ใบสำคัญจ่าย', 'name_en' => 'Payment Voucher', 'affects_stock' => false, 'affects_ar' => false, 'affects_ap' => true],
            ['code' => 'RECEIPT', 'name_th' => 'ใบเสร็จรับเงิน', 'name_en' => 'Receipt', 'affects_stock' => false, 'affects_ar' => true, 'affects_ap' => false],
            ['code' => 'QUOTATION', 'name_th' => 'ใบเสนอราคา', 'name_en' => 'Quotation', 'affects_stock' => false, 'affects_ar' => false, 'affects_ap' => false],
        ];

        foreach ($rows as &$row) {
            $row['is_active'] = true;
        }

        return $this->upsertChunked('document_types', $rows, ['code'], ['name_th', 'name_en', 'affects_stock', 'affects_ar', 'affects_ap', 'is_active']);
    }

    // ---- helpers ----

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === '' || $value === null) ? null : (string) $value;
    }

    private function joinNonEmpty(array $parts): string
    {
        return implode(' ', array_filter(array_map(fn ($p) => trim((string) ($p ?? '')), $parts), fn ($p) => $p !== ''));
    }

    /** @return array<string, int> code => id */
    private function codeToIdMap(string $table, string $codeColumn = 'code'): array
    {
        return DB::table($table)->pluck('id', $codeColumn)->mapWithKeys(fn ($id, $code) => [(string) $code => $id])->all();
    }

    /** @return array<string, int> "WH_CODE|WL_CODE" => warehouse_location id */
    private function warehouseLocationCodeMap(): array
    {
        $map = [];
        foreach (DB::table('warehouse_locations as wl')
            ->join('warehouses as wh', 'wh.id', '=', 'wl.warehouse_id')
            ->select('wl.id', 'wh.code as wh_code', 'wl.code as wl_code')
            ->get() as $row) {
            $map[$row->wh_code.'|'.$row->wl_code] = $row->id;
        }

        return $map;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function upsertChunked(string $table, array $rows, array $uniqueBy, array $update): int
    {
        $total = 0;
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $update);
            $total += count($chunk);
        }

        Log::info("MasterDataEtlService: upserted {$total} rows into {$table}");

        return $total;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function insertChunked(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
