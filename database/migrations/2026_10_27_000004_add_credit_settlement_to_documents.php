<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ใบลดหนี้ที่อ้างใบขายซึ่งชำระไปแล้ว (บางส่วนหรือทั้งหมด): ส่วนที่เกินยอดค้าง
// จะเป็น "credit" = เครดิตไว้หักใบถัดไป หรือ "refund" = คืนเงินสดให้ลูกค้า
return new class extends Migration
{
    public function up(): void
    {
        // SQLite rebuilds the table and refuses while sales_postings references it.
        $view = $this->salesPostingsViewDefinition();
        if ($view !== null) {
            DB::statement('DROP VIEW sales_postings');
        }
        Schema::table('documents', function (Blueprint $table) {
            $table->string('credit_settlement', 20)->nullable()->after('source_document_id');
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
            $table->dropColumn('credit_settlement');
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
