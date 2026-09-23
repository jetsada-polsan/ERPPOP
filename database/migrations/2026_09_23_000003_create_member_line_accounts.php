<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_line_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('line_user_id', 100)->unique();
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamp('unlinked_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['member_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_line_accounts');
    }
};
