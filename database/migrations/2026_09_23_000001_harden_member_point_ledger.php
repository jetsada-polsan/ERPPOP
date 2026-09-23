<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_point_transactions', function (Blueprint $table) {
            $table->string('source', 40)->nullable()->after('direction');
            $table->string('source_id', 100)->nullable()->after('source');
            $table->foreignId('reversal_of_id')->nullable()->after('document_id')
                ->constrained('member_point_transactions')->nullOnDelete();
            $table->index(['member_id', 'source', 'source_id']);
            $table->unique(['member_id', 'source', 'source_id', 'direction'], 'member_point_tx_idempotency');
        });
    }

    public function down(): void
    {
        Schema::table('member_point_transactions', function (Blueprint $table) {
            $table->dropUnique('member_point_tx_idempotency');
            $table->dropIndex(['member_id', 'source', 'source_id']);
            $table->dropForeign(['reversal_of_id']);
            $table->dropColumn(['source', 'source_id', 'reversal_of_id']);
        });
    }
};
