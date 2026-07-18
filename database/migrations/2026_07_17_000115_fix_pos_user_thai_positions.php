<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('users')->where('username', 'like', 'pos%')->update([
            'position' => 'แคชเชียร์ประจำสาขา',
            'updated_at' => now(),
        ]);
    }

    public function down(): void {}
};
