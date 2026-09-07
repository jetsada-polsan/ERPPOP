<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $bookingTypeId = DB::table('document_types')->where('code', 'BOOKING')->value('id');
        if ($bookingTypeId) {
            $books = DB::table('document_books')->where('document_type_id', $bookingTypeId)
                ->orderBy('id')->get(['id', 'code']);
            $targetId = $books->firstWhere('code', 'B')?->id ?? $books->first()?->id;
            foreach ($books as $book) {
                DB::table('document_books')->where('id', $book->id)->update([
                    'code' => 'BOOKING-TMP-'.$book->id,
                    'name' => 'ใบจอง (Booking)',
                    'prefix' => 'B',
                    'updated_at' => now(),
                ]);
            }
            if ($targetId) {
                DB::table('document_books')->where('id', $targetId)->update(['code' => 'B', 'updated_at' => now()]);
            }
        }

        DB::table('branches')->where('code', 'B001')->update(['name_th' => 'สาขาวาริน', 'updated_at' => now()]);
        DB::table('branches')->where('code', 'HQ')->update(['name_th' => 'สนญ.', 'updated_at' => now()]);

        DB::table('warehouses')->where(function ($query) {
            $query->where('name', 'like', '%คลังกลาง%')
                ->orWhereIn('branch_id', DB::table('branches')->where('code', 'HQ')->pluck('id'));
        })->update(['name' => 'คลังสำนักงานใหญ่']);

        DB::table('warehouse_locations')->where('name', 'like', '%คลังกลาง%')
            ->update(['name' => DB::raw("replace(name, 'คลังกลาง', 'คลังสำนักงานใหญ่')")]);
    }

    public function down(): void
    {
        // Master names and new booking numbers are intentionally not guessed back.
    }
};
