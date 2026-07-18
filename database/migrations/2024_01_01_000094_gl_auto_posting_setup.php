<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// GL อัตโนมัติ: (1) ให้ gl_journals ผูกกับเอกสารใดก็ได้ (document_id) ไม่ใช่แค่
// payment_document เดิม (2) seed ผังบัญชีมาตรฐานพร้อม default_role ครบทุกบัญชี
// ที่ระบบต้องใช้ลงบัญชีขาย/ซื้อ/ปรับหนี้อัตโนมัติ (ทำเฉพาะตอนผังบัญชียังว่าง)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_journals', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('payment_document_id')
                ->constrained('documents')->nullOnDelete();
            $table->foreignId('payment_document_id')->nullable()->change();
        });

        if (DB::table('chart_of_accounts')->count() === 0) {
            DB::table('chart_of_accounts')->insert([
                ['code' => '1010', 'name_th' => 'เงินสด', 'name_en' => 'Cash', 'account_type' => 'asset', 'default_role' => 'cash'],
                ['code' => '1020', 'name_th' => 'เงินฝากธนาคาร', 'name_en' => 'Bank Deposits', 'account_type' => 'asset', 'default_role' => null],
                ['code' => '1030', 'name_th' => 'ลูกหนี้การค้า', 'name_en' => 'Accounts Receivable', 'account_type' => 'asset', 'default_role' => 'ar'],
                ['code' => '1060', 'name_th' => 'สินค้าคงเหลือ', 'name_en' => 'Inventory', 'account_type' => 'asset', 'default_role' => 'inventory'],
                ['code' => '1150', 'name_th' => 'ภาษีซื้อ', 'name_en' => 'Input VAT', 'account_type' => 'asset', 'default_role' => 'vat_input'],
                ['code' => '2010', 'name_th' => 'เจ้าหนี้การค้า', 'name_en' => 'Accounts Payable', 'account_type' => 'liability', 'default_role' => 'ap'],
                ['code' => '2150', 'name_th' => 'ภาษีขาย', 'name_en' => 'Output VAT', 'account_type' => 'liability', 'default_role' => 'vat_output'],
                ['code' => '3010', 'name_th' => 'ทุนจดทะเบียน', 'name_en' => 'Capital', 'account_type' => 'equity', 'default_role' => null],
                ['code' => '3020', 'name_th' => 'กำไรสะสม', 'name_en' => 'Retained Earnings', 'account_type' => 'equity', 'default_role' => 'retained_earnings'],
                ['code' => '4010', 'name_th' => 'รายได้จากการขาย', 'name_en' => 'Sales Revenue', 'account_type' => 'revenue', 'default_role' => 'sales_revenue'],
                ['code' => '4020', 'name_th' => 'รับคืนและส่วนลดจ่าย', 'name_en' => 'Sales Returns & Allowances', 'account_type' => 'revenue', 'default_role' => 'sales_return'],
                ['code' => '5010', 'name_th' => 'ต้นทุนขาย', 'name_en' => 'Cost of Goods Sold', 'account_type' => 'expense', 'default_role' => 'cogs'],
                ['code' => '5020', 'name_th' => 'ค่าใช้จ่ายในการขายและบริหาร', 'name_en' => 'Selling & Admin Expenses', 'account_type' => 'expense', 'default_role' => 'expense'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('gl_journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
