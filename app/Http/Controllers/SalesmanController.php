<?php

namespace App\Http\Controllers;

use App\Models\Salesman;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SalesmanController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('users.index')->with(
            'success_popup',
            'รวมการจัดการคนขายไว้ที่หน้า “ผู้ใช้และสิทธิ์” แล้ว ส่วนรหัสขาย/POS เดิมระบบเก็บไว้ใช้อ้างอิงภายใน'
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateSalesman($request);

        Salesman::create($data);

        return redirect()->route('users.index')->with('success', "เพิ่มรหัสขาย {$data['code']} แล้ว");
    }

    public function update(Request $request, Salesman $salesman): RedirectResponse
    {
        $data = $this->validateSalesman($request, $salesman->id);

        $salesman->update($data);

        return redirect()->route('users.index')->with('success', 'บันทึกข้อมูลรหัสขายแล้ว');
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
