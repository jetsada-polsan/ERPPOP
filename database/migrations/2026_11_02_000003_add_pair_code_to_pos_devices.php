<?php

use App\Models\PosDevice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', fn (Blueprint $table) => $table->string('pair_code', 8)->nullable()->unique());
        foreach (DB::table('pos_devices')->pluck('id') as $id) {
            DB::table('pos_devices')->where('id', $id)->update(['pair_code' => PosDevice::newPairCode()]);
        }
    }

    public function down(): void
    {
        Schema::table('pos_devices', fn (Blueprint $table) => $table->dropColumn('pair_code'));
    }
};
