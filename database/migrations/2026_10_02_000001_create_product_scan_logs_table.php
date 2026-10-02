<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_scan_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context', 30);
            $table->string('code', 100);
            $table->string('source', 20)->default('manual');
            $table->string('result', 20);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['context', 'created_at']);
            $table->index(['code', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_scan_logs');
    }
};
