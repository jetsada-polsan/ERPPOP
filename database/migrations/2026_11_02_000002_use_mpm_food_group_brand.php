<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AppSetting::set('logo_path', 'images/logos/mpm-food-group.jpg');
        AppSetting::set('receipt_logo_path', 'images/logos/mpm-food-group.jpg');
        AppSetting::set('favicon_path', 'images/logos/mpm-food-group.jpg');
        AppSetting::set('company_name_th', 'บริษัท เอ็มพีเอ็ม ฟู้ดส์ กรุ๊ป จำกัด');
        AppSetting::set('company_name_en', 'MPM Food Group');
    }

    public function down(): void {}
};
