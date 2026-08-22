<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * erp:health ต้องจับได้เองเมื่อมีเอกสารขายที่ไม่ได้ลง GL
 *
 * บน production มีบิล POS 5 ใบ (6-12 ก.ค. 2026) ที่ขายก่อนระบบต่อ GL รวม 2,383 บาท
 * ตอนนั้นไม่มีอะไรจับได้เลย กว่าจะรู้ก็ตอนกระทบยอดสองเดือนถัดมา
 */
class ErpHealthSalesLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_passes_when_every_sale_is_posted(): void
    {
        $this->artisan('erp:health')->expectsOutputToContain('เอกสารขายลง GL ครบ');
    }

    public function test_health_fails_and_names_the_amount_when_a_sale_never_reached_the_ledger(): void
    {
        $branch = Branch::create(['code' => 'HL', 'name_th' => 'สาขาทดสอบ', 'is_active' => true]);
        $type = DocumentType::create(['code' => 'CASH_SALE', 'name_th' => 'ขายสด']);
        Document::create([
            'document_type_id' => $type->id, 'branch_id' => $branch->id,
            'doc_number' => 'CS-NOGL-1', 'doc_date' => '2026-07-06', 'status' => 'active',
            'total_items' => 1, 'total_amount' => 102,
        ]);

        $this->artisan('erp:health')
            ->expectsOutputToContain('เอกสารขาย 1 ใบไม่มีรายการ GL')
            ->assertExitCode(1);
    }
}
