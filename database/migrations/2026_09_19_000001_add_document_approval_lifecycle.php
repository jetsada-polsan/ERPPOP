<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite cannot rebuild a table while a view still references it.
        // Preserve and restore the reporting view around the ALTER TABLE.
        $view = $this->salesPostingsViewDefinition();
        if ($view !== null) {
            DB::statement('DROP VIEW sales_postings');
        }
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('submitted_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_note')->nullable()->after('approved_at');
            $table->index(['status', 'branch_id']);
        });
        if ($view !== null) {
            DB::statement($view);
        }
    }

    public function down(): void
    {
        $view = $this->salesPostingsViewDefinition();
        if ($view !== null) {
            DB::statement('DROP VIEW sales_postings');
        }
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['status', 'branch_id']);
            $table->dropColumn(['submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'approval_note']);
        });
        if ($view !== null) {
            DB::statement($view);
        }
    }

    private function salesPostingsViewDefinition(): ?string
    {
        if (! Schema::hasTable('documents')) {
            return null;
        }

        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            $sql = DB::table('sqlite_master')->where('type', 'view')->where('name', 'sales_postings')->value('sql');
        } else {
            $sql = DB::selectOne("select definition from pg_views where schemaname = 'public' and viewname = 'sales_postings'")?->definition;
            $sql = $sql ? 'CREATE VIEW sales_postings AS '.$sql : null;
        }

        return $sql ?: null;
    }
};
