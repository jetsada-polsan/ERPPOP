<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// สำเนาเอกสารจาก BPlus (ใบจอง / ใบขายเชื่อ / ใบเสร็จ) ไว้ดูและดูยอดขาย
// แยกจากเอกสาร ERP: ไม่ตัดสต๊อก ไม่ลง GL ไม่สร้างลูกหนี้
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bplus_sync_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bplus_key')->unique();
            $table->string('kind', 20); // booking | credit_sale | receipt
            $table->string('route_code', 20)->index();
            $table->string('book_code', 20);
            $table->string('ref', 40);
            $table->date('doc_date')->index();
            $table->boolean('is_cancelled')->default(false);
            $table->string('customer_code', 40)->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('created_by', 60)->nullable();
            $table->string('booking_ref', 40)->nullable()->index();
            $table->decimal('vatable_amount', 15, 2)->default(0);
            $table->decimal('exempt_amount', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->decimal('cash_amount', 15, 2)->default(0);
            $table->decimal('transfer_amount', 15, 2)->default(0);
            $table->decimal('other_amount', 15, 2)->default(0);
            $table->decimal('short_amount', 15, 2)->default(0);
            $table->timestamp('bplus_updated_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'doc_date']);
        });

        Schema::create('bplus_sync_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bplus_sync_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('sku_code', 40)->nullable();
            $table->string('barcode', 40)->nullable();
            $table->string('unit_name', 60)->nullable();
            $table->decimal('unit_factor', 15, 4)->default(1);
            $table->decimal('qty', 15, 4)->default(0);
            $table->decimal('free_qty', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->boolean('has_vat')->default(true);
        });

        Schema::create('bplus_sync_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bplus_sync_document_id')->constrained()->cascadeOnDelete();
            $table->string('sale_ref', 40)->index();
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('cash_amount', 15, 2)->default(0);
            $table->decimal('transfer_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('wht_amount', 15, 2)->default(0);
            $table->decimal('short_amount', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bplus_sync_allocations');
        Schema::dropIfExists('bplus_sync_lines');
        Schema::dropIfExists('bplus_sync_documents');
    }
};
