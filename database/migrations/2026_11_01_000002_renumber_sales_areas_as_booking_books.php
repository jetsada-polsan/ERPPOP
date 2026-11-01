<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// สายคือเล่มใบจอง: อำนาจเจริญเป็น B001 สายอื่นรันต่อ แก้ชื่อได้ที่หน้าสายการขาย
return new class extends Migration
{
    public function up(): void
    {
        $areas = DB::table('sales_areas')->orderBy('id')->get(['id', 'name']);
        $ordered = $areas->sortBy(fn ($area) => str_contains((string) $area->name, 'อำนาจเจริญ') ? 0 : 1)->values();
        foreach ($ordered as $area) {
            DB::table('sales_areas')->where('id', $area->id)->update(['code' => 'TMP'.$area->id]);
        }
        foreach ($ordered as $index => $area) {
            DB::table('sales_areas')->where('id', $area->id)->update(['code' => sprintf('B%03d', $index + 1)]);
        }
    }

    public function down(): void {}
};
