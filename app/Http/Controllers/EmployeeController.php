<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $department = trim((string) $request->query('department'));
        $branchId = $request->integer('branch_id') ?: null;

        $employees = Employee::with(['branch:id,code,name_th', 'user:id,username'])
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
            'branches' => Branch::orderBy('code')->get(['id', 'code', 'name_th']),
            'summary' => [
                'total' => Employee::count(),
                'linked_branch' => Employee::whereNotNull('branch_id')->count(),
                'linked_user' => Employee::whereNotNull('user_id')->count(),
                'unassigned' => Employee::whereNull('branch_id')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:200'],
            'nickname' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
            'alt_phone' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'nationality' => ['nullable', 'string', 'max:50'],
            'birth_date_raw' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department' => ['nullable', 'string', 'max:150'],
            'department_other' => ['nullable', 'string', 'max:150'],
            'position' => ['nullable', 'string', 'max:150'],
            'employment_type' => ['nullable', 'string', 'max:100'],
            'wage_type' => ['nullable', 'string', 'max:50'],
            'wage_amount' => ['nullable', 'numeric', 'min:0'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'social_security_enabled' => ['nullable', 'boolean'],
            'start_date_raw' => ['nullable', 'date'],
            'status' => ['required', 'string', 'max:30'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['department'] = $data['department'] === '__other__'
            ? trim((string) ($data['department_other'] ?? ''))
            : ($data['department'] ?: null);
        unset($data['department_other']);
        $data['social_security_enabled'] = $request->boolean('social_security_enabled', true);

        $employee = DB::transaction(function () use ($data) {
            // Serialize code generation so concurrent submissions cannot choose the same EMP number.
            DB::table('employees')->orderBy('id')->lockForUpdate()->get(['id']);
            $data['employee_code'] = $this->nextEmployeeCode();

            return Employee::create($data);
        });

        return redirect()->route('employees.index')
            ->with('success', "เพิ่มพนักงาน {$employee->employee_code} - {$employee->full_name} แล้ว");
    }

    // เลขพนักงานรันอัตโนมัติ EMP#### ต่อจากเลขสูงสุดที่มีอยู่เสมอ (ห้าม user กรอกเอง)
    private function nextEmployeeCode(): string
    {
        $max = DB::table('employees')->where('employee_code', 'like', 'EMP%')
            ->pluck('employee_code')
            ->reduce(function (int $max, string $code): int {
                return preg_match('/^EMP(\d+)$/', $code, $matches)
                    ? max($max, (int) $matches[1])
                    : $max;
            }, 0);

        return 'EMP'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
