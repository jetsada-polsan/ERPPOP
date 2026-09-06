<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// เกณฑ์เติมสินค้าต่อสาขา (Min/Max ต่อสินค้า x สาขา) แยกจาก products.minimum_stock/
// maximum_stock ซึ่งเป็นค่ากลางใช้กับงานจัดซื้อจาก supplier - สาขาขายไม่เท่ากัน
// เกณฑ์เติมระหว่างสาขาจึงต้องตั้งแยกได้ต่อสาขา ถ้าสาขาไหนไม่ได้ตั้งไว้ (ไม่มีแถวนี้)
// BranchReplenishmentService จะ fallback ไปใช้ค่าที่ระดับสินค้าแทน
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_stock_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('minimum_stock', 20, 8)->nullable();
            $table->decimal('maximum_stock', 20, 8)->nullable();
            $table->decimal('reorder_point', 20, 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_stock_policies');
    }
};
