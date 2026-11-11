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
            $existing = DB::table('chart_of_accounts')
                ->where('account_type', 'asset')
                ->where(function ($q) {
                    $q->where('code', '1160')
                      ->orWhere('name_th', 'like', '%หัก ณ ที่จ่าย%');
                })
                ->first();

            if ($existing) {
                DB::table('chart_of_accounts')
                    ->where('id', $existing->id)
                    ->update(['default_role' => 'wht_receivable']);
            } else {
                DB::table('chart_of_accounts')->insert([
                    'code' => '1160',
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

        DB::table('chart_of_accounts')
            ->where('default_role', 'wht_receivable')
            ->where('code', '1160')
            ->delete();

        DB::table('chart_of_accounts')
            ->where('default_role', 'wht_receivable')
            ->update(['default_role' => null]);
    }
};
