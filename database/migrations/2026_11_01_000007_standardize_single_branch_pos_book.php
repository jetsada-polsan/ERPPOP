<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_books')) {
            return;
        }

        $hasPs01 = DB::table('document_books')->where('code', 'PS01')->exists();

        if (! $hasPs01) {
            DB::table('document_books')
                ->where('code', 'PS07')
                ->update([
                    'code' => 'PS01',
                    'prefix' => 'PS',
                    'name' => DB::raw("replace(name, '(0007)', '(0001)')"),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Do not rename issued document books backwards automatically.
    }
};
