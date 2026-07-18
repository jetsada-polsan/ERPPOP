<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->string('count_mode', 30)->default('partial')->after('status');
            $table->foreignId('submitted_by')->nullable()->after('count_mode')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('confirmed_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
        });
    }
    public function down(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['count_mode','submitted_at','confirmed_at']);
        });
    }
};
