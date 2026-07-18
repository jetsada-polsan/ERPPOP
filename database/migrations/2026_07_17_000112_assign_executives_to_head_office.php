<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $hoId = DB::table('branches')->where('code', 'HO')->value('id');
        if (! $hoId) return;

        DB::table('employees')
            ->whereIn('department', ['ผู้บริหาร/กรรมการ', 'ผู้บริหาร / กรรมการ'])
            ->update(['branch_id'=>$hoId, 'branch_text'=>'สำนักงานใหญ่', 'updated_at'=>now()]);
    }

    public function down(): void {}
};
