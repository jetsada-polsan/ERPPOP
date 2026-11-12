<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_tables', function (Blueprint $table) {
            // ตาราง BPlus ต้นทาง (ARPRICETAB.ARPRB_KEY) ใช้แมปตอนนำราคาเข้า แทนการเดาจากรหัส
            $table->string('bplus_key', 20)->nullable();
            // ราคาในตารางรวม VAT แล้วหรือยัง (BPlus ตาราง 1 = ไม่รวม) ว่าง = ไม่ระบุ (ถือว่ารวม)
            $table->boolean('vat_included')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('price_tables', function (Blueprint $table) {
            $table->dropColumn(['bplus_key', 'vat_included']);
        });
    }
};
