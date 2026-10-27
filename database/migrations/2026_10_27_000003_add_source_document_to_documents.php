<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// เอกสารที่อ้างเอกสารต้นทาง (เริ่มจากใบรับคืน -> ใบขายสด/ขายเชื่อ) เก็บเป็น FK จริง
// ไม่ใช้ documents.reference เพราะ SaleReturnService::approve() ตีความ reference
// ว่าเป็นเลขใบขายเชื่อที่ต้องมีลูกหนี้ ใบขายสดจะอนุมัติไม่ได้ถ้าใช้ช่องเดียวกัน
return new class extends Migration
{
    public function up(): void
    {
        // SQLite rebuilds the table to add a foreign key and refuses while a view
        // still references it - keep sales_postings around the ALTER like
        // 2026_09_19_000001_add_document_approval_lifecycle does.
        $view = $this->salesPostingsViewDefinition();
        if ($view !== null) {
            DB::statement('DROP VIEW sales_postings');
        }
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('source_document_id')->nullable()->after('reference')
                ->constrained('documents')->nullOnDelete();
        });
        if ($view !== null) {
            DB::statement($view);
        }
    }

    public function down(): void
    {
        $view = $this->salesPostingsViewDefinition();
        if ($view !== null) {
            DB::statement('DROP VIEW sales_postings');
        }
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_document_id');
        });
        if ($view !== null) {
            DB::statement($view);
        }
    }

    private function salesPostingsViewDefinition(): ?string
    {
        if (! Schema::hasTable('documents')) {
            return null;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $sql = DB::table('sqlite_master')->where('type', 'view')->where('name', 'sales_postings')->value('sql');
        } else {
            $sql = DB::selectOne("select definition from pg_views where schemaname = 'public' and viewname = 'sales_postings'")?->definition;
            $sql = $sql ? 'CREATE VIEW sales_postings AS '.$sql : null;
        }

        return $sql ?: null;
    }
};
