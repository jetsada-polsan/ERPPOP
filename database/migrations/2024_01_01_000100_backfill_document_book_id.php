<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents') || ! Schema::hasTable('document_books')) {
            return;
        }

        $books = DB::table('document_books')
            ->where('is_default', true)
            ->get(['id', 'document_type_id']);

        foreach ($books as $book) {
            DB::table('documents')
                ->where('document_type_id', $book->document_type_id)
                ->whereNull('document_book_id')
                ->update(['document_book_id' => $book->id]);
        }
    }

    public function down(): void
    {
        // Keep the historical book assignment. It is data cleanup, not schema.
    }
};
