<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->string('accounting_review_status', 20)->default('pending')->after('delivery_status');
            $table->foreignId('accounting_reviewed_by')->nullable()->after('accounting_review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('accounting_reviewed_at')->nullable()->after('accounting_reviewed_by');
            $table->text('accounting_review_note')->nullable()->after('accounting_reviewed_at');
            $table->index(['accounting_review_status', 'status'], 'sale_bookings_accounting_review_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sale_bookings', function (Blueprint $table) {
            $table->dropForeign(['accounting_reviewed_by']);
            $table->dropIndex('sale_bookings_accounting_review_idx');
            $table->dropColumn(['accounting_review_status', 'accounting_reviewed_by', 'accounting_reviewed_at', 'accounting_review_note']);
        });
    }
};
