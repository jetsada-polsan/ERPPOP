<?php

namespace App\Services\MasterData;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * ลบข้อมูลหลักได้เฉพาะตัวที่ยังไม่มีใครอ้าง ถ้ามีประวัติให้ปิดใช้งานแทน
 * ตรวจทุกตารางที่มีคอลัมน์อ้างถึง ไม่พึ่ง foreign key เพราะตารางเก่าบางตัวไม่มี
 */
final class SafeDelete
{
    /** ชนิดที่อนุญาต => [ตาราง, คอลัมน์ที่ตารางอื่นใช้อ้าง, สิทธิ์] */
    public const TYPES = [
        'customers' => ['customers', ['customer_id'], 'sales.manage'],
        'suppliers' => ['suppliers', ['supplier_id'], 'masterdata.manage'],
        'product-units' => ['product_units', ['unit_id', 'base_unit_id', 'matched_unit_id'], 'masterdata.manage'],
        'promotions' => ['promotions', ['promotion_id'], 'masterdata.manage'],
        'qty-promotions' => ['qty_promotions', ['qty_promotion_id'], 'masterdata.manage'],
        'price-tables' => ['price_tables', ['price_table_id'], 'masterdata.manage'],
        'bank-accounts' => ['bank_accounts', ['bank_account_id'], 'finance.manage'],
        'chart-of-accounts' => ['chart_of_accounts', ['account_id', 'chart_of_account_id', 'gl_account_id', 'expense_account_id'], 'finance.manage'],
        'document-books' => ['document_books', ['document_book_id'], 'settings.manage'],
        'warehouse-locations' => ['warehouse_locations', ['warehouse_location_id', 'from_warehouse_location_id', 'to_warehouse_location_id', 'default_warehouse_location_id'], 'masterdata.manage'],
        'discount-cards' => ['discount_cards', ['discount_card_id'], 'masterdata.manage'],
        'member-points' => ['member_point_rules', ['member_point_rule_id'], 'masterdata.manage'],
        'product-categories' => ['product_categories', ['product_category_id'], 'masterdata.manage'],
        'product-departments' => ['product_departments', ['product_department_id'], 'masterdata.manage'],
        'product-brands' => ['product_brands', ['product_brand_id'], 'masterdata.manage'],
        'sales-areas' => ['sales_areas', ['sales_area_id'], 'masterdata.manage'],
        'member-types' => ['member_types', ['member_type_id'], 'masterdata.manage'],
        'branches' => ['branches', ['branch_id'], 'masterdata.manage'],
        'warehouses' => ['warehouses', ['warehouse_id'], 'masterdata.manage'],
    ];

    /** @return array<int, string> ตารางที่ยังอ้างแถวนี้อยู่ */
    public function references(string $type, int $id): array
    {
        [$table, $columns] = self::TYPES[$type];
        $found = [];
        foreach (Schema::getTableListing() as $other) {
            $other = str_contains($other, '.') ? substr($other, strrpos($other, '.') + 1) : $other;
            if ($other === $table) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($other, $column) && DB::table($other)->where($column, $id)->exists()) {
                    $found[] = $other;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @return string 'deleted' หรือ 'deactivated' (มีประวัติ จึงปิดใช้งานแทน) */
    public function delete(string $type, int $id, ?int $userId): string
    {
        [$table] = self::TYPES[$type] ?? throw new RuntimeException('ลบข้อมูลชนิดนี้ไม่ได้');
        $references = $this->references($type, $id);
        if ($references) {
            if (! Schema::hasColumn($table, 'is_active')) {
                throw new RuntimeException('มีประวัติใช้งานแล้ว ('.implode(', ', array_slice($references, 0, 3)).') จึงลบไม่ได้');
            }
            DB::transaction(function () use ($table, $id, $userId) {
                $row = DB::table($table)->where('id', $id)->first();
                if ($row) {
                    DB::table($table)->where('id', $id)->update(['is_active' => false]);
                    AuditLog::create([
                        'user_id' => $userId,
                        'action' => 'master_deactivated',
                        'table_name' => $table,
                        'record_id' => $id,
                        'old_values' => (array) $row,
                        'new_values' => ['is_active' => false],
                    ]);
                }
            });

            return 'deactivated';
        }
        DB::transaction(function () use ($table, $id, $userId) {
            $row = DB::table($table)->where('id', $id)->first();
            if (! $row) {
                throw new RuntimeException('ไม่พบข้อมูล');
            }
            DB::table($table)->where('id', $id)->delete();
            AuditLog::create(['user_id' => $userId, 'action' => 'master_deleted', 'table_name' => $table, 'record_id' => $id, 'old_values' => (array) $row]);
        });

        return 'deleted';
    }
}
