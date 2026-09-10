<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('transport_load_items', function (Blueprint $t) { $t->id(); $t->foreignId('transport_job_id')->constrained('transport_jobs')->cascadeOnDelete(); $t->foreignId('stock_document_item_id')->constrained('stock_document_items')->cascadeOnDelete(); $t->decimal('planned_qty',20,8); $t->decimal('loaded_qty',20,8)->default(0); $t->string('availability',12)->default('pending'); $t->text('note')->nullable(); $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps(); $t->unique(['transport_job_id','stock_document_item_id']); }); } public function down(): void { Schema::dropIfExists('transport_load_items'); } };
