<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Support\PosReceiptTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

class ReceiptTemplateController extends Controller
{
    public function edit(): View
    {
        return view('settings.receipt-template', [
            'receiptTemplate' => PosReceiptTemplate::get(),
            'defaultTemplate' => PosReceiptTemplate::defaults(),
            'company' => [
                'name' => AppSetting::company('name_th'),
                'tax_id' => AppSetting::company('tax_id'),
                'address' => AppSetting::company('address'),
                'phone' => AppSetting::company('phone'),
                'logo_url' => AppSetting::logoUrl(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['template' => ['required', 'string', 'max:20000']]);

        try {
            $decoded = json_decode($data['template'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::warning('Invalid POS receipt template JSON', ['message' => $exception->getMessage()]);
            throw ValidationException::withMessages(['template' => 'รูปแบบใบเสร็จไม่ถูกต้อง กรุณาลองจัดใหม่']);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['template' => 'รูปแบบใบเสร็จไม่ถูกต้อง']);
        }

        $template = PosReceiptTemplate::sanitize($decoded);
        AppSetting::set(PosReceiptTemplate::SETTING_KEY, json_encode($template, JSON_UNESCAPED_UNICODE));

        return redirect()->route('settings.receipt-template.edit')
            ->with('success', 'บันทึกแบบใบเสร็จ POS แล้ว เครื่องแคชเชียร์จะรับแบบใหม่เมื่อซิงก์ข้อมูล');
    }
}
