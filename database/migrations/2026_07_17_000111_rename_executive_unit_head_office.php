<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $hoId = DB::table('branches')->where('code', 'HO')->value('id');
        DB::table('organizational_units')->where('code', 'EXEC')->update([
            'name' => 'ผู้บริหาร/กรรมการ สนญ',
            'branch_id' => $hoId,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('organizational_units')->where('code', 'EXEC')->update([
            'name' => 'ผู้บริหาร / กรรมการ',
            'branch_id' => null,
            'updated_at' => now(),
        ]);
    }
};
