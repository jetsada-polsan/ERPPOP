<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id(); $table->string('document_type_code', 60)->unique(); $table->string('name_th', 150);
            $table->string('mode', 20)->default('fast'); $table->string('approval_permission', 80)->nullable(); $table->boolean('is_active')->default(true);
            $table->json('steps')->nullable(); $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        foreach ([
            ['STOCK_TRANSFER','ใบขอโอนสินค้า','approval','stock.manage',['ผู้ขอ','ผู้จัดการสาขา','ผู้มีสิทธิ์คลัง','ส่งของ','รับโอน']],
            ['STOCK_DAMAGE','ใบสินค้าสูญเสีย/ชำรุด','approval','stock.damage.approve',['ผู้ทำรายการ','ผู้จัดการสาขา','ผู้มีสิทธิ์คลัง']],
            ['STOCK_ADJUSTMENT','ใบปรับสต๊อก','approval','stock.adjust.approve',['ผู้ทำรายการ','ผู้จัดการสาขา','ผู้มีสิทธิ์คลัง']],
            ['RECEIPT','ใบรับสินค้า','fast',null,['ผู้รับสินค้า','ตรวจรับ']], ['BOOKING','ใบจองสินค้า','fast',null,['พนักงานขาย','ยืนยันจอง']],
            ['CREDIT_SALE','ใบขายเชื่อ','fast',null,['พนักงานขาย','ตรวจวงเงินอัตโนมัติ']], ['CASH_SALE','ใบขายสด/POS','fast',null,['แคชเชียร์','ชำระเงิน']],
        ] as [$code,$name,$mode,$permission,$steps]) DB::table('workflow_definitions')->insert(['document_type_code'=>$code,'name_th'=>$name,'mode'=>$mode,'approval_permission'=>$permission,'steps'=>json_encode($steps,JSON_UNESCAPED_UNICODE),'created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void { Schema::dropIfExists('workflow_definitions'); }
};
