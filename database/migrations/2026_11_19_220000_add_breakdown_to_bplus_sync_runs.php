<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** แยกเอกสารที่ได้รับในแต่ละรอบเป็น ใหม่ / แก้ไข / เหมือนเดิม (รอบเก่าเป็น null = ไม่ได้นับ) */
return new class extends Migration
{
    private const COLUMNS = ['new_documents', 'changed_documents', 'unchanged_documents'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('bplus_sync_runs', $column)) {
                Schema::table('bplus_sync_runs', function (Blueprint $table) use ($column) {
                    $table->unsignedInteger($column)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('bplus_sync_runs', $column)) {
                Schema::table('bplus_sync_runs', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
