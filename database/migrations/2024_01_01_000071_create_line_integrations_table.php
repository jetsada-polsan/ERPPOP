<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('channel_type', 40)->default('messaging_api');
            $table->string('target_name', 150)->nullable();
            $table->text('token')->nullable();
            $table->boolean('notify_sales')->default(true);
            $table->boolean('notify_qr_payment')->default(true);
            $table->boolean('notify_void_bill')->default(true);
            $table->boolean('notify_stock_alert')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_integrations');
    }
};
