<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_books')
            ->where('code', 'like', 'BOOKING-TMP-%')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Legacy duplicate books remain hidden intentionally.
    }
};
