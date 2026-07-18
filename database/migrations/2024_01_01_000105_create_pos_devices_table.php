<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Token ต่อเครื่อง (POS desktop/Tauri) แทน session login: 1 device = login แทน
// cashier user 1 คน (branch/salesman ถูกล็อกผ่าน user เดิม) + ตารางกัน bill ซ้ำ
// (idempotency) เพื่อรองรับ offline-first ที่ retry ส่งบิลเดิมได้อย่างปลอดภัย
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);                                  // ชื่ออุปกรณ์ เช่น "วาริน เครื่อง 1"
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // login แทน user นี้
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('terminal_code', 40)->nullable();
            $table->string('token_hash', 64)->unique();                   // sha256 ของ token (เก็บ plaintext ไม่ได้)
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();                  // เพิกถอน = ยัง query ประวัติได้
            $table->timestamps();
        });

        Schema::create('pos_api_idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 120)->unique();            // terminal:TYPE:uuid จาก client
            $table->foreignId('pos_device_id')->constrained('pos_devices')->cascadeOnDelete();
            $table->string('endpoint', 40);                               // checkout ฯลฯ
            $table->smallInteger('status_code');
            $table->longText('response_body');                            // JSON คืนเดิมเป๊ะเมื่อ replay
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_api_idempotency');
        Schema::dropIfExists('pos_devices');
    }
};
