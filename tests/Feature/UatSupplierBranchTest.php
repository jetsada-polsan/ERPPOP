<?php

namespace Tests\Feature;

use App\Http\Middleware\ErpAuthorize;
use App\Models\{Branch, ChartOfAccount, Document, DocumentType, Permission, Role, Supplier, SupplierOpenItem, User};
use App\Services\Purchasing\SupplierNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UatSupplierBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_wide_user_with_a_home_branch_sees_and_filters_hq_sources(): void
    {
        [$user, $home, $hq, $source, $item] = $this->masters();
        $role = $user->roles()->first();
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'reports.all_branches'], ['name' => 'ทุกสาขา'])->id);
        $this->actingAs($user->fresh())->get(route('supplier-notes.index'))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->contains('id', $item->id))->assertSee('name="branch_id"', false);
        $this->get(route('supplier-notes.index', ['branch_id' => $home->id]))->assertOk()->assertViewHas('items', fn ($items) => $items->isEmpty());
        $this->get(route('supplier-notes.index', ['branch_id' => $hq->id]))->assertOk()->assertViewHas('items', fn ($items) => $items->contains('id', $item->id));
        $expense = ChartOfAccount::create(['code' => 'EXP-UAT', 'name_th' => 'ค่าใช้จ่าย', 'account_type' => 'expense']);
        $note = app(SupplierNoteService::class)->create(['supplier_open_item_id' => $item->id, 'account_id' => $expense->id,
            'kind' => 'credit', 'base_amount' => '5.00', 'vat_amount' => 0, 'reason' => 'ปรับราคา'], $user->fresh());
        $this->assertSame($hq->id, (int) $note->branch_id);
    }

    public function test_branch_user_cannot_list_or_submit_foreign_supplier_sources(): void
    {
        [$user, $home, $hq, $source, $item] = $this->masters();
        $this->actingAs($user)->get(route('supplier-notes.index'))->assertOk()->assertViewHas('items', fn ($items) => $items->isEmpty());
        $this->get(route('supplier-notes.index', ['branch_id' => $hq->id]))->assertForbidden();
        $expense = ChartOfAccount::create(['code' => 'EXP-UAT', 'name_th' => 'ค่าใช้จ่าย', 'account_type' => 'expense']);
        $this->expectException(\RuntimeException::class);
        app(SupplierNoteService::class)->create(['supplier_open_item_id' => $item->id, 'account_id' => $expense->id,
            'kind' => 'credit', 'base_amount' => 5, 'vat_amount' => 0, 'reason' => 'ปลอมสาขา'], $user);
    }

    public function test_explicit_multiple_branch_roles_allow_only_assigned_sources(): void
    {
        [$user, $home, $hq, $source, $item] = $this->masters();
        $role = $user->roles()->first();
        $user->branchRoles()->attach($role->id, ['branch_id' => $home->id, 'is_active' => true]);
        $user->branchRoles()->attach($role->id, ['branch_id' => $hq->id, 'is_active' => true]);
        $this->actingAs($user->fresh())->get(route('supplier-notes.index'))->assertOk()
            ->assertViewHas('items', fn ($items) => $items->contains('id', $item->id));
        $user->branchRoles()->updateExistingPivot($role->id, ['is_active' => false]);
        $this->actingAs($user->fresh())->get(route('supplier-notes.index'))->assertOk()->assertViewHas('items', fn ($items) => $items->isEmpty());
    }

    public function test_approval_policy_accepts_an_active_assigned_branch_and_rejects_revoked_assignments(): void
    {
        [$user, $home, $hq] = $this->masters();
        $role = $user->roles()->first();
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'finance.note.approve'], ['name' => 'อนุมัติ'])->id);
        $user->branchRoles()->attach($role->id, ['branch_id' => $hq->id, 'is_active' => true]);
        $policy = app(\App\Services\Documents\ApprovalPolicyService::class);
        $policy->authorize('SUPPLIER_CREDIT_NOTE', '5.00', $hq->id, null, $user->fresh(), 'finance.note.approve');
        $this->assertTrue(true);
        $user->branchRoles()->updateExistingPivot($role->id, ['is_active' => false]);
        $this->expectException(\RuntimeException::class);
        $policy->authorize('SUPPLIER_CREDIT_NOTE', '5.00', $hq->id, null, $user->fresh(), 'finance.note.approve');
    }

    private function masters(): array
    {
        $this->withoutMiddleware(ErpAuthorize::class);
        $home = Branch::create(['code' => 'B001', 'name_th' => 'บ้าน', 'is_active' => true]);
        $hq = Branch::create(['code' => 'HQ', 'name_th' => 'สำนักงานใหญ่', 'is_active' => true]);
        $user = User::factory()->create(['branch_id' => $home->id, 'must_change_password' => false, 'is_active' => true]);
        $role = Role::create(['code' => 'FIN-UAT', 'name' => 'การเงิน']);
        $role->permissions()->attach(Permission::firstOrCreate(['code' => 'purchasing.manage'], ['name' => 'การเงิน'])->id);
        $user->roles()->attach($role);
        $supplier = Supplier::create(['code' => 'SUP-UAT', 'name_th' => 'ผู้ขาย']);
        $type = DocumentType::firstOrCreate(['code' => 'PURCHASE'], ['name_th' => 'ซื้อ']);
        $source = Document::create(['document_type_id' => $type->id, 'branch_id' => $hq->id, 'doc_number' => 'HQ-PURCHASE', 'doc_date' => now(),
            'supplier_id' => $supplier->id, 'total_amount' => 20, 'status' => 'active']);
        $item = SupplierOpenItem::create(['supplier_id' => $supplier->id, 'source_document_id' => $source->id, 'document_no' => 'HQ-PURCHASE',
            'document_date' => now(), 'original_amount' => 20, 'paid_amount' => 0, 'balance_amount' => 20, 'status' => 'open']);
        return [$user->fresh(), $home, $hq, $source, $item];
    }
}
