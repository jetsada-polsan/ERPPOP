<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\SalesArea;
use App\Models\Salesman;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SalesmanController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $salesmen = Salesman::with(['branch', 'user:id,username,name'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")
                ->orWhere('name', 'ilike', "%{$q}%")
            ))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        $branches = Branch::orderBy('code')->get();
        $salesAreas = SalesArea::with(['branch', 'documentBook', 'users:id,username,name,sales_area_id'])
            ->where('area_type', 'route')
            ->orderBy('code')
            ->get();

        return view('salesmen.index', compact('salesmen', 'salesAreas', 'branches', 'q'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateSalesman($request);

        Salesman::create($data);

        return redirect()->route('salesmen.index')->with('success', "เพิ่มรหัสขาย {$data['code']} แล้ว");
    }

    public function update(Request $request, Salesman $salesman): RedirectResponse
    {
        $data = $this->validateSalesman($request, $salesman->id);

        $salesman->update($data);

        return redirect()->route('salesmen.index')->with('success', 'บันทึกข้อมูลรหัสขายแล้ว');
    }

    private function validateSalesman(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:salesmen,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
