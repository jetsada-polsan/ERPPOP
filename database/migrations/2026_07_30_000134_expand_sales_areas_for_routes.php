<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_areas', function (Blueprint $table) {
            $table->string('area_type', 20)->default('route');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('default_salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->boolean('is_active')->default(true);
        });

        DB::table('branches')->where('is_active', true)->orderBy('code')->get()->each(function ($branch) {
            $code = substr('BR-'.$branch->code, 0, 20);
            if (DB::table('sales_areas')->where('code', $code)->exists()) {
                return;
            }

            $salesmen = DB::table('salesmen')
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->pluck('id');

            DB::table('sales_areas')->insert([
                'code' => $code,
                'name' => $branch->name_th,
                'area_type' => 'branch',
                'branch_id' => $branch->id,
                'default_salesman_id' => $salesmen->count() === 1 ? $salesmen->first() : null,
                'is_active' => true,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('sales_areas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_salesman_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['area_type', 'is_active']);
        });
    }
};
