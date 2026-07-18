<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Warehouse daily documents from the BPlus manual ch.5 (คลังสินค้า):
// ใบคืนสินค้าจากการเบิก (IR) and ใบแปรรูปสินค้า (DT). ใบเบิก (DR) and
// ใบตัดชำรุด (DD) types already exist from the initial seed.
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['code' => 'STOCK_REQUISITION_RETURN', 'name_th' => 'ใบคืนสินค้าจากการเบิก'],
            ['code' => 'STOCK_TRANSFORM', 'name_th' => 'ใบแปรรูปสินค้า'],
        ] as $type) {
            $exists = DB::table('document_types')->where('code', $type['code'])->exists();
            if (! $exists) {
                DB::table('document_types')->insert($type);
            }
        }
    }

    public function down(): void
    {
        DB::table('document_types')->whereIn('code', ['STOCK_REQUISITION_RETURN', 'STOCK_TRANSFORM'])->delete();
    }
};
