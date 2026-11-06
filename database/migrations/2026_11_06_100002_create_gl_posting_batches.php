<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_posting_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id')->index();
            $table->string('component', 80);
            $table->string('fingerprint', 64);
            $table->date('entry_date');
            $table->timestamp('created_at');
        });
        Schema::table('gl_journals', function (Blueprint $table) {
            $table->unsignedBigInteger('posting_batch_id')->nullable()->index();
            $table->unsignedBigInteger('reversal_of_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('gl_journals', function (Blueprint $table) {
            $table->dropColumn(['posting_batch_id', 'reversal_of_id']);
        });
        Schema::dropIfExists('gl_posting_batches');
    }
};
