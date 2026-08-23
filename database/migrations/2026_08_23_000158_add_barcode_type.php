<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ประเภทของบาร์โค้ด เก็บรายแถวไม่ใช่รายสินค้า
 *
 * สินค้าตัวเดียวมีได้หลายหน่วยนับและหลายรูปแบบบาร์โค้ด เช่นชิ้นเป็น EAN-13
 * จากผู้ผลิต ส่วนลังเป็นรหัสที่บริษัทตั้งเอง ประเภทจึงต้องอยู่กับบาร์โค้ดแต่ละแถว
 *
 * ของเดิมย้ายเข้า INTERNAL_13 (13 หลัก) หรือ CUSTOM (นอกนั้น) โดยค่าบาร์โค้ด
 * ไม่ถูกแตะแม้แต่ตัวเดียว — ที่พิมพ์ติดสินค้าไปแล้วเปลี่ยนตามไม่ได้
 * และไม่มีอะไรถูกจัดเป็น EAN13_STANDARD อัตโนมัติ เพราะเราไม่รู้ว่าเลขไหน
 * มาจาก GS1 จริง การเดาแล้วติดป้ายว่าเป็นมาตรฐานสากลอันตรายกว่าปล่อยว่างไว้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->string('barcode_type', 20)->default('CUSTOM');
            $table->string('type_note')->nullable();
        });
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->index('barcode_type');
        });

        DB::table('product_barcodes')
            ->whereRaw('length(barcode) = 13')
            ->whereRaw("barcode not like '%[^0-9]%'")
            ->update(['barcode_type' => 'INTERNAL_13']);

        // เครื่องมือบางตัวไม่รองรับ pattern ข้างบน จึงไล่ยืนยันอีกรอบด้วย PHP
        DB::table('product_barcodes')->select('id', 'barcode')->orderBy('id')->chunk(2000, function ($rows) {
            $thirteen = [];
            $other = [];
            foreach ($rows as $row) {
                $barcode = (string) $row->barcode;
                if (strlen($barcode) === 13 && ctype_digit($barcode)) {
                    $thirteen[] = $row->id;
                } else {
                    $other[] = $row->id;
                }
            }
            if ($thirteen !== []) {
                DB::table('product_barcodes')->whereIn('id', $thirteen)->update(['barcode_type' => 'INTERNAL_13']);
            }
            if ($other !== []) {
                DB::table('product_barcodes')->whereIn('id', $other)->update(['barcode_type' => 'CUSTOM']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_barcodes', fn (Blueprint $table) => $table->dropColumn(['barcode_type', 'type_note']));
    }
};
