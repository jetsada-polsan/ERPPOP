<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DocumentBook;
use App\Models\DocumentType;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ข้อ 16: เลขใบจองต้องเป็น BK + สาขา + วันที่ + ลำดับ นับแยกสาขา เหมือนเอกสารอื่น
 * ทุกเล่มใบจองของสาขาใช้ตัวนับเดียวกัน ไม่งั้นได้เลขซ้ำ
 */
class BookingNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_numbers_follow_the_branch_and_date_format_and_never_repeat_across_books(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00');
        $amnat = Branch::create(['code' => 'B001', 'name_th' => 'สาขาอำนาจเจริญ', 'is_active' => true]);
        $other = Branch::create(['code' => 'B002', 'name_th' => 'สาขาสอง', 'is_active' => true]);
        $type = DocumentType::firstOrCreate(['code' => 'BOOKING'], ['name_th' => 'ใบจอง', 'stock_effect' => 'none']);
        $bookA = DocumentBook::firstOrCreate(['code' => 'B', 'document_type_id' => $type->id], ['name' => 'ใบจอง', 'prefix' => 'B', 'is_default' => true, 'is_active' => true]);
        $bookB = DocumentBook::create(['code' => 'BN', 'name' => 'ใบจองสายเหนือ', 'document_type_id' => $type->id, 'prefix' => 'BN', 'is_default' => false, 'is_active' => true]);
        $numbers = app(DocumentNumberGenerator::class);

        $this->assertSame('BKB00120261105001', $numbers->next('BOOKING', $amnat->id));
        $this->assertSame('BKB00120261105002', $numbers->nextInBook($bookA, $amnat->id));
        $this->assertSame('BKB00120261105003', $numbers->nextInBook($bookB, $amnat->id));
        $this->assertSame('BKB00220261105001', $numbers->next('BOOKING', $other->id));
    }
}
