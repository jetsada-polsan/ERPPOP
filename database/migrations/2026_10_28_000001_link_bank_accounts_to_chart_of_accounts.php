<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// บัญชีธนาคารแต่ละบัญชีผูกกับบัญชีในผัง GL ได้ (เช่น 1020-01 ของสาขาอำนาจเจริญ)
// เงินโอน/QR/เช็คของสาขานั้นจะลง GL บัญชีนี้ ไม่ต้องรวมกันใน 1020 ตัวเดียว
// ว่างไว้ได้ = ยังใช้บัญชีธนาคารกลาง (role bank) เหมือนเดิม จึงไม่เปลี่ยนอะไรจนกว่าจะตั้ง
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('chart_of_account_id')->nullable()->after('account_name')
                ->constrained('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_of_account_id');
        });
    }
};
