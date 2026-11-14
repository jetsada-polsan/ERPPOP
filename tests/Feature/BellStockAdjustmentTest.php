<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** เจ้าของ 14 พ.ย.: ใบตรวจนับยอดยกมารออนุมัติ ไม่เด้งที่กระดิ่ง */
class BellStockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_stock_adjustment_shows_in_the_bell_for_approver_and_creator(): void
    {
        $type = DocumentType::firstOrCreate(['code' => 'STOCK_ADJUSTMENT'], ['name_th' => 'ปรับสต๊อก', 'stock_effect' => 'adjust']);
        $branch = Branch::create(['code' => 'BEL', 'name_th' => 'สาขา', 'is_active' => true]);
        $maker = User::factory()->create(['is_active' => true]);
        $maker->roles()->attach(Role::where('code', 'GM')->value('id'));
        $checker = User::factory()->create(['is_active' => true]);
        $checker->roles()->attach(Role::where('code', 'GM')->value('id'));
        Document::create(['document_type_id' => $type->id, 'branch_id' => $branch->id, 'doc_number' => 'AJB-T1',
            'doc_date' => now()->toDateString(), 'status' => 'pending_approval', 'created_by' => $maker->id]);

        $labels = fn (User $u) => collect(app(NotificationService::class)->forUser($u))->pluck('count', 'label');
        $this->assertSame(1, $labels($checker)['ใบปรับสต๊อกรออนุมัติ'] ?? null);
        $this->assertArrayNotHasKey('ใบปรับสต๊อกรออนุมัติ', $labels($maker)->all(), 'ผู้สร้างอนุมัติเองไม่ได้');
        $this->assertSame(1, $labels($maker)['ใบปรับสต๊อกของคุณ รอคนอื่นอนุมัติ'] ?? null);
    }
}
