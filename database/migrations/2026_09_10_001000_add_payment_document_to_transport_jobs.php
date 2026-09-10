<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('transport_jobs', fn(Blueprint $t) => $t->foreignId('payment_document_id')->nullable()->constrained('documents')->nullOnDelete()); } public function down(): void { Schema::table('transport_jobs', fn(Blueprint $t) => $t->dropConstrainedForeignId('payment_document_id')); } };
