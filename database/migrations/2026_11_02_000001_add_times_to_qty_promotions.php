<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qty_promotions', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
        });
        foreach (DB::table('qty_promotions')->get(['id', 'starts_date', 'ends_date']) as $row) {
            DB::table('qty_promotions')->where('id', $row->id)->update([
                'starts_at' => $row->starts_date ? substr($row->starts_date, 0, 10).' 00:00:00' : null,
                'ends_at' => $row->ends_date ? substr($row->ends_date, 0, 10).' 23:59:59' : null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('qty_promotions', fn (Blueprint $table) => $table->dropColumn(['starts_at', 'ends_at']));
    }
};
