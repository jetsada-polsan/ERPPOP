<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Price tag templates (ป้ายราคา): named shelf-label styles that decide which
// price a printed tag shows for a product - the branch's default price, a
// specific price table (e.g. สมาชิก/ขายเชื่อ), the active flash-sale price,
// or no price at all. Actual printing is generated on demand from
// PriceTagController@preview, not stored.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_tag_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('price_source', 20)->default('default'); // default|price_table|flash_sale|no_price
            $table->foreignId('price_table_id')->nullable()->constrained('price_tables')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_tag_templates');
    }
};
