<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FA ทะเบียนทรัพย์สิน + ค่าเสื่อมราคา (BPlus manual ch.16): บันทึกทรัพย์สินถาวร
// คิดค่าเสื่อมแบบเส้นตรง (straight-line) รายเดือน เก็บประวัติค่าเสื่อมแต่ละงวด
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 40)->unique();
            $table->string('name', 200);
            $table->string('category', 100)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('acquired_date');
            $table->decimal('cost', 18, 2);
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->string('status', 20)->default('active'); // active|fully_depreciated|disposed
            $table->date('disposed_date')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('depreciation_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->date('period_date'); // สิ้นเดือนของงวดที่คิดค่าเสื่อม
            $table->decimal('amount', 18, 2);
            $table->decimal('accumulated_after', 18, 2);
            $table->decimal('book_value_after', 18, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['fixed_asset_id', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_records');
        Schema::dropIfExists('fixed_assets');
    }
};
