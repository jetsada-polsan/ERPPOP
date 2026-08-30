<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_auth_event_ingests', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('pos_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->string('cashier_code', 40);
            $table->string('event_type', 60);
            $table->boolean('success');
            $table->text('reason')->nullable();
            $table->string('terminal_code', 80)->nullable();
            $table->string('branch_code', 80)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['pos_device_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_auth_event_ingests');
    }
};
