<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * รูปแบบบาร์โค้ดเครื่องชั่ง — เป็นข้อมูลที่ตั้งได้ ไม่ใช่กฎที่ฝังในโค้ด
 *
 * เดิมทั้ง ERP และ POS ต่างฝัง "ขึ้นต้น 800/801 แล้ว PLU 6 หลัก ราคา 6 หลัก" ไว้ในโค้ด
 * ของตัวเอง ซึ่งแปลว่าเครื่องชั่งรุ่นอื่นหรือร้านที่ตั้งค่าต่างไปจะอ่านผิดทันที
 * และแก้ทีต้องแก้สองที่ให้ตรงกันเอง
 *
 * ค่าเริ่มต้นที่ seed ไว้คือกฎเดิมทุกประการ พฤติกรรมจึงไม่เปลี่ยนจากวันนี้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scale_barcode_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('prefix', 6);                       // ตัวขึ้นต้นที่บอกว่าเป็นป้ายเครื่องชั่ง
            $table->unsignedTinyInteger('plu_length');          // ความยาวรหัสสินค้าในป้าย (รวม prefix)
            $table->unsignedTinyInteger('value_length');        // ความยาวช่องมูลค่า
            $table->string('value_type', 10);                   // price = ราคารวมเป็นสตางค์, weight = น้ำหนักเป็นกรัม
            $table->string('check_digit', 10);                  // ean13 = ตรวจหลักสุดท้าย, none = ไม่ตรวจ
            $table->unsignedTinyInteger('total_length');
            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('scale_barcode_profiles')->insert([
            [
                'code' => 'POPSTAR-800', 'name' => 'ป้ายเครื่องชั่ง 800xxx (ราคารวม)',
                'prefix' => '800', 'plu_length' => 6, 'value_length' => 6, 'value_type' => 'price',
                'check_digit' => 'ean13', 'total_length' => 13, 'is_active' => true,
                'note' => 'PLU 6 หลัก + ราคารวมเป็นสตางค์ 6 หลัก + check digit',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'POPSTAR-801', 'name' => 'ป้ายเครื่องชั่ง 801xxx (ราคารวม)',
                'prefix' => '801', 'plu_length' => 6, 'value_length' => 6, 'value_type' => 'price',
                'check_digit' => 'ean13', 'total_length' => 13, 'is_active' => true,
                'note' => 'PLU 6 หลัก + ราคารวมเป็นสตางค์ 6 หลัก + check digit',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                // เครื่องชั่งบางรุ่นออกป้าย 12 หลักและไม่ใส่ check digit มาให้
                'code' => 'POPSTAR-800-12', 'name' => 'ป้ายเครื่องชั่ง 800xxx แบบ 12 หลัก',
                'prefix' => '800', 'plu_length' => 6, 'value_length' => 5, 'value_type' => 'price',
                'check_digit' => 'none', 'total_length' => 12, 'is_active' => true,
                'note' => 'PLU 6 หลัก + ราคารวม 5 หลัก + หลักท้ายไม่ใช้',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'POPSTAR-801-12', 'name' => 'ป้ายเครื่องชั่ง 801xxx แบบ 12 หลัก',
                'prefix' => '801', 'plu_length' => 6, 'value_length' => 5, 'value_type' => 'price',
                'check_digit' => 'none', 'total_length' => 12, 'is_active' => true,
                'note' => 'PLU 6 หลัก + ราคารวม 5 หลัก + หลักท้ายไม่ใช้',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('scale_barcode_profiles');
    }
};
