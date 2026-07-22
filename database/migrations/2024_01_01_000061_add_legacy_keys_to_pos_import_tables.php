<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Pivot: POS Import now pulls from BPlus MSSQL H/D/P tables (read-only) instead of parsing
// undocumented .cds binary files. These legacy_*_key columns track the source MSSQL row
// (PSH_KEY/PSD_KEY/PSP_KEY) so re-running a sync never double-imports the same record.
return new class extends Migration
{
    public function up(): void
    {
        // Postgres (prod) uses the exact raw ALTER; other drivers (sqlite in tests)
        // relax NOT NULL through the schema builder since they reject that raw syntax.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_batches ALTER COLUMN file_hash DROP NOT NULL');
        } else {
            Schema::table('import_batches', fn (Blueprint $table) => $table->string('file_hash', 64)->nullable()->change());
        }

        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('source_system', 20)->default('mssql_pos')->after('pos_terminal_id');
            $table->unique(['pos_code', 'sale_date'], 'import_batches_pos_date_unique');
        });

        Schema::table('imported_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_psh_key')->nullable()->after('batch_id');
            $table->index('legacy_psh_key');
        });

        Schema::table('imported_receipt_items', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_psd_key')->nullable()->after('receipt_id');
            $table->index('legacy_psd_key');
        });

        Schema::table('imported_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_psp_key')->nullable()->after('receipt_id');
            $table->index('legacy_psp_key');
        });
    }

    public function down(): void
    {
        Schema::table('imported_payments', function (Blueprint $table) {
            $table->dropIndex(['legacy_psp_key']);
            $table->dropColumn('legacy_psp_key');
        });

        Schema::table('imported_receipt_items', function (Blueprint $table) {
            $table->dropIndex(['legacy_psd_key']);
            $table->dropColumn('legacy_psd_key');
        });

        Schema::table('imported_receipts', function (Blueprint $table) {
            $table->dropIndex(['legacy_psh_key']);
            $table->dropColumn('legacy_psh_key');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropUnique('import_batches_pos_date_unique');
            $table->dropColumn('source_system');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_batches ALTER COLUMN file_hash SET NOT NULL');
        } else {
            Schema::table('import_batches', fn (Blueprint $table) => $table->string('file_hash', 64)->nullable(false)->change());
        }
    }
};
