<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. บัญชีภาษีเงินได้ถูกหัก ณ ที่จ่าย (wht_receivable) ฝั่งลูกหนี้ เป็นสินทรัพย์
        if (! DB::table('chart_of_accounts')->where('default_role', 'wht_receivable')->exists()) {
            // ผูกกับบัญชีสินทรัพย์ที่ชื่อบอกชัดว่าเป็นภาษีที่ถูกหักไว้เท่านั้น ห้ามเดาจากรหัสบัญชี
            // (รหัส 1160 ของผังบัญชีจริงอาจเป็นบัญชีอื่น)
            $existing = DB::table('chart_of_accounts')
                ->where('account_type', 'asset')
                ->where(function ($q) {
                    $q->where('name_th', 'like', '%ถูกหัก ณ ที่จ่าย%')
                      ->orWhere('name_th', 'like', '%ภาษีเงินได้หัก ณ ที่จ่าย%')
                      ->orWhere('name_th', 'like', '%ภาษีหัก ณ ที่จ่ายรอ%');
                })
                ->orderBy('code')
                ->first();

            if ($existing) {
                DB::table('chart_of_accounts')
                    ->where('id', $existing->id)
                    ->update(['default_role' => 'wht_receivable']);
            } else {
                // ใช้รหัส 1160 ถ้ายังว่าง ไม่งั้นหารหัสว่างถัดไปในช่วง 1161-1199 ห้ามชนรหัสเดิม
                $code = '1160';
                for ($n = 1160; $n <= 1199 && DB::table('chart_of_accounts')->where('code', (string) $n)->exists(); $n++) {
                    $code = (string) ($n + 1);
                }
                if (DB::table('chart_of_accounts')->where('code', $code)->exists()) {
                    throw new \RuntimeException('หารหัสบัญชีว่างสำหรับภาษีเงินได้ถูกหัก ณ ที่จ่ายไม่ได้ (1160-1199 ถูกใช้หมด)');
                }
                DB::table('chart_of_accounts')->insert([
                    'code' => $code,
                    'name_th' => 'ภาษีเงินได้ถูกหัก ณ ที่จ่าย',
                    'name_en' => 'Withholding tax receivable',
                    'account_type' => 'asset',
                    'default_role' => 'wht_receivable',
                ]);
            }
        }

        // 2. ช่องเก็บเลขที่และวันที่หนังสือรับรองหัก ณ ที่จ่ายบน payment_documents
        Schema::table('payment_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_documents', 'wht_cert_no')) {
                $table->string('wht_cert_no', 60)->nullable()->after('status');
            }
            if (! Schema::hasColumn('payment_documents', 'wht_cert_date')) {
                $table->date('wht_cert_date')->nullable()->after('wht_cert_no');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_documents', function (Blueprint $table) {
            if (Schema::hasColumn('payment_documents', 'wht_cert_date')) {
                $table->dropColumn('wht_cert_date');
            }
            if (Schema::hasColumn('payment_documents', 'wht_cert_no')) {
                $table->dropColumn('wht_cert_no');
            }
        });

        // ลบเฉพาะบัญชีที่ migration นี้สร้างเอง (ชื่อตรงตัวและไม่มีรายการบัญชีอ้างอิง)
        $created = DB::table('chart_of_accounts')->where('default_role', 'wht_receivable')
            ->where('name_th', 'ภาษีเงินได้ถูกหัก ณ ที่จ่าย')->where('name_en', 'Withholding tax receivable')->first();
        if ($created && ! DB::table('gl_journals')->where('account_id', $created->id)->exists()) {
            DB::table('chart_of_accounts')->where('id', $created->id)->delete();
        }

        DB::table('chart_of_accounts')
            ->where('default_role', 'wht_receivable')
            ->update(['default_role' => null]);
    }
};
