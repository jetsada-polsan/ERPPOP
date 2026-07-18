<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 30)->unique();
            $table->string('full_name', 200);
            $table->string('nickname', 100)->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('alt_phone', 100)->nullable();
            $table->string('national_id', 30)->nullable()->index();
            $table->string('nationality', 50)->nullable();
            $table->string('birth_date_raw', 100)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('branch_text', 150)->nullable();
            $table->string('department', 150)->nullable()->index();
            $table->string('position', 150)->nullable();
            $table->string('employment_type', 100)->nullable();
            $table->string('wage_type', 50)->nullable();
            $table->decimal('wage_amount', 14, 2)->nullable();
            $table->string('start_date_raw', 100)->nullable();
            $table->string('status', 30)->default('Active')->index();
            $table->text('remark')->nullable();
            $table->string('source_section', 150)->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'department']);
        });
    }

    public function down(): void { Schema::dropIfExists('employees'); }
};
