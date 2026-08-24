<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowDefinitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_seeded_workflows(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/settings/workflows')
            ->assertOk()
            ->assertSee('ใบขอโอนสินค้า')
            ->assertSee('stock.manage');
    }

    public function test_admin_can_update_workflow_mode_steps_permission_and_active_state(): void
    {
        $admin = $this->admin();
        $definition = WorkflowDefinition::where('document_type_code', 'STOCK_TRANSFER')->sole();

        $this->actingAs($admin)->put(route('settings.workflows.update', $definition), [
            'mode' => 'approval', 'approval_permission' => 'stock.manage', 'is_active' => 1,
            'steps' => "ผู้ขอ\nผู้จัดการสาขา\nคลังรับโอน",
        ])->assertRedirect();

        $this->assertSame(['ผู้ขอ', 'ผู้จัดการสาขา', 'คลังรับโอน'], $definition->fresh()->steps);
        $this->assertSame('stock.manage', $definition->fresh()->approval_permission);
        $this->assertDatabaseHas('audit_logs', ['action' => 'workflow.updated', 'record_id' => $definition->id]);
    }

    public function test_approval_workflow_requires_two_steps(): void
    {
        $admin = $this->admin();
        $definition = WorkflowDefinition::where('document_type_code', 'STOCK_DAMAGE')->sole();

        $this->actingAs($admin)->put(route('settings.workflows.update', $definition), [
            'mode' => 'approval', 'is_active' => 1, 'steps' => 'ผู้ทำรายการ',
        ])->assertStatus(422);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['username' => 'wf_'.uniqid(), 'is_active' => true, 'must_change_password' => false]);
        $role = Role::create(['code' => 'WF_'.strtoupper(uniqid()), 'name' => 'Workflow test admin']);
        $permission = Permission::firstOrCreate(['code' => 'settings.manage'], ['name' => 'จัดการตั้งค่า']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);

        return $user;
    }
}
