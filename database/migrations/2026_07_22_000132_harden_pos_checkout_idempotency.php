<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_api_idempotency', function (Blueprint $table) {
            $table->string('request_hash', 64)->nullable()->after('endpoint');
            $table->string('state', 20)->default('completed')->after('request_hash');
            $table->timestamp('updated_at')->nullable()->after('created_at');
            $table->index(['pos_device_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_api_idempotency', function (Blueprint $table) {
            $table->dropIndex(['pos_device_id', 'state']);
            $table->dropColumn(['request_hash', 'state', 'updated_at']);
        });
    }
};
