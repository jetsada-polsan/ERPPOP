<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bplus_sync_documents', 'sale_ref')) {
            Schema::table('bplus_sync_documents', function (Blueprint $table) {
                $table->string('sale_ref', 40)->nullable()->index();
            });
        }

        $typeId = DB::table('document_types')->where('code', 'CREDIT_NOTE')->value('id');
        if (! $typeId) {
            return;
        }

        foreach ([
            ['code' => 'IS', 'name' => 'ใบลดหนี้ (เล่มมี VAT)', 'no_vat' => false],
            ['code' => 'IS-N', 'name' => 'ใบลดหนี้ (เล่มไม่มี VAT)', 'no_vat' => true],
        ] as $book) {
            DB::table('document_books')->updateOrInsert(
                ['document_type_id' => $typeId, 'code' => $book['code']],
                [
                    'name' => $book['name'],
                    'prefix' => $book['code'],
                    'is_default' => false,
                    'is_active' => true,
                    'no_vat' => $book['no_vat'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        $typeId = DB::table('document_types')->where('code', 'CREDIT_NOTE')->value('id');
        if ($typeId) {
            DB::table('document_books')->where('document_type_id', $typeId)->whereIn('code', ['IS', 'IS-N'])->delete();
        }

        if (Schema::hasColumn('bplus_sync_documents', 'sale_ref')) {
            Schema::table('bplus_sync_documents', function (Blueprint $table) {
                $table->dropIndex(['sale_ref']);
                $table->dropColumn('sale_ref');
            });
        }
    }
};
