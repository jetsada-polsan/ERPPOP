<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->timestamp('pos_credential_version')->nullable()->after('pin_changed_at');
        });

        DB::table('salesmen')
            ->whereNotNull('pos_pin_hash')
            ->whereNull('pos_credential_version')
            ->update(['pos_credential_version' => DB::raw('coalesce(pin_changed_at, current_timestamp)')]);
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn('pos_credential_version');
        });
    }
};
