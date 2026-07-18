<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 40)->unique();
            $table->string('approval_type', 40);
            $table->string('subject', 150);
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('status', 30)->default('pending');
            $table->string('requested_by', 100)->nullable();
            $table->string('approved_by', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
