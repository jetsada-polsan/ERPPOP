<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * สมุดเงินสดต้องถูกเดินรายการโดยระบบ ไม่ใช่พึ่งคนกรอก
 *
 * ของเดิมมีแค่ branch_id/entry_date/description/debit/credit/balance และมีที่เขียนเข้า
 * ที่เดียวคือฟอร์มกรอกมือ สมุดเงินสดจึงไม่มีทางตรงกับยอดขายและยอดปิดกะ
 *
 * รอบนี้เปลี่ยนเป็นสมุดที่รับ posting อัตโนมัติ:
 *  - `source_key` unique = กันลงซ้ำ (idempotent) เอกสารใบเดิม post กี่ครั้งก็ได้แถวเดียว
 *  - `source_type` / `source_id` ไล่กลับไปยังเอกสารต้นทางได้
 *  - `running_balance` คำนวณตอนบันทึกโดยล็อกแถวล่าสุดของสาขา
 *  - กรอกมือได้เฉพาะ source_type = 'adjustment' ซึ่งต้องมีผู้อนุมัติและเหตุผล
 *
 * สร้างตารางใหม่แทนการ ALTER ทีละคอลัมน์ เพราะบน SQLite การถอด foreign key หรือทิ้ง
 * หลายคอลัมน์ใน blueprint เดียวจะ rebuild ตารางกลางคัน ทำให้ down() ย้อนไม่สุด
 * ทั้งสองทิศทางจึงเป็น create/drop ตรง ๆ ซึ่งพฤติกรรมเหมือนกันทั้ง PostgreSQL และ SQLite
 *
 * ปลอดภัยเพราะ cash_books ยังไม่มีข้อมูลจริง — และถ้ามีเมื่อไร migration จะหยุดทันที
 * ไม่ยอมทำลายข้อมูลเงียบ ๆ
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstDataLoss('up');

        Schema::dropIfExists('cash_books');
        Schema::create('cash_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('entry_date');
            $table->text('description')->nullable();
            $table->decimal('cash_in', 18, 4)->default(0);
            $table->decimal('cash_out', 18, 4)->default(0);
            $table->decimal('running_balance', 18, 4)->default(0);
            // posting อัตโนมัติ: source_key เป็นตัวกันลงซ้ำ, source_type/source_id ไว้ไล่กลับต้นทาง
            $table->string('source_type', 30)->default('adjustment');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_key', 120)->unique();
            $table->foreignId('pos_terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
            $table->foreignId('pos_shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::table('cash_books', function (Blueprint $table) {
            $table->index(['branch_id', 'entry_date'], 'cash_books_branch_entry_idx');
            $table->index(['source_type', 'source_id'], 'cash_books_source_idx');
        });
    }

    public function down(): void
    {
        $this->guardAgainstDataLoss('down');

        Schema::dropIfExists('cash_books');
        Schema::create('cash_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('entry_date');
            $table->text('description')->nullable();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->decimal('balance', 18, 4)->default(0);
        });
    }

    private function guardAgainstDataLoss(string $direction): void
    {
        if (Schema::hasTable('cash_books') && DB::table('cash_books')->exists()) {
            throw new RuntimeException(
                "cash_books มีข้อมูลอยู่แล้ว migration นี้ ({$direction}) จะสร้างตารางใหม่และทำให้ข้อมูลหาย ".
                'ให้ย้ายข้อมูลออกและตรวจยอดก่อน แล้วจึงรันใหม่'
            );
        }
    }
};
