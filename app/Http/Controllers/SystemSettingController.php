<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PosDevice;
use App\Models\PosTerminal;
use App\Models\Salesman;
use App\Models\User;
use App\Models\UserPosCredential;
use App\Services\Accounting\OpeningBalanceService;
use App\Support\PosLayout;
use App\Support\PosReleaseManifest;
use App\Support\PosTerminalCode;
use App\Support\PythonPosInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SystemSettingController extends Controller
{
    public function posDesigner(): View
    {
        return view('settings.pos-designer', [
            'layout' => PosLayout::draft(),
            'defaultRuntime' => PosLayout::defaultRuntime(),
            'publishedRuntime' => PosLayout::published()['runtime'],
            'publishedVersion' => PosLayout::publishedVersion(),
            'publishedAt' => PosLayout::publishedAt(),
            'limits' => [
                'pane_min' => PosLayout::MIN_PANE_WIDTH,
                'pane_max' => PosLayout::MAX_PANE_WIDTH,
                'rows_min' => PosLayout::MIN_PRODUCT_ROWS,
                'rows_max' => PosLayout::MAX_PRODUCT_ROWS,
                'columns_min' => PosLayout::MIN_PRODUCT_COLUMNS,
                'columns_max' => PosLayout::MAX_PRODUCT_COLUMNS,
                'densities' => PosLayout::DENSITIES,
                'button_sizes' => PosLayout::BUTTON_SIZES,
            ],
            // ตารางเดียวกับที่ฝั่งเซิร์ฟเวอร์ใช้แปลงเป็น CSS เพื่อให้ preview ไม่เพี้ยนจากของจริง
            'metrics' => [
                'density' => PosLayout::densityMetrics(),
                'button' => PosLayout::buttonMetrics(),
            ],
        ]);
    }

    public function savePosLayout(Request $request): RedirectResponse
    {
        // ปุ่ม "คืนค่าเริ่มต้น" ต้องไม่ต้องผ่าน validation ของฟอร์มที่ผู้ใช้เพิ่งทำพัง
        if ($request->boolean('reset')) {
            AppSetting::set('pos_layout_draft', $this->encodeLayout(PosLayout::defaults()));

            return redirect()->route('settings.pos-designer')
                ->with('success', 'คืนค่าแบบร่างกลับเป็นค่าเริ่มต้นแล้ว ยังไม่ได้ Publish ให้เครื่อง POS');
        }

        $rawLayout = $request->input('layout');
        if (is_string($rawLayout)) {
            $rawLayout = json_decode($rawLayout, true);
        }

        $validator = Validator::make($request->all(), PosLayout::runtimeRules(), PosLayout::runtimeMessages());
        $validator->after(function ($validator) use ($request, $rawLayout) {
            // สองฝั่งต้องเต็มหน้าจอพอดี ไม่งั้นหน้าขายจะเหลือช่องว่างหรือดันคอลัมน์ล้น
            $product = (int) $request->input('runtime.product_width');
            $cart = (int) $request->input('runtime.cart_width');
            if ($product > 0 && $cart > 0 && $product + $cart !== 100) {
                $validator->errors()->add('runtime.cart_width', 'ความกว้างฝั่งสินค้ากับฝั่งบิลรวมกันต้องได้ 100% (ตอนนี้ได้ '.($product + $cart).'%)');
            }

            if (! is_array($rawLayout)) {
                $validator->errors()->add('layout', 'รูปแบบ POS layout ไม่ถูกต้อง หรือ JSON ไม่สมบูรณ์');
            } elseif (PosLayout::componentsFrom($rawLayout['components'] ?? null) === []) {
                $validator->errors()->add('layout', 'ต้องมีอย่างน้อย 1 ส่วนประกอบบน canvas');
            }
        });
        $validator->validate();

        $layout = PosLayout::normalize([
            'version' => $rawLayout['version'] ?? 1,
            'components' => $rawLayout['components'] ?? null,
            'runtime' => $request->input('runtime'),
        ]);
        AppSetting::set('pos_layout_draft', $this->encodeLayout($layout));

        if ($request->boolean('publish')) {
            $version = PosLayout::publishedVersion() + 1;
            $layout['version'] = $version;
            $layout['layout_version'] = $version;
            AppSetting::set('pos_layout_published', $this->encodeLayout($layout));
            AppSetting::set('pos_layout_version', (string) $version);
            AppSetting::set('pos_layout_published_at', now()->toIso8601String());

            return redirect()->route('settings.pos-designer')->with('success', "Build และเผยแพร่ POS layout รุ่น {$version} แล้ว เครื่อง POS จะรับค่าเมื่อ Sync");
        }

        return redirect()->route('settings.pos-designer')->with('success', 'บันทึกแบบร่าง POS layout แล้ว');
    }

    private function encodeLayout(array $layout): string
    {
        return json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function updateLayout(Request $request): RedirectResponse
    {
        $data = $request->validate(['erp_layout' => ['required', 'in:classic,odoo']]);
        AppSetting::set('erp_layout', $data['erp_layout']);

        return redirect()->route('settings.index')->with('success', 'บันทึกรูปแบบหน้าหลังบ้านแล้ว');
    }

    /**
     * นโยบายสต๊อกติดลบรายสาขา: บางสาขายังต้องขายต่อได้แม้ยอดคงเหลือยังไม่ตรง
     * (เช่น เพิ่งเปิดและยังไม่ได้ลงยอดยกมา) จึงแยกตั้งทีละสาขา ไม่ใช่ทั้งระบบ.
     */
    public function stockPolicies(): View
    {
        return view('settings.stock-policies', [
            'branches' => Branch::with('defaultWarehouseLocation')->orderBy('code')->get(),
            'negativeCounts' => $this->negativeStockCountsByBranch(),
        ]);
    }

    public function updateStockPolicies(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*' => ['required', 'in:block,allow'],
        ]);

        $branches = Branch::whereIn('id', array_keys($data['policies']))->get();
        foreach ($branches as $branch) {
            $branch->update(['negative_stock_policy' => $data['policies'][$branch->id]]);
        }

        return redirect()->route('settings.stock-policies')
            ->with('success', "บันทึกนโยบายสต๊อกติดลบ {$branches->count()} สาขาแล้ว");
    }

    /**
     * จำนวนรายการสต๊อกติดลบของแต่ละสาขา - ใช้เตือนตอนเลือกนโยบาย.
     *
     * @return array<int, int> branch_id => จำนวนรายการติดลบ
     */
    private function negativeStockCountsByBranch(): array
    {
        // ใช้กติกาเจ้าของคลังเดียวกับการยกยอด (warehouses.branch_id บนฐานจริงเป็น NULL)
        return app(OpeningBalanceService::class)->negativeStockRows()
            ->whereNotNull('branch_id')
            ->countBy('branch_id')
            ->all();
    }

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
            // รายชื่อที่เลือกผูกกับเครื่องต้องเป็น User ใหม่ที่มีโปรไฟล์ POS แล้ว
            // ไม่แสดง salesman เก่าที่ไม่มีตัวตน User ให้แอดมินเลือก
            'posUsers' => User::query()
                ->where('is_active', true)
                ->whereHas('posCashierProfile', fn ($cashier) => $cashier->where('is_active', true))
                ->with('branch:id,code,name_th')
                ->orderBy('name')
                ->get(['id', 'name', 'username', 'branch_id']),
            'posBranches' => Branch::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']),
            'posDevices' => PosDevice::with(['user:id,name,username', 'branch:id,code,name_th'])->latest()->limit(20)->get(),
            'posTerminals' => PosTerminal::with('branch:id,code,name_th')->orderBy('code')->get(),
            'menuOrder' => $this->menuOrder(),
            'erpTheme' => AppSetting::get('erp_theme', 'ocean'),
            'erpLayout' => AppSetting::get('erp_layout', 'classic'),
            'posRelease' => $this->currentPosRelease(),
            'pythonPosInstaller' => $this->currentPythonPosInstaller(),
            'posReadiness' => $this->posReadiness(),
            'posPinMode' => AppSetting::get('pos_passwordless_login') === '1' ? 'selection' : 'pin',
        ]);
    }

    /**
     * ออก PIN ชั่วคราวให้รหัสคนขาย POS จากหลังบ้าน (แคชเชียร์รายเครื่องไม่ผูก user จึงใช้ปุ่มในหน้าผู้ใช้ไม่ได้)
     * PIN สุ่ม ไม่ซ้ำกับคนขายอื่น แสดงครั้งเดียว เครื่องบังคับเปลี่ยนตอนล็อกอินครั้งแรก
     * ถอนการยืนยันคนขายนี้ออกจากทุกเครื่องทันที เหมือนรีเซ็ตจากหน้าผู้ใช้
     */
    public function resetCashierPin(Salesman $salesman): RedirectResponse
    {
        abort_unless($salesman->is_active, 422, 'รหัสคนขายนี้ปิดใช้งานอยู่');
        $temporary = $this->uniqueTemporaryPin($salesman);

        DB::transaction(function () use ($salesman, $temporary) {
            $salesman->setPin($temporary, true);
            // คนขายที่ผูก user (เช่นหัวหน้า) ระบบอ่าน PIN จาก user ก่อน ต้องตั้งที่นั่นด้วย
            if ($salesman->user) {
                UserPosCredential::firstOrCreate(['user_id' => $salesman->user_id])->setPin($temporary, true);
            }
            PosDevice::where('active_cashier_id', $salesman->id)->update([
                'active_cashier_id' => null, 'active_cashier_user_id' => null, 'cashier_verified_at' => null,
            ]);
            AuditLog::create([
                'user_id' => auth()->id(), 'branch_id' => $salesman->branch_id, 'action' => 'cashier_pin_reset',
                'table_name' => 'salesmen', 'record_id' => $salesman->id,
                'new_values' => ['code' => $salesman->code, 'must_change_pin' => true],
            ]);
        });

        return redirect()->route('settings.index', ['tab' => 'pos-download'])
            ->with('cashier_pin_result', ['code' => $salesman->code, 'name' => $salesman->name, 'pin' => $temporary]);
    }

    private function uniqueTemporaryPin(Salesman $salesman): string
    {
        $hashes = Salesman::whereKeyNot($salesman->id)->whereNotNull('pos_pin_hash')->pluck('pos_pin_hash')
            ->merge(UserPosCredential::whereNotNull('pin_hash')->whereNull('revoked_at')->pluck('pin_hash'));
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $pin = (string) random_int(100000, 999999);
            if (! $hashes->contains(fn ($hash) => Hash::check($pin, $hash))) {
                return $pin;
            }
        }
        abort(500, 'สร้าง PIN ที่ไม่ซ้ำไม่ได้ กรุณาลองใหม่');
    }

    /**
     * ความพร้อมขายของแต่ละสาขา สำหรับแถบตรวจในหน้าดาวน์โหลด POS
     * นับเครื่องที่ยังไม่เพิกถอน, เครื่องที่เคยต่อเข้ามา และคนขายที่ตั้ง PIN แล้ว (PIN ของ user หรือของคนขายเอง)
     */
    private function posReadiness(): array
    {
        return Branch::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th', 'default_warehouse_location_id'])
            ->map(function (Branch $branch) {
                $devices = PosDevice::where('branch_id', $branch->id)->whereNull('revoked_at')->get(['id', 'name', 'last_seen_at']);
                $cashiers = Salesman::with('user.posCredential')->where('branch_id', $branch->id)->where('is_active', true)->get();
                $withPin = $cashiers->filter(fn (Salesman $s) => filled($s->user?->posCredential?->pin_hash) || filled($s->pos_pin_hash));

                // ผู้ใช้ที่ผูกกับเครื่องให้อัตโนมัติ: user ของสาขาที่ผูกคนขายไว้ (หัวหน้า เช่น amnat) และมีสิทธิ์ขาย
                $deviceUser = User::where('branch_id', $branch->id)->where('is_active', true)
                    ->whereHas('posCashierProfile', fn ($q) => $q->where('is_active', true))
                    ->with(['roles.permissions', 'branchRoles.permissions'])->orderBy('id')->get()
                    ->first(fn (User $user) => $user->canUsePosDevice($branch->id));

                return [
                    'branch' => $branch,
                    'device_user' => $deviceUser,
                    'cashier_list' => $cashiers,
                    'devices' => $devices->count(),
                    'online_devices' => $devices->whereNotNull('last_seen_at')->count(),
                    'last_seen' => $devices->max('last_seen_at'),
                    'cashiers' => $cashiers->count(),
                    'cashiers_with_pin' => $withPin->count(),
                    'has_warehouse' => $branch->hasDefaultWarehouse(),
                ];
            })->all();
    }

    /** Download the tested PopCentral POS installer without requiring GitHub access on a cashier PC. */
    public function downloadPythonPos()
    {
        $installer = $this->currentPythonPosInstaller();
        abort_unless($installer, 404, 'ยังไม่มีไฟล์ติดตั้ง PopCentral POS รุ่นทดสอบ');

        return response()->download($installer['path'], $installer['filename'], [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    public function publishPosRelease(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pos_version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'],
            'pos_installer' => ['required', 'file', 'max:204800'],
            'pos_signature' => ['required', 'string', 'max:2000'],
            'pos_release_notes' => ['nullable', 'string', 'max:2000'],
            'pos_mandatory' => ['nullable', 'boolean'],
        ], [
            'pos_version.regex' => 'เวอร์ชันต้องอยู่ในรูป 1.2.3',
            'pos_installer.max' => 'ไฟล์ติดตั้งต้องไม่เกิน 200 MB',
        ]);

        $extension = strtolower($request->file('pos_installer')->getClientOriginalExtension());
        abort_unless(in_array($extension, ['exe', 'msi', 'zip'], true), 422, 'รองรับไฟล์ exe, msi หรือ zip เท่านั้น');

        $directory = storage_path('app/pos-releases');
        File::ensureDirectoryExists($directory);
        $filename = 'POPSTAR-POS-'.$data['pos_version'].'.'.$extension;
        $request->file('pos_installer')->move($directory, $filename);

        $manifest = [
            'version' => $data['pos_version'],
            'notes' => trim((string) ($data['pos_release_notes'] ?? '')),
            'pub_date' => now()->toIso8601String(),
            'platforms' => [
                'windows-x86_64' => [
                    'signature' => trim($data['pos_signature']),
                    'url' => url('/download/pos/releases/'.$filename),
                ],
            ],
            'mandatory' => $request->boolean('pos_mandatory'),
            'sha256' => hash_file('sha256', $directory.'/'.$filename),
        ];

        $temporary = $directory.'/latest-'.Str::random(8).'.json';
        File::put($temporary, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::move($temporary, $directory.'/latest.json');

        return redirect()->route('settings.index')->with('success', 'เผยแพร่ PopCentral POS รุ่น '.$data['pos_version'].' แล้ว เครื่องสาขาจะตรวจพบอัตโนมัติ');
    }

    private function currentPosRelease(): ?array
    {
        return app(PosReleaseManifest::class)->current();
    }

    private function currentPythonPosInstaller(): ?array
    {
        return PythonPosInstaller::current();
    }

    public function issuePosToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pos_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'pos_user_id' => ['required', 'integer', 'exists:users,id'],
            'pos_device_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            [$device, $token] = DB::transaction(function () use ($data) {
                // Lock the branch while issuing its next terminal number. Two admins cannot receive the same code.
                $branch = Branch::query()->lockForUpdate()->findOrFail($data['pos_branch_id']);
                $user = User::query()
                    ->whereKey($data['pos_user_id'])
                    ->where('is_active', true)
                    ->with(['posCashierProfile', 'roles.permissions', 'branchRoles.permissions'])
                    ->first();
                $cashier = $user?->posCashierProfile;

                if (! $user || ! $cashier?->is_active || ! $user->canUsePosDevice($branch->id)) {
                    throw new \RuntimeException("ผู้ใช้ที่เลือกยังไม่มีสิทธิ์ขาย POS ที่สาขา {$branch->code} หรือไม่มีโปรไฟล์ POS ที่ใช้งานได้");
                }

                $terminalCode = PosTerminalCode::next($branch);
                $name = trim((string) ($data['pos_device_name'] ?? '')) ?: "PopCentral POS {$terminalCode}";

                return PosDevice::issue([
                    'name' => $name,
                    'user_id' => $user->id,
                    'branch_id' => $branch->id,
                    'terminal_code' => $terminalCode,
                ]);
            });
        } catch (\RuntimeException $exception) {
            // ขาดแคชเชียร์ที่มีสิทธิ์ pos.sell เป็นเงื่อนไขทางธุรกิจ ไม่ใช่ข้อผิดพลาดของระบบ เด้ง back() แทน abort() กันตกหน้า Whoops
            return back()->withErrors(['pos_branch_id' => $exception->getMessage()]);
        }

        return redirect()->route('settings.index', ['tab' => 'pos-download'])->with([
            'success' => "สร้าง {$device->name} ({$device->terminal_code}) และ Token ให้แล้ว กรุณาคัดลอกไปตั้งค่าในเครื่อง POS",
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

        return redirect()->route('settings.index', ['tab' => 'pos-download'])->with([
            'success' => "ออก Token ใหม่สำหรับ {$device->name} แล้ว Token เดิมจะใช้งานไม่ได้",
            'pos_token' => $token,
            'pos_device_name' => $device->name,
        ]);
    }

    public function deletePosDevice(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pos_device_id' => ['required', 'integer', 'exists:pos_devices,id'],
        ]);

        $device = PosDevice::findOrFail($data['pos_device_id']);
        $name = $device->name;
        $device->delete();

        return redirect()->route('settings.index', ['tab' => 'pos-download'])->with('success', "ลบเครื่อง POS {$name} แล้ว");
    }

    public function updatePosTerminalHardware(Request $request): RedirectResponse
    {
        $terminalId = $request->integer('pos_terminal_id');
        $data = $request->validate([
            'pos_terminal_id' => ['required', 'integer', 'exists:pos_terminals,id'],
            "hardware.{$terminalId}.printer_driver" => ['required', 'in:browser,windows,escpos_usb,escpos_network'],
            "hardware.{$terminalId}.printer_name" => ['nullable', 'string', 'max:120'],
            "hardware.{$terminalId}.printer_address" => ['nullable', 'string', 'max:200'],
            "hardware.{$terminalId}.paper_width" => ['required', 'in:58mm,80mm'],
            "hardware.{$terminalId}.scanner_mode" => ['required', 'in:keyboard,serial'],
            "hardware.{$terminalId}.scale_mode" => ['required', 'in:none,keyboard,serial'],
            "hardware.{$terminalId}.customer_display" => ['required', 'in:none,browser,serial,network'],
            "hardware.{$terminalId}.print_copies" => ['required', 'integer', 'min:1', 'max:3'],
        ]);
        $input = data_get($data, "hardware.{$terminalId}");

        PosTerminal::findOrFail($terminalId)->update([
            'hardware_profile' => [
                ...$input,
                'cash_drawer_enabled' => $request->boolean("hardware.{$terminalId}.cash_drawer_enabled"),
                'auto_print' => $request->boolean("hardware.{$terminalId}.auto_print"),
                'updated_at' => now()->toIso8601String(),
            ],
        ]);

        return redirect()->route('settings.index')->with('success', 'บันทึกโปรไฟล์อุปกรณ์ POS แล้ว');
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
            'erp_theme' => ['required', 'in:ocean,navy,emerald,slate,clear'],
            // Optional for cached/older settings forms; preserve the current choice if omitted.
            'erp_layout' => ['nullable', 'in:classic,odoo'],
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
        AppSetting::set('erp_layout', $data['erp_layout'] ?? AppSetting::get('erp_layout', 'classic'));
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
        return ['ภาพรวม', 'งานประจำวัน', 'POS / หน้าร้าน', 'คลัง / ผลิต / ซื้อ', 'การเงิน / บัญชี', 'ข้อมูลตั้งต้น', 'เชื่อมต่อ', 'รายงาน', 'ระบบ'];
    }

    private function menuOrder(): array
    {
        $saved = json_decode((string) AppSetting::get('menu_section_order', '[]'), true);
        $allowed = $this->defaultMenuOrder();
        $saved = is_array($saved) ? array_values(array_intersect($saved, $allowed)) : [];

        return array_values(array_unique(array_merge($saved, $allowed)));
    }
}
