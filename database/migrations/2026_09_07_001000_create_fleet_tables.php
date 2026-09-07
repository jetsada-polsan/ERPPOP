<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fleet_vehicles', function (Blueprint $table) {
            $table->id(); $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30)->unique(); $table->string('registration', 30)->unique();
            $table->string('vehicle_type', 80); $table->string('brand', 80)->nullable(); $table->string('model', 80)->nullable();
            $table->unsignedInteger('current_odometer')->default(0); $table->string('status', 20)->default('active'); $table->text('note')->nullable(); $table->timestamps();
        });
        Schema::create('fleet_trips', function (Blueprint $table) {
            $table->id(); $table->foreignId('vehicle_id')->constrained('fleet_vehicles')->cascadeOnDelete(); $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('trip_date'); $table->unsignedInteger('odometer_start'); $table->unsignedInteger('odometer_end'); $table->string('route'); $table->decimal('fuel_liters',10,2)->default(0); $table->decimal('fuel_cost',12,2)->default(0); $table->text('note')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('fleet_repairs', function (Blueprint $table) {
            $table->id(); $table->foreignId('vehicle_id')->constrained('fleet_vehicles')->cascadeOnDelete(); $table->date('repair_date'); $table->unsignedInteger('odometer')->default(0); $table->string('summary'); $table->string('vendor')->nullable(); $table->decimal('cost',12,2)->default(0); $table->date('next_service_date')->nullable(); $table->text('note')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        $permissionId = DB::table('permissions')->insertGetId(['code'=>'transport.manage','name'=>'จัดการยานพาหนะและขนส่ง']);
        foreach (['GM','BRANCH_MGR','IT_MGR'] as $code) { $roleId = DB::table('roles')->where('code',$code)->value('id'); if ($roleId) DB::table('permission_role')->insertOrIgnore(['role_id'=>$roleId,'permission_id'=>$permissionId]); }
    }
    public function down(): void { Schema::dropIfExists('fleet_repairs'); Schema::dropIfExists('fleet_trips'); Schema::dropIfExists('fleet_vehicles'); DB::table('permissions')->where('code','transport.manage')->delete(); }
};
