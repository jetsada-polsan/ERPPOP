<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** เก็บ hash ของเนื้อหาเอกสาร BPlus ที่สร้างเป็นเอกสาร ERP แล้ว รอบถัดไปข้ามใบที่ไม่เปลี่ยน */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bplus_sync_documents', 'materialized_hash')) {
            Schema::table('bplus_sync_documents', function (Blueprint $table) {
                $table->string('materialized_hash', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bplus_sync_documents', 'materialized_hash')) {
            Schema::table('bplus_sync_documents', function (Blueprint $table) {
                $table->dropColumn('materialized_hash');
            });
        }
    }
};
