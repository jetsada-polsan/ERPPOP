<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->text('note')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'starts_on', 'ends_on'], 'accounting_period_scope_dates_idx');
            $table->index(['status', 'starts_on', 'ends_on'], 'accounting_period_status_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
