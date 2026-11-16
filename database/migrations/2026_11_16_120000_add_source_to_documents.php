<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// เอกสารที่สร้างจากระบบอื่น (BPlus): บอกที่มาและคีย์เดิม ใช้กันสร้างซ้ำและกันแก้ใน ERP
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('source_system', 20)->nullable();
            $table->unsignedBigInteger('source_key')->nullable();
            $table->unique(['source_system', 'source_key'], 'documents_source_unique');
        });
        Schema::table('bplus_sync_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('problem')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bplus_sync_documents', function (Blueprint $table) {
            $table->dropIndex(['document_id']);
            $table->dropColumn(['document_id', 'problem']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_source_unique');
            $table->dropColumn(['source_system', 'source_key']);
        });
    }
};
