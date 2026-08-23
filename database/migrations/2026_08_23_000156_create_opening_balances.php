<?php

use App\Models\ChartOfAccount;
use App\Models\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ยอดยกมาตอนเริ่มใช้ระบบ
 *
 * ระบบที่เพิ่งล้างข้อมูลยังใช้ทำธุรกิจไม่ได้จนกว่าจะมีสต๊อก ลูกหนี้ เจ้าหนี้
 * และเงินสดยกมาจากของเดิม ทุกยอดที่ยกมาต้องลง GL ด้วย ไม่งั้นของมีอยู่ในคลัง
 * แต่งบดุลเป็นศูนย์
 *
 * อีกขาของทุกรายการยกมาลงที่บัญชี 3030 ไม่ใช่กำไรสะสมโดยตรง เพราะการยกยอด
 * ทำทีละชุด (สต๊อกวันนี้ ลูกหนี้พรุ่งนี้) บัญชีพักทำให้เห็นว่ายกมาแล้วเท่าไร
 * และถ้ายกครบแล้วยอดไม่ลงตัว จะเห็นเป็นตัวเลขค้างที่ 3030 แทนที่จะปนเข้ากำไรสะสมเงียบ ๆ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);              // stock | ar | ap | cash
            $table->foreignId('branch_id')->constrained('branches');
            $table->date('as_of_date');
            $table->unsignedInteger('line_count')->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->string('source_name')->nullable();
            $table->string('source_checksum', 64)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // ยกยอดชนิดเดียวกัน สาขาเดียวกัน ณ วันเดียวกัน ทำได้ครั้งเดียว
            $table->unique(['kind', 'branch_id', 'as_of_date']);
        });

        ChartOfAccount::firstOrCreate(
            ['code' => '3030'],
            [
                'name_th' => 'ยอดยกมาระหว่างตั้งต้นระบบ',
                'name_en' => 'Opening balance suspense',
                'account_type' => 'equity',
                'default_role' => ChartOfAccount::ROLE_OPENING_BALANCE,
            ],
        );

        // ลูกหนี้ยกมาต้องผูกกับเอกสาร (customer_open_items.document_id เป็น NOT NULL)
        // จึงต้องมีชนิดเอกสารสำหรับใบหนี้เดิมที่ยกมาโดยเฉพาะ แยกจากใบขายจริง
        DocumentType::firstOrCreate(['code' => 'OPENING_AR'], ['name_th' => 'ใบหนี้ยกมา']);
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_runs');
        ChartOfAccount::where('code', '3030')->delete();
        DocumentType::where('code', 'OPENING_AR')->delete();
    }
};
