<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['SUPPLIER_CREDIT_NOTE' => 'ใบลดหนี้ผู้ขาย', 'SUPPLIER_DEBIT_NOTE' => 'ใบเพิ่มหนี้ผู้ขาย'] as $code => $name) {
            DB::table('document_types')->insertOrIgnore(['code' => $code, 'name_th' => $name, 'affects_ap' => true]);
        }
        Schema::create('supplier_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('documents')->restrictOnDelete();
            $table->foreignId('supplier_open_item_id')->constrained('supplier_open_items')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('kind', 10);
            $table->decimal('base_amount', 18, 2);
            $table->decimal('vat_amount', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_notes');
    }
};
