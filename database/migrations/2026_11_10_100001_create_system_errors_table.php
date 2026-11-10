<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_errors', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->unsignedSmallInteger('status')->default(500)->index();
            $table->text('url');
            $table->string('route_name')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('message', 500);
            $table->string('exception_class');
            $table->string('file_line');
            $table->string('fingerprint', 64)->unique();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_errors');
    }
};
