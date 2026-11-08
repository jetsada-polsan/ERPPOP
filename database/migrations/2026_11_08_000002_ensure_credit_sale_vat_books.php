<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $typeId = DB::table('document_types')->where('code', 'CREDIT_SALE')->value('id');
        if (! $typeId) {
            return;
        }

        foreach ([
            ['code' => 'DS', 'name' => 'ใบขายเชื่อ (เล่มหลัก)', 'prefix' => 'DS', 'no_vat' => false, 'is_default' => true],
            ['code' => 'DSN', 'name' => 'ใบขายเชื่อ (เล่ม N)', 'prefix' => 'DSN', 'no_vat' => true, 'is_default' => false],
        ] as $book) {
            $exists = DB::table('document_books')
                ->where('document_type_id', $typeId)->where('code', $book['code'])->exists();
            if (! $exists) {
                DB::table('document_books')->insert([
                    ...$book, 'document_type_id' => $typeId, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // DS and DSN can contain production documents; never remove them in rollback.
    }
};
