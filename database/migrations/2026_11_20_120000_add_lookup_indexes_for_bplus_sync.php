<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * index ของคอลัมน์ที่ตัวซิงค์ BPlus และรายงานลูกหนี้/GL ค้นทีละใบ
 * ก่อนหน้านี้ไม่มี index เลย PostgreSQL ต้องอ่านทั้งตารางทุกครั้ง (bplus_sync_lines ถูกอ่านทั้งตารางกว่าแสนรอบ)
 * PostgreSQL ไม่สร้าง index ให้ foreign key เอง
 */
return new class extends Migration
{
    private const INDEXES = [
        'bplus_sync_lines' => ['bplus_sync_document_id'],
        'bplus_sync_allocations' => ['bplus_sync_document_id'],
        'customer_open_items' => ['document_id'],
        'payment_allocations' => ['payment_document_id', 'customer_open_item_id'],
        'documents' => ['source_document_id', 'doc_number'],
        'gl_journals' => ['document_id'],
        'customer_addresses' => ['customer_id'],
        'customer_contacts' => ['customer_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            foreach ($columns as $column) {
                $name = "{$table}_{$column}_lookup_idx";
                if (Schema::hasColumn($table, $column) && ! Schema::hasIndex($table, $name)) {
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
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
