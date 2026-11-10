<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. ตารางข้อตกลงลูกค้า (Customer Terms) ตามแบบ BPlus
        if (! Schema::hasTable('customer_terms')) {
            Schema::create('customer_terms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->string('name', 255); // เช่น เงินสด, N-เงินสด (N- = no VAT), เครดิต 30 วัน
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->foreignId('price_table_id')->nullable()->constrained('price_tables')->nullOnDelete();
                $table->integer('credit_days')->default(0);
                $table->decimal('trade_discount_percent', 8, 4)->default(0);
                $table->decimal('cash_discount_percent', 8, 4)->default(0);
                $table->boolean('is_default')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'is_default']);
            });
        }

        // 2. ตารางข้อตกลงผู้ขาย/ซัพพลายเออร์ (Supplier Terms) ตามแบบ BPlus
        if (! Schema::hasTable('supplier_terms')) {
            Schema::create('supplier_terms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->string('name', 255); // เช่น เงินสด, N-เงินสด, เครดิต 30 วัน
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->foreignId('price_table_id')->nullable()->constrained('price_tables')->nullOnDelete();
                $table->integer('credit_days')->default(0);
                $table->decimal('trade_discount_percent', 8, 4)->default(0);
                $table->decimal('cash_discount_percent', 8, 4)->default(0);
                $table->boolean('is_default')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['supplier_id', 'is_default']);
            });
        }

        // 3. เพิ่มแบบภาษีหัก ณ ที่จ่าย (Withholding Tax Form: none / ภ.ง.ด.3 / ภ.ง.ด.53) บนตาราง suppliers
        if (! Schema::hasColumn('suppliers', 'withholding_tax_form')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->string('withholding_tax_form', 20)->default('none')->nullable()->after('tax_branch');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_terms');
        Schema::dropIfExists('supplier_terms');

        if (Schema::hasColumn('suppliers', 'withholding_tax_form')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropColumn('withholding_tax_form');
            });
        }
    }
};
