<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * index ของ foreign key ในตารางธุรกรรมที่ยังไม่มี index (PostgreSQL ไม่สร้างให้เอง)
 * เช่น stock_movements.document_id, customer_ledger.document_id, pos_receipts.document_id
 * หน้าเอกสาร รายงาน และการยกเลิกบิลต้องค้นตามคอลัมน์เหล่านี้ เดิม PostgreSQL ต้องอ่านทั้งตาราง
 * รายการมาจากการไล่ foreign key ทุกตัวที่ชี้ไปตารางธุรกรรม/สินค้า/ลูกค้า แล้วไม่มี index ที่ขึ้นต้นด้วยคอลัมน์นั้น
 */
return new class extends Migration
{
    private const INDEXES = [
        'customer_ledger' => ['document_id'],
        'supplier_addresses' => ['supplier_id'],
        'supplier_ledger' => ['document_id'],
        'payment_documents' => ['supplier_id', 'customer_id'],
        'payment_lines' => ['payment_document_id'],
        'pos_receipt_discounts' => ['pos_receipt_id'],
        'pos_coupons' => ['pos_receipt_id'],
        'imported_receipts' => ['posted_pos_receipt_id'],
        'flash_sale_items' => ['product_id'],
        'discount_cards' => ['member_id'],
        'cheques' => ['payment_document_id', 'supplier_id', 'customer_id'],
        'billing_note_items' => ['customer_open_item_id'],
        'gl_journals' => ['payment_document_id'],
        'purchase_orders' => ['received_document_id', 'supplier_id'],
        'pos_receipts' => ['document_id', 'member_id'],
        'pos_receipt_returns' => ['document_id'],
        'stock_counts' => ['posted_document_id'],
        'recall_cases' => ['stock_lot_id'],
        'ecommerce_orders' => ['document_id'],
        'ecommerce_order_items' => ['product_id'],
        'stock_lots' => ['source_document_id', 'source_lot_id'],
        'stock_movements' => ['document_id'],
        'stock_document_items' => ['source_stock_lot_id'],
        'inventory_cost_closes' => ['product_id'],
        'production_batches' => ['output_product_id'],
        'stock_lot_lineages' => ['input_lot_id', 'document_id'],
        'production_recipes' => ['finished_product_id'],
        'production_recipe_items' => ['product_id'],
        'production_order_items' => ['product_id'],
        'purchase_order_items' => ['product_id'],
        'quotation_items' => ['product_id'],
        'stock_count_items' => ['product_id'],
        'pos_receipt_return_items' => ['pos_receipt_return_id', 'pos_receipt_item_id'],
        'purchase_plans' => ['product_id', 'supplier_id'],
        'product_suppliers' => ['supplier_id'],
        'promotions' => ['product_id'],
        'recall_contacts' => ['document_id', 'customer_id'],
        'imported_receipt_items' => ['product_id'],
        'supplier_price_schedules' => ['supplier_id'],
        'pos_held_bills' => ['customer_id', 'pos_shift_id'],
        'cash_books' => ['pos_shift_id'],
        'purchase_quotes' => ['supplier_id'],
        'ocr_documents' => ['posted_document_id'],
        'supplier_product_mappings' => ['product_id'],
        'branch_stock_policies' => ['product_id'],
        'stock_transfer_receipt_items' => ['product_id'],
        'production_orders' => ['finished_product_id'],
        'purchase_cost_adjustments' => ['product_id', 'stock_lot_id', 'document_id'],
        'transport_jobs' => ['payment_document_id'],
        'member_point_transactions' => ['document_id'],
        'supplier_notes' => ['supplier_open_item_id'],
        'product_scan_logs' => ['product_id'],
        'pos_offline_sales' => ['pos_receipt_id'],
        'payment_allocations' => ['supplier_open_item_id'],
        'qty_promotions' => ['free_product_id', 'product_id'],
        'qty_promotion_products' => ['product_id'],
        'scheduled_price_changes' => ['product_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                $name = "{$table}_{$column}_lookup_idx";
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column) && ! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($column, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                $name = "{$table}_{$column}_lookup_idx";
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
