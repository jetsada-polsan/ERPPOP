<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeOrgAssignment;
use App\Models\OrganizationalUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationalUnitController extends Controller
{
    public function index(): View
    {
        $units = OrganizationalUnit::with(['parent:id,name','branch:id,code,name_th','manager:id,employee_code,full_name','positions.holder:id,employee_code,full_name,nickname'])
            ->withCount('assignments')->orderBy('sort_order')->orderBy('name')->get();

        return view('organizational-units.index', [
            'units' => $units,
            'roots' => $units->whereNull('parent_id'),
            'orgRows' => $this->flatten($units),
            'employees' => Employee::orderBy('employee_code')->get(['id','employee_code','full_name','department','position']),
            'branches' => Branch::orderBy('code')->get(['id','code','name_th']),
            'assignments' => EmployeeOrgAssignment::with(['employee:id,employee_code,full_name,branch_id','employee.branch:id,code,name_th','organizationalUnit:id,code,name'])
                ->orderByDesc('is_primary')->orderBy('employee_id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        OrganizationalUnit::create($this->validated($request));
        return back()->with('success', 'เพิ่มหน่วยงานแล้ว');
    }

    public function update(Request $request, OrganizationalUnit $organizationalUnit): RedirectResponse
    {
        $data = $this->validated($request, $organizationalUnit);
        if (($data['parent_id'] ?? null) && in_array((int) $data['parent_id'], $this->descendantIds($organizationalUnit), true)) {
            return back()->withErrors(['parent_id'=>'เลือกหน่วยงานลูกเป็นหน่วยงานแม่ไม่ได้']);
        }
        $organizationalUnit->update($data);
        return back()->with('success', 'บันทึกผังองค์กรแล้ว');
    }

    public function destroy(OrganizationalUnit $organizationalUnit): RedirectResponse
    {
        if ($organizationalUnit->children()->exists() || $organizationalUnit->assignments()->exists()) {
            return back()->withErrors(['organization'=>'ลบไม่ได้ เพราะยังมีหน่วยงานลูกหรือพนักงานในหน่วยงาน']);
        }
        $organizationalUnit->delete();
        return back()->with('success', 'ลบหน่วยงานแล้ว');
    }

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required','integer','exists:employees,id'],
            'organizational_unit_id' => ['required','integer','exists:organizational_units,id'],
            'position_title' => ['nullable','string','max:150'],
            'is_primary' => ['nullable','boolean'],
        ]);
        $isPrimary = $request->boolean('is_primary');
        DB::transaction(function () use ($data, $isPrimary) {
            if ($isPrimary) EmployeeOrgAssignment::where('employee_id', $data['employee_id'])->update(['is_primary'=>false]);
            EmployeeOrgAssignment::updateOrCreate(
                ['employee_id'=>$data['employee_id'],'organizational_unit_id'=>$data['organizational_unit_id']],
                ['position_title'=>$data['position_title'] ?? null,'is_primary'=>$isPrimary]
            );
        });
        return back()->with('success', 'บันทึกสังกัดพนักงานแล้ว');
    }

    public function removeAssignment(EmployeeOrgAssignment $assignment): RedirectResponse
    {
        $assignment->delete();
        return back()->with('success', 'นำพนักงานออกจากหน่วยงานแล้ว');
    }

    private function validated(Request $request, ?OrganizationalUnit $unit = null): array
    {
        return $request->validate([
            'code' => ['required','string','max:30',Rule::unique('organizational_units','code')->ignore($unit?->id)],
            'name' => ['required','string','max:150'],
            'unit_type' => ['required',Rule::in(['company','management','division','department','team'])],
            'parent_id' => ['nullable','integer','exists:organizational_units,id',Rule::notIn(array_filter([$unit?->id]))],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'manager_employee_id' => ['nullable','integer','exists:employees,id'],
            'description' => ['nullable','string','max:1000'],
            'sort_order' => ['nullable','integer','min:0','max:9999'],
            'is_active' => ['nullable','boolean'],
        ]) + ['is_active'=>$request->boolean('is_active')];
    }

    private function descendantIds(OrganizationalUnit $unit): array
    {
        $ids = [$unit->id];
        $pending = [$unit->id];
        while ($pending) {
            $children = OrganizationalUnit::whereIn('parent_id', $pending)->pluck('id')->map(fn ($id)=>(int)$id)->all();
            $ids = [...$ids, ...$children];
            $pending = $children;
        }
        return array_values(array_unique($ids));
    }

    private function flatten($units, ?int $parentId = null, int $level = 0): array
    {
        $rows = [];
        foreach ($units->filter(fn ($unit) => $unit->parent_id === $parentId)->sortBy([['sort_order','asc'],['name','asc']]) as $unit) {
            $rows[] = ['unit'=>$unit,'level'=>$level];
            $rows = [...$rows, ...$this->flatten($units, $unit->id, $level + 1)];
        }
        return $rows;
    }
}
