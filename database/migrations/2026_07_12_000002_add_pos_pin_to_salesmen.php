<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            if (! Schema::hasColumn('salesmen', 'pos_pin_hash')) {
                $table->string('pos_pin_hash')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            if (Schema::hasColumn('salesmen', 'pos_pin_hash')) {
                $table->dropColumn('pos_pin_hash');
            }
        });
    }
};
