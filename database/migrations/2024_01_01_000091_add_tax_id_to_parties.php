<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// เลขประจำตัวผู้เสียภาษี + รหัสสาขา (00000 = สำนักงานใหญ่) ของคู่ค้า
// จำเป็นสำหรับรายงานภาษีซื้อ-ภาษีขายแบบยื่นสรรพากร (ภพ.30)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tax_id', 20)->nullable()->after('name_en');
            $table->string('tax_branch', 10)->nullable()->after('tax_id');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('tax_id', 20)->nullable()->after('name_en');
            $table->string('tax_branch', 10)->nullable()->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['tax_id', 'tax_branch']));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn(['tax_id', 'tax_branch']));
    }
};
