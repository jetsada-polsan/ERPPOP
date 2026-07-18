<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizational_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('unit_type', 30)->default('department');
            $table->foreignId('parent_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('employee_org_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('organizational_unit_id')->constrained('organizational_units')->cascadeOnDelete();
            $table->string('position_title', 150)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'organizational_unit_id'], 'employee_org_unique');
            $table->index(['organizational_unit_id', 'is_primary']);
        });

        $now = now();
        $company = DB::table('organizational_units')->insertGetId(['code'=>'COMPANY','name'=>'บริษัท','unit_type'=>'company','sort_order'=>10,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        $executive = DB::table('organizational_units')->insertGetId(['code'=>'EXEC','name'=>'ผู้บริหาร / กรรมการ','unit_type'=>'management','parent_id'=>$company,'sort_order'=>10,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        $gm = DB::table('organizational_units')->insertGetId(['code'=>'GM-OFFICE','name'=>'สำนักงานผู้จัดการทั่วไป','unit_type'=>'management','parent_id'=>$company,'sort_order'=>20,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        $shop = DB::table('organizational_units')->insertGetId(['code'=>'SHOP-OPS','name'=>'ปฏิบัติการหน้าร้าน','unit_type'=>'division','parent_id'=>$company,'sort_order'=>30,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);

        $units = [];
        foreach ([
            ['ACC','บัญชี',10], ['WH','คลังสินค้า',20], ['DELIVERY','ฝ่ายจัดส่ง',30],
            ['SALES','ฝ่ายขาย',40], ['IT','ระบบ',50], ['MAINT','ช่างซ่อมบำรุง',60],
        ] as [$code, $name, $sort]) {
            $units[$name] = DB::table('organizational_units')->insertGetId(['code'=>$code,'name'=>$name,'unit_type'=>'department','parent_id'=>$gm,'sort_order'=>$sort,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        }

        $departmentMap = $units + [
            'ผู้บริหาร/กรรมการ' => $executive,
            'หน้าร้าน' => $shop,
            'สิบล้อ' => $units['ฝ่ายจัดส่ง'],
            'Oniine Marketing' => $units['ฝ่ายขาย'],
        ];
        DB::table('employees')->orderBy('id')->get(['id','department','position'])->each(function ($employee) use ($departmentMap, $now) {
            $unitId = $departmentMap[$employee->department] ?? null;
            if (! $unitId) return;
            DB::table('employee_org_assignments')->insert([
                'employee_id'=>$employee->id,
                'organizational_unit_id'=>$unitId,
                'position_title'=>$employee->position,
                'is_primary'=>true,
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_org_assignments');
        Schema::dropIfExists('organizational_units');
    }
};
