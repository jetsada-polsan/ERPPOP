<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $settings = [
            'company_name_th' => 'เหมียวฟู๊ด',
            'company_name_en' => 'Meaw Foods',
            'logo_path' => 'images/logos/meaw-foods.png',
            'receipt_logo_path' => 'images/logos/meaw-foods-receipt.png',
            'favicon_path' => 'images/logos/meaw-foods-favicon.png',
        ];

        foreach ($settings as $key => $value) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // Brand activation is intentionally not reversed automatically.
        // Existing company settings may have been edited after deployment.
    }
};
