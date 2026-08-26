<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('sales_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sales_area_id')->nullable()->constrained('sales_areas')->nullOnDelete();
            $table->string('title', 200);
            $table->string('stage', 30)->default('new');
            $table->decimal('expected_amount', 18, 4)->default(0);
            $table->date('expected_close_date')->nullable();
            $table->text('note')->nullable();
            $table->string('lost_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'stage']);
            $table->index(['sales_user_id', 'stage']);
            $table->index(['customer_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunities');
    }
};
