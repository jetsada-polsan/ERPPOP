<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_employee_with_the_next_system_code(): void
    {
        $this->withoutMiddleware();
        $response = $this->post(route('employees.store'), [
            'full_name' => 'พนักงานใหม่',
            'department' => '__other__',
            'department_other' => 'ควบคุมคุณภาพ',
            'monthly_salary' => 18000,
            'status' => 'Active',
            'social_security_enabled' => '1',
        ]);

        $response->assertRedirect(route('employees.index'));
        $this->assertDatabaseHas('employees', [
            'employee_code' => 'POP002',
            'full_name' => 'พนักงานใหม่',
            'department' => 'ควบคุมคุณภาพ',
            'monthly_salary' => 18000,
            'social_security_enabled' => true,
        ]);

        $this->post(route('employees.store'), [
            'full_name' => 'พนักงานคนถัดไป',
            'status' => 'Active',
        ])->assertRedirect(route('employees.index'));
        $this->assertDatabaseHas('employees', [
            'employee_code' => 'POP003',
            'full_name' => 'พนักงานคนถัดไป',
        ]);
    }

    public function test_employee_can_be_edited(): void
    {
        $this->withoutMiddleware();
        $this->post(route('employees.store'), ['full_name' => 'ก่อนแก้', 'status' => 'Active']);
        $employee = \App\Models\Employee::where('full_name', 'ก่อนแก้')->sole();

        $user = \App\Models\User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->roles()->attach(\App\Models\Role::where('code', 'GM')->value('id'));
        $this->withMiddleware()->actingAs($user)->put(route('employees.update', $employee), ['full_name' => 'หลังแก้', 'status' => 'Resigned'])->assertRedirect(route('employees.index'));

        $this->assertSame('หลังแก้', $employee->fresh()->full_name);
        $this->assertSame('Resigned', $employee->fresh()->status);
    }

    public function test_employee_index_renders_the_edit_flow_with_the_update_route(): void
    {
        $this->withoutMiddleware();
        $this->get(route('employees.index'))
            ->assertOk()
            ->assertSee('openEdit(', false)
            ->assertSee(':action="editAction ||', false)
            ->assertSee('แก้ไขข้อมูลพนักงาน');
    }
}
