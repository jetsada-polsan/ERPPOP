<?php

namespace App\Http\Controllers;

use App\Models\SalesArea;
use App\Models\Salesman;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalesAreaController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateArea($request);
        SalesArea::create($data);

        return redirect()->route('salesmen.index')->with('success', "เพิ่มสายการขาย {$data['code']} แล้ว");
    }

    public function update(Request $request, SalesArea $salesArea): RedirectResponse
    {
        $salesArea->update($this->validateArea($request, $salesArea->id));

        return redirect()->route('salesmen.index')->with('success', 'บันทึกสายการขายแล้ว');
    }

    private function validateArea(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:sales_areas,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'area_type' => ['required', 'in:branch,route'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'default_salesman_id' => ['nullable', 'integer', 'exists:salesmen,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['branch_id'] = $data['branch_id'] ?? null;
        $data['default_salesman_id'] = $data['default_salesman_id'] ?? null;
        $data['is_active'] = $request->boolean('is_active', true);

        if ($data['branch_id'] && $data['default_salesman_id']) {
            $salesman = Salesman::findOrFail($data['default_salesman_id']);
            if ($salesman->branch_id && (int) $salesman->branch_id !== (int) $data['branch_id']) {
                throw ValidationException::withMessages([
                    'default_salesman_id' => 'พนักงานขายหลักต้องอยู่สาขาเดียวกับสายการขาย',
                ]);
            }
        }

        return $data;
    }
}
