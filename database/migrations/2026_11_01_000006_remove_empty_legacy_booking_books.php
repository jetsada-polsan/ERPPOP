<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacy = DB::table('document_books')
            ->where('code', 'like', 'BOOKING-TMP-%');

        $emptyIds = (clone $legacy)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw('1'))
                    ->from('documents')
                    ->whereColumn('documents.document_book_id', 'document_books.id');
            })
            ->pluck('id');

        if ($emptyIds->isNotEmpty()) {
            DB::table('document_books')->whereIn('id', $emptyIds)->delete();
        }

        DB::table('document_books')
            ->where('code', 'like', 'BOOKING-TMP-%')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Removed empty legacy books cannot be restored without inventing history.
    }
};
