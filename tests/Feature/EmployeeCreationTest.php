<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_create_employee_with_the_next_system_code(): void
    {
        $this->withoutMiddleware();
        Employee::where('employee_code', 'EMP0107')->update(['full_name' => 'พนักงานเดิม']);

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
            'employee_code' => 'EMP0108',
            'full_name' => 'พนักงานใหม่',
            'department' => 'ควบคุมคุณภาพ',
            'monthly_salary' => 18000,
            'social_security_enabled' => true,
        ]);
    }
}
