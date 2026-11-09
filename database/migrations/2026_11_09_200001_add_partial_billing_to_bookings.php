<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ออกใบขายจากใบจองแบบแก้ได้ (แบบ BPlus "อ่านจาก"):
// - billed_qty: จำนวนที่ออกบิลไปแล้วต่อบรรทัดใบจอง ส่วนที่เหลือคือ "ค้างส่ง"
// - remainder_closed_at: ผู้จัดการปิดยอดค้าง คืนสต๊อกที่กันไว้
// - sales.price_below_cost: สิทธิ์บันทึกราคาขายต่ำกว่าทุน
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->decimal('billed_qty', 18, 4)->default(0);
        });
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->timestamp('remainder_closed_at')->nullable();
            $table->foreignId('remainder_closed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // ใบจองที่แปลงเป็นใบขายไปแล้วก่อนมีระบบนี้ = ออกบิลครบทุกบรรทัด
        $convertedStockDocs = DB::table('sale_bookings')
            ->join('stock_documents', 'stock_documents.document_id', '=', 'sale_bookings.document_id')
            ->where('sale_bookings.status', 'converted_to_sale')
            ->pluck('stock_documents.id');
        foreach ($convertedStockDocs->chunk(500) as $chunk) {
            DB::table('stock_document_items')->whereIn('stock_document_id', $chunk->all())->update(['billed_qty' => DB::raw('qty')]);
        }

        $permissionId = DB::table('permissions')->where('code', 'sales.price_below_cost')->value('id')
            ?: DB::table('permissions')->insertGetId(['code' => 'sales.price_below_cost', 'name' => 'อนุมัติราคาขายต่ำกว่าทุน']);
        foreach (DB::table('roles')->whereIn('code', ['GM', 'BRANCH_MGR', 'IT_MGR'])->pluck('id') as $roleId) {
            DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', 'sales.price_below_cost')->value('id');
        DB::table('permission_role')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remainder_closed_by');
            $table->dropColumn('remainder_closed_at');
        });
        Schema::table('stock_document_items', function (Blueprint $table) {
            $table->dropColumn('billed_qty');
        });
    }
};
