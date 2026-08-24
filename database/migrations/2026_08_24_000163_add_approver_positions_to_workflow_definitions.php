<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table): void {
            $table->json('approver_positions')->nullable()->after('approval_permission');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_definitions', fn (Blueprint $table) => $table->dropColumn('approver_positions'));
    }
};
