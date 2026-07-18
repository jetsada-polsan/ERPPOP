<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\PosDevice;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class SystemSettingController extends Controller
{
    public function index(): View
    {
        // Preset logos = every image dropped in public/images/logos
        $presetDir = public_path('images/logos');
        $presets = File::isDirectory($presetDir)
            ? collect(File::files($presetDir))
                ->filter(fn ($f) => in_array(strtolower($f->getExtension()), ['png', 'jpg', 'jpeg', 'webp', 'svg']))
                ->map(fn ($f) => 'images/logos/'.$f->getFilename())
                ->values()
            : collect();

        // อัตรา VAT ที่มีผลวันนี้ (ไม่มีแถว = 7)
        $vatRate = DB::table('vat_rates')
            ->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')
            ->value('rate_percent') ?? 7.0;

        return view('settings.index', [
            'presets' => $presets,
            'currentLogo' => AppSetting::get('logo_path'),
            'company' => [
                'name_th' => AppSetting::company('name_th'),
                'name_en' => AppSetting::company('name_en'),
                'tax_id' => AppSetting::company('tax_id'),
                'address' => AppSetting::company('address'),
                'phone' => AppSetting::company('phone'),
            ],
            'doc' => [
                'vat_rate' => (float) $vatRate,
                'price_includes_vat' => AppSetting::get('doc_price_includes_vat', '1') === '1',
                'credit_days' => (int) (AppSetting::get('default_credit_days') ?: 30),
                'footer_note' => AppSetting::get('doc_footer_note', ''),
            ],
            'bookCount' => DB::table('document_books')->where('is_active', true)->count(),
            'bankCount' => DB::table('bank_accounts')->count(),
            'posUsers' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'username', 'branch_id']),
            'posDevices' => PosDevice::with(['user:id,name,username', 'branch:id,code,name_th'])->latest()->limit(20)->get(),
            'menuOrder' => $this->menuOrder(),
            'erpTheme' => AppSetting::get('erp_theme', 'ocean'),
        ]);
    }

    public function issuePosToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pos_user_id' => ['required', 'integer', 'exists:users,id'],
            'pos_device_name' => ['required', 'string', 'max:100'],
            'pos_terminal_code' => ['nullable', 'string', 'max:40'],
        ]);

        $user = User::findOrFail($data['pos_user_id']);
        [$device, $token] = PosDevice::issue([
            'name' => $data['pos_device_name'],
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
            'terminal_code' => $data['pos_terminal_code'] ?? null,
        ]);

        return redirect()->route('settings.index')->with([
            'success' => "สร้าง Token สำหรับ {$device->name} แล้ว กรุณาคัดลอกเก็บไว้ทันที",
            'pos_token' => $token,
            'pos_device_name' => $device->name,
        ]);
    }

    public function rotatePosToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pos_device_id' => ['required', 'integer', 'exists:pos_devices,id'],
        ]);

        $device = PosDevice::findOrFail($data['pos_device_id']);
        $token = $device->rotateToken();

        return redirect()->route('settings.index')->with([
            'success' => "ออก Token ใหม่สำหรับ {$device->name} แล้ว Token เดิมจะใช้งานไม่ได้",
            'pos_token' => $token,
            'pos_device_name' => $device->name,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'logo_choice' => ['nullable', 'string', 'max:255'],
            'logo_file' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:4096'],
            'company_name_th' => ['required', 'string', 'max:200'],
            'company_name_en' => ['nullable', 'string', 'max:200'],
            'company_tax_id' => ['nullable', 'string', 'max:30'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'company_phone' => ['nullable', 'string', 'max:60'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:30'],
            'price_includes_vat' => ['required', 'in:0,1'],
            'credit_days' => ['required', 'integer', 'min:0', 'max:365'],
            'footer_note' => ['nullable', 'string', 'max:500'],
            'menu_order' => ['nullable', 'string', 'max:2000'],
            'erp_theme' => ['required', 'in:ocean,navy,emerald,slate'],
        ], [
            'company_name_th.required' => 'กรุณาระบุชื่อบริษัท',
            'logo_file.image' => 'ไฟล์โลโก้ต้องเป็นรูปภาพ (png/jpg/webp/svg)',
            'logo_file.max' => 'ไฟล์โลโก้ต้องไม่เกิน 4MB',
            'vat_rate.required' => 'กรุณาระบุอัตราภาษีมูลค่าเพิ่ม',
            'credit_days.required' => 'กรุณาระบุจำนวนวันเครดิต',
        ]);

        // Uploaded file wins over a preset pick
        if ($request->hasFile('logo_file')) {
            $file = $request->file('logo_file');
            $name = 'custom-'.now()->format('YmdHis').'.'.strtolower($file->getClientOriginalExtension());
            $file->move(public_path('images/logos'), $name);
            AppSetting::set('logo_path', 'images/logos/'.$name);
        } elseif ($request->filled('logo_choice')) {
            if ($data['logo_choice'] === '__none__') {
                AppSetting::set('logo_path', null);
            } elseif (str_starts_with($data['logo_choice'], 'images/')
                && File::exists(public_path($data['logo_choice']))) {
                AppSetting::set('logo_path', $data['logo_choice']);
            }
        }

        AppSetting::set('company_name_th', $data['company_name_th']);
        AppSetting::set('company_name_en', $data['company_name_en'] ?? '');
        AppSetting::set('company_tax_id', $data['company_tax_id'] ?? '');
        AppSetting::set('company_address', $data['company_address'] ?? '');
        AppSetting::set('company_phone', $data['company_phone'] ?? '');

        // ตั้งค่าเอกสาร
        AppSetting::set('doc_price_includes_vat', $data['price_includes_vat']);
        AppSetting::set('default_credit_days', (string) $data['credit_days']);
        AppSetting::set('doc_footer_note', $data['footer_note'] ?? '');
        AppSetting::set('erp_theme', $data['erp_theme']);
        if ($request->filled('menu_order')) {
            $requestedOrder = json_decode($data['menu_order'], true);
            $allowed = $this->defaultMenuOrder();
            if (is_array($requestedOrder)) {
                $clean = array_values(array_intersect($requestedOrder, $allowed));
                AppSetting::set('menu_section_order', json_encode(array_values(array_unique(array_merge($clean, $allowed))), JSON_UNESCAPED_UNICODE));
            }
        }

        // อัตรา VAT เปลี่ยน: ปิดแถวเดิม (มีผลถึงเมื่อวาน) + เปิดแถวใหม่มีผลวันนี้
        // GlPostingService/รายงานภาษี อ่านจากตารางนี้อยู่แล้ว
        $currentRate = DB::table('vat_rates')
            ->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->orderByDesc('effective_from')
            ->first();

        if (! $currentRate || abs((float) $currentRate->rate_percent - (float) $data['vat_rate']) > 0.001) {
            if ($currentRate && $currentRate->effective_from === now()->toDateString()) {
                // เปลี่ยนซ้ำภายในวันเดียว: แก้แถวเดิมแทน
                DB::table('vat_rates')->where('id', $currentRate->id)->update(['rate_percent' => $data['vat_rate']]);
            } else {
                if ($currentRate) {
                    DB::table('vat_rates')->where('id', $currentRate->id)
                        ->update(['effective_to' => now()->subDay()->toDateString()]);
                }
                DB::table('vat_rates')->insert([
                    'rate_percent' => $data['vat_rate'],
                    'effective_from' => now()->toDateString(),
                    'effective_to' => null,
                ]);
            }
        }

        return redirect()->route('settings.index')->with('success', 'บันทึกการตั้งค่าเรียบร้อย');
    }

    private function defaultMenuOrder(): array
    {
        return ['ภาพรวม', 'งานประจำวัน', 'คลัง / ผลิต / ซื้อ', 'การเงิน / บัญชี', 'ข้อมูลตั้งต้น', 'เชื่อมต่อ', 'รายงาน', 'ระบบ'];
    }

    private function menuOrder(): array
    {
        $saved = json_decode((string) AppSetting::get('menu_section_order', '[]'), true);
        $allowed = $this->defaultMenuOrder();
        $saved = is_array($saved) ? array_values(array_intersect($saved, $allowed)) : [];

        return array_values(array_unique(array_merge($saved, $allowed)));
    }
}
