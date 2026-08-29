<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $branchId = DB::table('branches')->where('code', 'B001')->value('id');
        if (! $branchId) {
            return;
        }

        $legacy = DB::table('pos_terminals')
            ->where('branch_id', $branchId)
            ->where('code', '0002')
            ->first(['id']);
        $existing = DB::table('pos_terminals')
            ->where('code', 'POS-B001-01')
            ->first(['id']);

        if ($legacy && ! $existing) {
            DB::table('pos_terminals')->where('id', $legacy->id)->update([
                'code' => 'POS-B001-01',
                'name' => 'POS001 · B001',
            ]);
        }
    }

    public function down(): void
    {
        $branchId = DB::table('branches')->where('code', 'B001')->value('id');
        if (! $branchId) {
            return;
        }

        DB::table('pos_terminals')
            ->where('branch_id', $branchId)
            ->where('code', 'POS-B001-01')
            ->where('name', 'POS001 · B001')
            ->update([
                'code' => '0002',
                'name' => 'สาขา-หน้าร้าน',
            ]);
    }
};
