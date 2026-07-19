<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('shelf_life_days')->nullable()->after('tracks_expiry');
            $table->unsignedInteger('clearance_warning_days')->default(7)->after('expiry_warning_days');
            $table->decimal('clearance_discount_percent', 5, 2)->default(0)->after('clearance_warning_days');
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->date('manufacture_date')->nullable()->after('received_date');
            $table->string('quality_status', 20)->default('available')->after('expiry_date');
            $table->text('quality_reason')->nullable()->after('quality_status');
            $table->foreignId('quality_updated_by')->nullable()->after('quality_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('quality_updated_at')->nullable()->after('quality_updated_by');
            $table->index(['quality_status', 'remaining_qty'], 'stock_lots_quality_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropIndex('stock_lots_quality_idx');
            $table->dropConstrainedForeignId('quality_updated_by');
            $table->dropColumn(['manufacture_date', 'quality_status', 'quality_reason', 'quality_updated_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['shelf_life_days', 'clearance_warning_days', 'clearance_discount_percent']);
        });
    }
};
