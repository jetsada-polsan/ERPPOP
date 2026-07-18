<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organizational_unit_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('title', 150);
            $table->foreignId('holder_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('reports_to_position_id')->nullable()->constrained('organization_positions')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['organizational_unit_id', 'is_active']);
        });

        $gmUnit = DB::table('organizational_units')->where('code', 'GM-OFFICE')->value('id');
        $salesUnit = DB::table('organizational_units')->where('code', 'SALES')->value('id');
        $gm = DB::table('employees')->where('employee_code', 'EMP0075')->value('id');
        $salesManager = DB::table('employees')->where('employee_code', 'EMP0035')->value('id');
        $now = now();
        if ($gmUnit) DB::table('organization_positions')->insert(['organizational_unit_id'=>$gmUnit,'code'=>'GM','title'=>'ผู้จัดการทั่วไป','holder_employee_id'=>$gm,'sort_order'=>10,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        if ($salesUnit) {
            $managerId = DB::table('organization_positions')->insertGetId(['organizational_unit_id'=>$salesUnit,'code'=>'SALES-MGR','title'=>'ผู้จัดการฝ่ายขาย','holder_employee_id'=>$salesManager,'sort_order'=>10,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
            DB::table('organization_positions')->insert(['organizational_unit_id'=>$salesUnit,'code'=>'SALES-LEAD','title'=>'หัวหน้าฝ่ายขาย','holder_employee_id'=>null,'reports_to_position_id'=>$managerId,'sort_order'=>20,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
            DB::table('organizational_units')->where('id', $salesUnit)->update(['manager_employee_id'=>$salesManager,'updated_at'=>$now]);
        }
    }
    public function down(): void { Schema::dropIfExists('organization_positions'); }
};
