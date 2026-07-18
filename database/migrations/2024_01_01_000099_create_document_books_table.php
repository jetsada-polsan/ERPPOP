<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// สมุดเอกสาร (Document Books) แบบ BPlus: เอกสารประเภทเดียว (เช่น ใบขายเชื่อ) แยก
// ได้หลายเล่ม (DS, DSN) แต่ละเล่มมีเลขรันของตัวเอง จัดกลุ่มในทะเบียนเอกสารได้
// seed เล่มเริ่มต้น 1 เล่มต่อประเภทให้ตรง prefix เดิม (ไม่กระทบเลขที่มีอยู่)
return new class extends Migration
{
    private const DEFAULT_BOOKS = [
        'CREDIT_SALE' => ['DS', 'ใบขายเชื่อ (เล่มหลัก)'],
        'CASH_SALE' => ['CS', 'ใบขายสด'],
        'SALE_RETURN' => ['CN', 'ใบรับคืนสินค้า'],
        'PURCHASE' => ['PO', 'ใบซื้อ'],
        'BOOKING' => ['BK', 'ใบจอง'],
    ];

    public function up(): void
    {
        Schema::create('document_books', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->foreignId('document_type_id')->constrained('document_types')->cascadeOnDelete();
            $table->string('prefix', 10);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['document_type_id', 'code']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('document_book_id')->nullable()->after('document_type_id')
                ->constrained('document_books')->nullOnDelete();
        });

        foreach (self::DEFAULT_BOOKS as $typeCode => [$prefix, $name]) {
            $typeId = DB::table('document_types')->where('code', $typeCode)->value('id');
            if ($typeId) {
                DB::table('document_books')->insert([
                    'code' => $prefix, 'name' => $name, 'document_type_id' => $typeId,
                    'prefix' => $prefix, 'is_default' => true, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // เพิ่มเล่มที่ 2 ของใบขายเชื่อ (DSN) เหมือน BPlus
        $creditSaleId = DB::table('document_types')->where('code', 'CREDIT_SALE')->value('id');
        if ($creditSaleId) {
            DB::table('document_books')->insert([
                'code' => 'DSN', 'name' => 'ใบขายเชื่อ (เล่ม N)', 'document_type_id' => $creditSaleId,
                'prefix' => 'DSN', 'is_default' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('document_book_id'));
        Schema::dropIfExists('document_books');
    }
};
