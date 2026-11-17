<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** token ของเครื่องในร้านที่ส่งเอกสาร BPlus ขึ้นมาเอง และประวัติการส่งแต่ละรอบ */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bplus_sync_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('route_code', 20);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bplus_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bplus_sync_token_id')->nullable()->constrained('bplus_sync_tokens')->nullOnDelete();
            $table->string('route_code', 20);
            $table->string('status', 20);
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('erp_documents')->default(0);
            $table->unsignedInteger('problems')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bplus_sync_runs');
        Schema::dropIfExists('bplus_sync_tokens');
    }
};
