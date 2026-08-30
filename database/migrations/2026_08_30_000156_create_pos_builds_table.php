<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_builds', function (Blueprint $table) {
            $table->id();
            $table->uuid('build_uuid')->unique();
            $table->string('version', 30);
            $table->string('channel', 20)->default('uat');
            $table->string('source_ref', 120)->default('main');
            $table->string('status', 30)->default('queued');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('github_run_id')->nullable()->unique();
            $table->string('github_run_url', 500)->nullable();
            $table->string('commit_sha', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['channel', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_builds');
    }
};
