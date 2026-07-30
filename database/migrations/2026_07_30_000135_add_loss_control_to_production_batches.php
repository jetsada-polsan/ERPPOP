<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->string('loss_reason_code', 30)->nullable();
            $table->decimal('expected_loss_percent', 9, 4)->default(0);
            $table->decimal('loss_cost_amount', 18, 8)->default(0);
            $table->decimal('abnormal_loss_qty', 18, 8)->default(0);
            $table->decimal('abnormal_loss_cost_amount', 18, 8)->default(0);
            $table->string('loss_note', 500)->nullable();
        });

        DB::table('production_batches')->where('loss_weight_qty', '>', 0)->update([
            'loss_reason_code' => 'unclassified',
            'abnormal_loss_qty' => DB::raw('loss_weight_qty'),
            'loss_cost_amount' => DB::raw('case when input_weight_qty > 0 then total_input_cost * loss_weight_qty / input_weight_qty else 0 end'),
            'abnormal_loss_cost_amount' => DB::raw('case when input_weight_qty > 0 then total_input_cost * loss_weight_qty / input_weight_qty else 0 end'),
        ]);
    }

    public function down(): void
    {
        Schema::table('production_batches', function (Blueprint $table) {
            $table->dropColumn([
                'loss_reason_code', 'expected_loss_percent', 'loss_cost_amount',
                'abnormal_loss_qty', 'abnormal_loss_cost_amount', 'loss_note',
            ]);
        });
    }
};
