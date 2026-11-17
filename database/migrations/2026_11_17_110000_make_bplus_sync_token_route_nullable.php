<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bplus_sync_tokens', function (Blueprint $table) {
            $table->string('route_code', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep the column nullable on rollback so existing all-route credentials are not
        // silently narrowed to a route or deleted. Scoped tokens remain fully compatible.
    }
};
