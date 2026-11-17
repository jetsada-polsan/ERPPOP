<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bplus_sync_documents', function (Blueprint $table) {
            $table->string('salesman_code', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bplus_sync_documents', function (Blueprint $table) {
            $table->dropColumn('salesman_code');
        });
    }
};
