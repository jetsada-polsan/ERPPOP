<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LineIntegration;
use App\Models\Member;
use App\Models\MemberLineAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class LineIntegrationController extends Controller
{
    /** ค่า LINE OA ที่ระบบใช้อยู่ (จาก .env) — secret = ต้องปิดบังจนกว่าจะยืนยันรหัสผ่าน */
    private const CREDENTIALS = [
        'channel_id' => ['label' => 'Channel ID (Messaging API)', 'env' => 'LINE_CHANNEL_ID', 'secret' => false],
        'channel_secret' => ['label' => 'Channel secret', 'env' => 'LINE_CHANNEL_SECRET', 'secret' => true],
        'channel_access_token' => ['label' => 'Channel access token', 'env' => 'LINE_CHANNEL_ACCESS_TOKEN', 'secret' => true],
        'liff_id' => ['label' => 'LIFF ID', 'env' => 'LINE_LIFF_ID', 'secret' => false],
        'login_channel_id' => ['label' => 'LINE Login Channel ID', 'env' => 'LINE_LOGIN_CHANNEL_ID', 'secret' => false],
    ];

    public function index(): View
    {
        return $this->page();
    }

    /** แสดงค่าเต็มในคำตอบนี้ครั้งเดียว ไม่เก็บลง session ไม่ให้ browser cache */
    public function revealCredentials(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission('system.admin'), 403, 'เฉพาะผู้ดูแลระบบ');
        $request->validate(['password' => ['required', 'string']], ['password.required' => 'กรุณาใส่รหัสผ่าน']);

        $ok = Hash::check((string) $request->input('password'), (string) $user->password);
        AuditLog::create([
            'user_id' => $user->id,
            'branch_id' => $user->branch_id,
            'action' => $ok ? 'line_credentials_reveal' : 'line_credentials_reveal_failed',
            'table_name' => 'env',
            'record_id' => 0,
            'new_values' => ['keys' => array_keys(self::CREDENTIALS), 'ip' => $request->ip()],
        ]);
        if (! $ok) {
            return redirect()->route('line-integrations.index')->withErrors(['password' => 'รหัสผ่านไม่ถูกต้อง']);
        }

        return response($this->page(true)->render())
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    private function page(bool $reveal = false): View
    {
        $integrations = LineIntegration::orderBy('code')->paginate(50);
        $credentials = [];
        foreach (self::CREDENTIALS as $key => $meta) {
            $value = (string) config("services.line.{$key}");
            $credentials[] = $meta + [
                'key' => $key,
                'is_set' => $value !== '',
                'shown' => $value === '' ? 'ยังไม่ตั้งค่า' : (($reveal || ! $meta['secret']) ? $value : self::mask($value)),
                'full' => $reveal && $value !== '' ? $value : null,
            ];
        }

        return view('line-integrations.index', [
            'credentials' => $credentials,
            'revealed' => $reveal,
            'integrations' => $integrations,
            'lineChannelId' => config('services.line.channel_id'),
            'webhookUrl' => route('api.line.webhook'),
            'activeMembers' => Member::where('is_active', true)->count(),
            'linkedMembers' => MemberLineAccount::where('is_active', true)->distinct('member_id')->count('member_id'),
            'linkedAccounts' => MemberLineAccount::with(['member.customer'])->where('is_active', true)->latest('linked_at')->get(),
        ]);
    }

    /** เห็นแค่ 4 ตัวท้าย พอให้รู้ว่าเป็น token ตัวไหน */
    private static function mask(string $value): string
    {
        return mb_strlen($value) <= 8 ? str_repeat('•', 8) : str_repeat('•', 12).mb_substr($value, -4).' ('.mb_strlen($value).' ตัวอักษร)';
    }

    public function unlinkMember(MemberLineAccount $memberLineAccount): RedirectResponse
    {
        $memberLineAccount->forceFill(['is_active' => false, 'unlinked_at' => now()])->save();

        return redirect()->route('line-integrations.index')->with('success', 'ปลดผูกบัญชี LINE ของสมาชิกแล้ว');
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
