<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $department = trim((string) $request->query('department'));
        $branchId = $request->integer('branch_id') ?: null;

        $employees = Employee::with(['branch:id,code,name_th','user:id,username'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('employee_code', 'ilike', "%{$q}%")
                ->orWhere('full_name', 'ilike', "%{$q}%")
                ->orWhere('nickname', 'ilike', "%{$q}%")
                ->orWhere('phone', 'ilike', "%{$q}%")))
            ->when($department !== '', fn ($query) => $query->where('department', $department))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('employee_code')->paginate(50)->withQueryString();

        return view('employees.index', [
            'employees' => $employees,
            'departments' => Employee::whereNotNull('department')->where('department', '<>', '')->distinct()->orderBy('department')->pluck('department'),
            'branches' => Branch::orderBy('code')->get(['id','code','name_th']),
            'summary' => [
                'total' => Employee::count(),
                'linked_branch' => Employee::whereNotNull('branch_id')->count(),
                'linked_user' => Employee::whereNotNull('user_id')->count(),
                'unassigned' => Employee::whereNull('branch_id')->count(),
            ],
        ]);
    }
}
