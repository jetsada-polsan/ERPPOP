<?php

namespace App\Http\Controllers;

use App\Models\LineIntegration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LineIntegrationController extends Controller
{
    public function index(): View
    {
        $integrations = LineIntegration::orderBy('code')->paginate(50);

        return view('line-integrations.index', compact('integrations'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateIntegration($request);
        LineIntegration::create($data);

        return redirect()->route('line-integrations.index')->with('success', "เพิ่มช่องทาง {$data['code']} แล้ว");
    }

    public function update(Request $request, LineIntegration $lineIntegration): RedirectResponse
    {
        $data = $this->validateIntegration($request, $lineIntegration->id);
        $lineIntegration->update($data);

        return redirect()->route('line-integrations.index')->with('success', 'บันทึกช่องทาง LINE แล้ว');
    }

    private function validateIntegration(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:line_integrations,code,'.($ignoreId ?? 'NULL').',id'],
            'name' => ['required', 'string', 'max:150'],
            'channel_type' => ['required', 'string', 'max:40'],
            'target_name' => ['nullable', 'string', 'max:150'],
            'target_id' => ['nullable', 'string', 'max:150'],
            'token' => ['nullable', 'string', 'max:2000'],
            'notify_sales' => ['nullable', 'boolean'],
            'notify_qr_payment' => ['nullable', 'boolean'],
            'notify_void_bill' => ['nullable', 'boolean'],
            'notify_stock_alert' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        foreach (['notify_sales', 'notify_qr_payment', 'notify_void_bill', 'notify_stock_alert', 'is_active'] as $field) {
            $data[$field] = $request->boolean($field);
        }

        return $data;
    }
}
