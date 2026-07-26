<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabaseStructureService
{
    private const MODULES = [
        'master' => ['name' => 'สินค้าและข้อมูลตั้งต้น', 'icon' => 'bi-box-seam-fill', 'tone' => 'blue'],
        'inventory' => ['name' => 'คลังและคุณภาพสินค้า', 'icon' => 'bi-boxes', 'tone' => 'teal'],
        'sales' => ['name' => 'ขาย ลูกค้า และ POS', 'icon' => 'bi-receipt-cutoff', 'tone' => 'cyan'],
        'purchasing' => ['name' => 'จัดซื้อและผู้จำหน่าย', 'icon' => 'bi-basket-fill', 'tone' => 'amber'],
        'finance' => ['name' => 'การเงิน บัญชี และภาษี', 'icon' => 'bi-calculator-fill', 'tone' => 'red'],
        'marketing' => ['name' => 'สมาชิก ราคา และโปรโมชั่น', 'icon' => 'bi-tags-fill', 'tone' => 'orange'],
        'production' => ['name' => 'ผลิตและแปรรูป', 'icon' => 'bi-gear-wide-connected', 'tone' => 'indigo'],
        'people' => ['name' => 'องค์กรและพนักงาน', 'icon' => 'bi-people-fill', 'tone' => 'pink'],
        'integration' => ['name' => 'เชื่อมต่อและนำเข้าข้อมูล', 'icon' => 'bi-arrow-left-right', 'tone' => 'slate'],
        'system' => ['name' => 'ระบบ สิทธิ์ และงานเบื้องหลัง', 'icon' => 'bi-shield-lock-fill', 'tone' => 'brown'],
    ];

    public function catalog(): array
    {
        $cacheKey = 'database-structure:v2:'.config('database.default').':'.$this->databaseLabel();

        return Cache::remember($cacheKey, now()->addMinutes(15), function (): array {
            $tables = collect(Schema::getTables())
                ->reject(fn (array $table) => in_array($table['name'], ['sqlite_sequence'], true))
                ->mapWithKeys(function (array $table): array {
                    $name = $table['name'];
                    $moduleKey = $this->moduleFor($name);
                    $columns = $this->safeSchemaCall(fn () => Schema::getColumns($name));
                    $indexes = $this->safeSchemaCall(fn () => Schema::getIndexes($name));
                    $foreignKeys = $this->safeSchemaCall(fn () => Schema::getForeignKeys($name));
                    $primaryColumns = collect($indexes)->where('primary', true)->pluck('columns')->flatten()->all();
                    $uniqueColumns = collect($indexes)->where('unique', true)->pluck('columns')->flatten()->all();
                    $foreignByColumn = collect($foreignKeys)
                        ->flatMap(fn (array $foreign) => collect($foreign['columns'] ?? [])->mapWithKeys(fn (string $column) => [$column => $foreign]))
                        ->all();

                    $columns = collect($columns)->map(function (array $column) use ($primaryColumns, $uniqueColumns, $foreignByColumn): array {
                        $columnName = $column['name'];

                        return [
                            ...$column,
                            'is_primary' => in_array($columnName, $primaryColumns, true),
                            'is_unique' => in_array($columnName, $uniqueColumns, true),
                            'foreign' => $foreignByColumn[$columnName] ?? null,
                            'meaning' => $this->columnMeaning($columnName),
                        ];
                    })->values()->all();

                    return [$name => [
                        'name' => $name,
                        'label' => $this->tableLabel($name),
                        'module_key' => $moduleKey,
                        'module' => self::MODULES[$moduleKey],
                        'size' => $table['size'] ?? null,
                        'comment' => $table['comment'] ?? null,
                        'columns' => $columns,
                        'indexes' => array_values($indexes),
                        'foreign_keys' => array_values($foreignKeys),
                        'referenced_by' => [],
                    ]];
                })
                ->sortKeys()
                ->all();

            foreach ($tables as $sourceTable => $table) {
                foreach ($table['foreign_keys'] as $foreign) {
                    $target = $foreign['foreign_table'] ?? null;
                    if (! $target || ! isset($tables[$target])) {
                        continue;
                    }
                    $tables[$target]['referenced_by'][] = [
                        'table' => $sourceTable,
                        'columns' => $foreign['columns'] ?? [],
                        'foreign_columns' => $foreign['foreign_columns'] ?? [],
                        'on_delete' => $foreign['on_delete'] ?? null,
                    ];
                }
            }

            $modules = collect(self::MODULES)->map(function (array $module, string $key) use ($tables): array {
                return [
                    'key' => $key,
                    ...$module,
                    'table_count' => collect($tables)->where('module_key', $key)->count(),
                ];
            })->filter(fn (array $module) => $module['table_count'] > 0)->values()->all();

            return [
                'driver' => DB::getDriverName(),
                'database' => $this->databaseLabel(),
                'tables' => $tables,
                'modules' => $modules,
                'summary' => [
                    'tables' => count($tables),
                    'columns' => collect($tables)->sum(fn (array $table) => count($table['columns'])),
                    'relations' => collect($tables)->sum(fn (array $table) => count($table['foreign_keys'])),
                    'indexes' => collect($tables)->sum(fn (array $table) => count($table['indexes'])),
                ],
            ];
        });
    }

    public function rowEstimate(string $table): ?int
    {
        if (! isset($this->catalog()['tables'][$table])) {
            return null;
        }

        try {
            return match (DB::getDriverName()) {
                'pgsql' => (int) (DB::selectOne(
                    'select greatest(c.reltuples, 0)::bigint as estimate from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = current_schema() and c.relname = ?',
                    [$table]
                )?->estimate ?? 0),
                'mysql' => (int) (DB::selectOne(
                    'select table_rows as estimate from information_schema.tables where table_schema = database() and table_name = ?',
                    [$table]
                )?->estimate ?? 0),
                default => (int) DB::table($table)->count(),
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function safeSchemaCall(callable $callback): array
    {
        try {
            return $callback();
        } catch (Throwable) {
            return [];
        }
    }

    private function databaseLabel(): string
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        return DB::getDriverName() === 'sqlite' ? basename($database) : $database;
    }

    private function moduleFor(string $table): string
    {
        return match (true) {
            str_starts_with($table, 'production_') => 'production',
            str_starts_with($table, 'product_'), $table === 'products' => 'master',
            str_starts_with($table, 'stock_'), str_starts_with($table, 'warehouse'),
            str_starts_with($table, 'inventory_'), str_starts_with($table, 'recall_') => 'inventory',
            str_starts_with($table, 'purchase_'), str_starts_with($table, 'supplier') => 'purchasing',
            str_starts_with($table, 'pos_'), str_starts_with($table, 'customer'),
            str_starts_with($table, 'sale_'), str_starts_with($table, 'quotation'),
            str_starts_with($table, 'billing_'), in_array($table, ['documents', 'document_books', 'document_types', 'salesmen', 'sales_areas'], true) => 'sales',
            str_starts_with($table, 'member_'), $table === 'members',
            str_starts_with($table, 'price_'), str_starts_with($table, 'promotion'),
            str_starts_with($table, 'flash_sale'), str_starts_with($table, 'discount_'),
            str_starts_with($table, 'qty_promotion') => 'marketing',
            str_starts_with($table, 'accounting_'), str_starts_with($table, 'bank_'),
            str_starts_with($table, 'payment_'), str_starts_with($table, 'payroll_'),
            str_starts_with($table, 'tax_'), str_starts_with($table, 'etax_'),
            str_starts_with($table, 'budget'), str_starts_with($table, 'fixed_asset'),
            str_starts_with($table, 'depreciation_'), str_starts_with($table, 'branch_expense'),
            in_array($table, ['chart_of_accounts', 'gl_journals', 'cash_books', 'cheques', 'cost_centers', 'vat_rates', 'approval_requests'], true) => 'finance',
            str_starts_with($table, 'employee_'), str_starts_with($table, 'organization'),
            str_starts_with($table, 'attendance_'), in_array($table, ['employees', 'branches'], true) => 'people',
            str_starts_with($table, 'import'), str_starts_with($table, 'legacy_'),
            str_starts_with($table, 'ecommerce_'), str_starts_with($table, 'line_'),
            str_starts_with($table, 'sync_'), str_starts_with($table, 'show_price'),
            $table === 'qr_payment_configs' => 'integration',
            default => 'system',
        };
    }

    private function tableLabel(string $table): string
    {
        $labels = [
            'products' => 'แฟ้มสินค้า',
            'stock_balances' => 'ยอดคงเหลือสินค้า',
            'stock_movements' => 'ประวัติการเคลื่อนไหวสต๊อก',
            'stock_lots' => 'ล็อตและวันหมดอายุ',
            'documents' => 'เอกสารซื้อขายกลาง',
            'customers' => 'แฟ้มลูกค้า',
            'suppliers' => 'แฟ้มผู้จำหน่าย',
            'pos_receipts' => 'ใบเสร็จ POS',
            'pos_receipt_items' => 'รายการสินค้าในใบเสร็จ',
            'purchase_orders' => 'ใบสั่งซื้อ',
            'purchase_order_items' => 'รายการในใบสั่งซื้อ',
            'chart_of_accounts' => 'ผังบัญชี',
            'gl_journals' => 'สมุดรายวันบัญชี',
            'users' => 'ผู้ใช้งานระบบ',
            'roles' => 'บทบาทผู้ใช้',
            'permissions' => 'สิทธิ์การใช้งาน',
            'employees' => 'แฟ้มพนักงาน',
            'branches' => 'สาขา',
            'warehouses' => 'คลังสินค้า',
            'members' => 'สมาชิก',
            'production_orders' => 'คำสั่งผลิต',
            'production_recipes' => 'สูตรการผลิต',
            'audit_logs' => 'ประวัติการเปลี่ยนแปลง',
            'app_settings' => 'ค่าตั้งระบบ',
        ];

        return $labels[$table] ?? str($table)->replace('_', ' ')->title()->toString();
    }

    private function columnMeaning(string $column): string
    {
        $meanings = [
            'id' => 'รหัสภายในของรายการ',
            'created_at' => 'วันเวลาที่สร้าง',
            'updated_at' => 'วันเวลาที่แก้ไขล่าสุด',
            'deleted_at' => 'วันเวลาที่ลบแบบเก็บประวัติ',
            'branch_id' => 'สาขาที่เป็นเจ้าของข้อมูล',
            'product_id' => 'สินค้าที่เกี่ยวข้อง',
            'user_id' => 'ผู้ใช้งานที่เกี่ยวข้อง',
            'status' => 'สถานะของรายการ',
            'is_active' => 'เปิดหรือปิดการใช้งาน',
        ];

        if (isset($meanings[$column])) {
            return $meanings[$column];
        }
        if (str_ends_with($column, '_id')) {
            return 'รหัสอ้างอิงไปยังตารางที่เกี่ยวข้อง';
        }
        if (str_ends_with($column, '_at')) {
            return 'วันเวลาเกิดเหตุการณ์';
        }
        if (str_ends_with($column, '_date') || str_ends_with($column, '_on')) {
            return 'วันที่ของรายการ';
        }
        if (str_starts_with($column, 'is_') || str_starts_with($column, 'has_')) {
            return 'ค่าใช่/ไม่ใช่สำหรับควบคุมเงื่อนไข';
        }
        if (str_contains($column, 'amount') || str_contains($column, 'price') || str_contains($column, 'cost')) {
            return 'จำนวนเงินหรือมูลค่าที่ใช้คำนวณ';
        }
        if (str_contains($column, 'qty') || str_contains($column, 'quantity')) {
            return 'จำนวนสินค้า';
        }

        return 'ข้อมูลประกอบของรายการ';
    }
}
