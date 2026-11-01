<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// BPlus ปี 2026: เล่ม N (DSN, CSN, IS-N) มี VAT 0 จริง เล่มปกติคิด VAT ตามสินค้า
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_books', function (Blueprint $table) {
            $table->boolean('no_vat')->default(false);
        });
        DB::table('document_books')->whereIn('code', ['DSN', 'CSN', 'IS-N'])->update(['no_vat' => true]);
    }

    public function down(): void
    {
        Schema::table('document_books', fn (Blueprint $table) => $table->dropColumn('no_vat'));
    }
};
