<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            // PIN ที่แอดมินตั้งให้ = มีคนอื่นรู้ค่า จึงยังใช้ยืนยันตัวตนไม่ได้จนกว่าเจ้าตัวจะเปลี่ยน
            $table->boolean('must_change_pin')->default(false)->after('pos_pin_hash');
            $table->timestamp('pin_changed_at')->nullable()->after('must_change_pin');
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn(['must_change_pin', 'pin_changed_at']);
        });
    }
};
