<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business Plus DOCTYPE records whose DT_PROPERTIES = 207.
     *
     * @var array<string, string>
     */
    private const ROUTES = [
        'B10' => 'ใบจองสินค้า-หน้าร้าน',
        'B11' => 'ใบจองสินค้า-กันทรารมย์-ยโสธร (king)',
        'B12' => 'ใบจองสินค้า-สายพลาญชัย',
        'B14' => 'ใบจองสินค้า-เดชอุดม-นาจะหลวย',
        'B15' => 'ใบจองสินค้า-เดชอุดม-น้ำยืน (บอลอ้วน)',
        'B16' => 'ใบจองสินค้า-ตระการ (คิม)',
        'B17' => 'ใบจองสินค้า-ชานุมาน (เต้ย)',
        'B18' => 'ใบจองสินค้า-ม่วง-เลิง-อำนาจ-มุก',
        'B19' => 'ใบจองสินค้า-ม่วง-เลิง-มุก',
        'B20' => 'ใบจองสินค้า-กันทรารมย์-ราษี-ยโส (เกรียง)',
        'B21' => 'ใบจองสินค้า-หลวงปู่สรวง-สังขะ',
        'B22' => 'ใบจองสินค้า-สายในเมือง (รอบที่ 2)',
        'B23' => 'ใบจองสินค้า-ปราสาท สุรินทร์ (ปีอดด)',
        'B24' => 'ใบจองสินค้า-สายใหม่ (ก้อง)',
        'B25' => 'ใบจองสินค้า-สุรินทร์',
        'B26' => 'ใบจองสินค้า-ศรีสะเกษ-โพนทราย',
        'B27' => 'ใบจองสินค้า-ระหว่างอุบล-กันทรลักษ์',
        'B31' => 'ใบจองสินค้า-SHOP',
        'B32' => 'ใบจองสินค้า-SHOP วารินชำราบ',
        'B33' => 'ใบจองสินค้า SHOP สุรินทร์',
        'B34' => 'ใบจองสินค้า SHOP ปลาดุก',
        'B35' => 'ใบจองสินค้า SHOP ดอนกลาง',
        'B36' => 'ใบจองสินค้า SHOP ตลาดเจริญศรี',
        'B37' => 'ใบจองสินค้า ออนไลน์',
        'B38' => 'ใบจองสินค้า SHOP วาริน',
        'B39' => 'ใบจองสินค้า-สายขุนหาญ',
        'BK' => 'ใบจองสินค้า',
        'BK1' => 'ใบจองสินค้า-เดชอุดม (หนุ่ม)',
        'BK2' => 'ใบจองสินค้า-ตระการ',
        'BK3' => 'ใบจองสินค้า-ม่วง-เลิง-มุก',
        'BK4' => 'ใบจองสินค้า-ปราสาท สุรินทร์',
        'BK5' => 'ใบจองสินค้า-กัณทลักษณ์ (Nicky)',
        'BK6' => 'ใบจองสินค้า-ศรีเมืองใหม่ (ไก่ต๊อก)',
        'BK7' => 'ใบจองสินค้า-ช่องเม็ก',
        'BK8' => 'ใบจองสินค้า-สายในเมือง (รอบที่ 1)',
        'BK9' => 'ใบจองสินค้า-พิบูล',
        'BS' => 'ใบจองสินค้า-ขายสต็อกสินค้า',
    ];

    /**
     * Predominant salesperson per booking book, calculated from active
     * Business Plus documents in the previous 12 months on 2026-07-30.
     *
     * @var array<string, array{string, string}>
     */
    private const DEFAULT_SALESMEN = [
        'B10' => ['19', 'คลัง'],
        'B11' => ['12', 'จิน'],
        'B12' => ['03', 'โบว์'],
        'B14' => ['07', 'วุ้น'],
        'B15' => ['02', 'กาญ'],
        'B16' => ['01', 'เก่ง'],
        'B17' => ['05', 'บีบี'],
        'B18' => ['09', 'แบงค์'],
        'B20' => ['08', 'ยีนส์'],
        'B21' => ['10', 'บิว'],
        'B26' => ['04', 'รุ่ง'],
        'B32' => ['11', 'ต้าร์'],
        'B33' => ['16', 'สาขาสุรินทร์'],
        'B34' => ['15', 'สาขาปลาดุก'],
        'B35' => ['0', 'ขายเอง'],
        'B36' => ['0', 'ขายเอง'],
        'B37' => ['0', 'ขายเอง'],
        'B38' => ['11', 'ต้าร์'],
        'B39' => ['21', 'บุ๋มบิ๋ม'],
        'BK5' => ['06', 'อุ๋งอิ๋ง'],
        'BK6' => ['14', 'แอนนา'],
        'BK7' => ['03', 'โบว์'],
    ];

    public function up(): void
    {
        Schema::table('sales_areas', function (Blueprint $table) {
            $table->foreignId('document_book_id')->nullable()
                ->constrained('document_books')->nullOnDelete();
        });

        // These rows were generated from branches before the old B-code meaning
        // was confirmed. Keep them for historical references, but do not offer
        // them as sales routes on new booking documents.
        DB::table('sales_areas')
            ->where('area_type', 'branch')
            ->update(['is_active' => false]);

        $bookingTypeId = DB::table('document_types')->where('code', 'BOOKING')->value('id');
        if (! $bookingTypeId) {
            $bookingTypeId = DB::table('document_types')->insertGetId([
                'code' => 'BOOKING',
                'name_th' => 'ใบจองสินค้า',
                'name_en' => 'Sale Booking',
                'affects_stock' => false,
                'affects_ar' => false,
                'affects_ap' => false,
                'is_active' => true,
            ]);
        }

        foreach (collect(self::DEFAULT_SALESMEN)->unique(fn (array $salesman) => $salesman[0]) as [$code, $name]) {
            DB::table('salesmen')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'is_active' => true]
            );
        }

        foreach (self::ROUTES as $code => $name) {
            $bookId = DB::table('document_books')
                ->where('document_type_id', $bookingTypeId)
                ->where('code', $code)
                ->value('id');

            if ($bookId) {
                DB::table('document_books')->where('id', $bookId)->update([
                    'name' => $name,
                    'prefix' => $code,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            } else {
                $bookId = DB::table('document_books')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'document_type_id' => $bookingTypeId,
                    'prefix' => $code,
                    'is_default' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $salesmanCode = self::DEFAULT_SALESMEN[$code][0] ?? null;
            $salesmanId = $salesmanCode
                ? DB::table('salesmen')->where('code', $salesmanCode)->value('id')
                : null;

            DB::table('sales_areas')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'area_type' => 'route',
                    'branch_id' => null,
                    'default_salesman_id' => $salesmanId,
                    'document_book_id' => $bookId,
                    'is_active' => true,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('sales_areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_book_id');
        });
    }
};
