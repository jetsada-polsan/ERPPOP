<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->decimal('default_float', 18, 2)->default(2000);
            $table->decimal('cash_warning_threshold', 18, 2)->default(10000);
            $table->boolean('blind_count')->default(true);
        });
        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->decimal('carried_float', 18, 2)->nullable();
            $table->decimal('opening_variance', 18, 2)->nullable();
            $table->decimal('float_kept', 18, 2)->nullable();
            $table->decimal('deposit_amount', 18, 2)->nullable();
            $table->string('deposit_reference', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', fn (Blueprint $table) => $table->dropColumn([
            'carried_float', 'opening_variance', 'float_kept', 'deposit_amount', 'deposit_reference',
        ]));
        Schema::table('pos_terminals', fn (Blueprint $table) => $table->dropColumn([
            'default_float', 'cash_warning_threshold', 'blind_count',
        ]));
    }
};
