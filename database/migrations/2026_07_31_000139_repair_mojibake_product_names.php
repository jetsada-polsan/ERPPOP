<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair product names that were imported after UTF-8 bytes had been decoded as
 * Windows-874. Only values whose reverse conversion is valid UTF-8 are changed;
 * ordinary Thai text is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->select('id', 'name_th')
            ->where('name_th', 'like', '%เธ%')
            ->orderBy('id')
            ->eachById(function (object $product): void {
                $name = (string) $product->name_th;
                $repaired = @iconv('UTF-8', 'CP874//IGNORE', $name);

                if ($repaired === false || $repaired === $name || ! mb_check_encoding($repaired, 'UTF-8')) {
                    return;
                }

                DB::table('products')->where('id', $product->id)->update([
                    'name_th' => $repaired,
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Text repairs are intentionally not reversed automatically.
    }
};
